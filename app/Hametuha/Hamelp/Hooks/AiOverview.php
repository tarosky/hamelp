<?php
/**
 * AI Overview hook handler.
 *
 * @package hamelp
 */

namespace Hametuha\Hamelp\Hooks;

use Hametuha\Hamelp\Pattern\Singleton;
use Hametuha\Hamelp\Services\ConversationStore;
use Hametuha\Hamelp\Services\FaqSearchService;

/**
 * Class AiOverview
 *
 * Handles AI Overview feature initialization, REST API, and block registration.
 */
class AiOverview extends Singleton {

	/**
	 * Initialize hooks.
	 */
	protected function init() {
		// wp-ai-client is bundled in WordPress 7.0+. If it's not available
		// (older WP), this feature is disabled silently.
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		// Register REST API endpoint
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			'hamelp/v1',
			'/ai-overview',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_request' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'query'           => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'history'         => [
						'required' => false,
						'type'     => 'array',
						'default'  => [],
						// Items are associative arrays (role/content); sanitized
						// and windowed in FaqSearchService::prepare_history().
					],
					'conversation_id' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Check permission including rate limits.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	public function check_permission( \WP_REST_Request $request ) {
		// 0. Feature disabled (e.g. to stop a request flood).
		if ( 'off' === hamelp_ai_overview_mode() ) {
			return new \WP_Error(
				'ai_overview_disabled',
				__( 'AI Overview is currently disabled.', 'hamelp' ),
				[ 'status' => 403 ]
			);
		}

		// 1. Login required check.
		$require_login = (bool) apply_filters( 'hamelp_ai_overview_require_login', get_option( 'hamelp_rate_require_login', '' ) );
		if ( $require_login && ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to use this feature.', 'hamelp' ),
				[ 'status' => 403 ]
			);
		}

		// 2. Per-IP rate limit.
		$ip_result = $this->check_ip_rate_limit();
		if ( is_wp_error( $ip_result ) ) {
			return $ip_result;
		}

		// 3. Global daily limit.
		$global_result = $this->check_global_rate_limit();
		if ( is_wp_error( $global_result ) ) {
			return $global_result;
		}

		return true;
	}

	/**
	 * Check per-IP rate limit.
	 *
	 * @return true|\WP_Error
	 */
	protected function check_ip_rate_limit() {
		$per_ip = (int) apply_filters( 'hamelp_rate_limit_per_ip', get_option( 'hamelp_rate_per_ip', 5 ) );
		$ip     = $this->get_client_ip();
		$key    = 'hamelp_rate_' . md5( $ip );
		$count  = (int) get_transient( $key );

		if ( $count >= $per_ip ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				__( 'Too many requests. Please try again later.', 'hamelp' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * Check global daily rate limit.
	 *
	 * @return true|\WP_Error
	 */
	protected function check_global_rate_limit() {
		$daily = (int) apply_filters( 'hamelp_rate_limit_daily', get_option( 'hamelp_rate_daily', 100 ) );
		$key   = 'hamelp_rate_global_' . gmdate( 'Y-m-d' );
		$count = (int) get_transient( $key );

		if ( $count >= $daily ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				__( 'Too many requests. Please try again later.', 'hamelp' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * Increment rate limit counters after successful AI call.
	 */
	protected function increment_rate_counters() {
		$window = (int) apply_filters( 'hamelp_rate_limit_window', get_option( 'hamelp_rate_window', 10 ) );

		// Per-IP counter.
		$ip      = $this->get_client_ip();
		$ip_key  = 'hamelp_rate_' . md5( $ip );
		$current = (int) get_transient( $ip_key );
		set_transient( $ip_key, $current + 1, $window * MINUTE_IN_SECONDS );

		// Global daily counter.
		$global_key     = 'hamelp_rate_global_' . gmdate( 'Y-m-d' );
		$global_current = (int) get_transient( $global_key );
		$seconds_left   = strtotime( 'tomorrow' ) - time();
		set_transient( $global_key, $global_current + 1, max( $seconds_left, 1 ) );
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	protected function get_client_ip(): string {
		$headers = [
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For may contain multiple IPs; take the first.
				$ip = strtok( $_SERVER[ $header ], ',' );
				$ip = trim( $ip );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	/**
	 * Handle AI overview REST request.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function handle_request( \WP_REST_Request $request ) {
		$query   = $request->get_param( 'query' );
		$history = (array) $request->get_param( 'history' );

		// In single-shot mode each question is independent: ignore prior turns.
		if ( 'single' === hamelp_ai_overview_mode() ) {
			$history = [];
		}

		// Check if AI is available
		$prompt = wp_ai_client_prompt( $query );
		if ( ! $prompt->is_supported_for_text_generation() ) {
			return new \WP_Error(
				'ai_unavailable',
				__( 'AI feature is not configured.', 'hamelp' ),
				[ 'status' => 503 ]
			);
		}

		$service = new FaqSearchService();
		$result  = $service->generate_overview( $query, $history );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Increment rate counters on successful AI call.
		$this->increment_rate_counters();

		// Persist the exchange when conversation saving is enabled (opt-in).
		// Done only after a successful answer so empty attempts are never stored.
		$store = new ConversationStore();
		if ( $store->is_enabled() ) {
			$conversation_id = (string) $request->get_param( 'conversation_id' );
			$saved           = $store->save_turn(
				'' !== $conversation_id ? $conversation_id : null,
				$query,
				(string) $result['answer'],
				$result['cited_ids'] ?? []
			);
			if ( ! empty( $saved['uuid'] ) ) {
				$result['conversation_id'] = $saved['uuid'];
			}
		}

		// cited_ids is internal; do not expose it in the API response.
		unset( $result['cited_ids'] );

		return rest_ensure_response( $result );
	}
}
