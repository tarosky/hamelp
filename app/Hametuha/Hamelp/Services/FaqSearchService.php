<?php
/**
 * FAQ Search Service for AI Overview.
 *
 * @package hamelp
 */

namespace Hametuha\Hamelp\Services;

use Hametuha\Hamelp\Hooks\Settings;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;

/**
 * Service class for FAQ catalog-based AI response generation.
 */
class FaqSearchService {


	/**
	 * Generate AI overview response based on user query.
	 *
	 * Uses a catalog of all FAQs as context, letting the LLM
	 * select relevant FAQs and generate a response in one call.
	 *
	 * The conversation is stateless: prior turns are supplied by the client
	 * via $history on every request and are not persisted server-side.
	 *
	 * @param string  $query   User's question (the new turn).
	 * @param array[] $history Prior conversation turns, each `['role' => 'user'|'assistant', 'content' => string]`.
	 * @return array|\WP_Error Response with answer and sources, or error.
	 */
	public function generate_overview( string $query, array $history = [] ) {
		$builder = new FaqCatalogBuilder();
		$catalog = $builder->get_accessible_catalog();

		// No FAQs available.
		if ( empty( $catalog ) ) {
			return [
				'answer'  => __( 'No FAQ content is available at this time.', 'hamelp' ),
				'sources' => [],
			];
		}

		/**
		 * Threshold for switching between full-dump and catalog mode.
		 *
		 * When FAQ count is at or below this number, full content is included.
		 * Above this number, only title + excerpt (300 chars) is used.
		 *
		 * @param int $threshold Default 30.
		 */
		$threshold = apply_filters( 'hamelp_full_dump_threshold', 30 );

		if ( count( $catalog ) <= $threshold ) {
			$context = $this->build_full_context( $catalog );
		} else {
			$context = $this->build_catalog_context( $catalog );
		}

		$system_prompt = $this->get_system_prompt( $context );

		// Build the message list: prior turns (windowed/sanitized) + the new question.
		// The whole FAQ catalog stays in the system instruction, so a long
		// conversation grows only by the history window, not the catalog.
		$messages = [];
		foreach ( $this->prepare_history( $history ) as $turn ) {
			$part       = new MessagePart( $turn['content'] );
			$messages[] = ( 'assistant' === $turn['role'] )
				? new ModelMessage( [ $part ] )
				: new UserMessage( [ $part ] );
		}
		$messages[] = new UserMessage( [ new MessagePart( $query ) ] );

		$response = wp_ai_client_prompt( $messages )
			->using_system_instruction( $system_prompt )
			->using_temperature( 0.3 )
			->as_json_response()
			->generate_text();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( $response, true );
		if ( ! $data || ! isset( $data['answer'] ) ) {
			// JSON parse failed: treat response as plain text.
			return [
				'answer'  => $response,
				'sources' => [],
			];
		}

		// Build sources from cited IDs.
		$cited   = $data['cited_ids'] ?? [];
		$sources = [];
		foreach ( $catalog as $item ) {
			if ( in_array( $item['id'], $cited, true ) ) {
				$sources[] = [
					'id'    => $item['id'],
					'title' => $item['title'],
					'url'   => get_permalink( $item['id'] ),
				];
			}
		}

		return [
			'answer'  => $data['answer'],
			'sources' => $sources,
		];
	}

	/**
	 * Normalize, sanitize and window a client-supplied conversation history.
	 *
	 * The history is sent by the client on every request (stateless server), so
	 * it must be treated as untrusted: only `user`/`assistant` roles are kept,
	 * content is sanitized, empty turns are dropped, and the list is trimmed to
	 * the most recent N entries to bound token cost.
	 *
	 * @param array $history Raw history from the request.
	 * @return array[] Normalized turns, each `['role' => 'user'|'assistant', 'content' => string]`.
	 */
	public function prepare_history( array $history ): array {
		$normalized = [];
		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) || ! isset( $turn['role'], $turn['content'] ) ) {
				continue;
			}
			$role = (string) $turn['role'];
			if ( ! in_array( $role, [ 'user', 'assistant' ], true ) ) {
				continue;
			}
			$content = sanitize_textarea_field( (string) $turn['content'] );
			if ( '' === $content ) {
				continue;
			}
			$normalized[] = [
				'role'    => $role,
				'content' => $content,
			];
		}

		/**
		 * Maximum number of prior conversation turns (messages) sent to the LLM.
		 *
		 * Limits token cost on long conversations. Counts individual messages,
		 * not exchanges (a Q + A pair is 2).
		 *
		 * @param int $window Default 10.
		 */
		$window = (int) apply_filters( 'hamelp_history_window', 10 );
		if ( $window > 0 && count( $normalized ) > $window ) {
			$normalized = array_slice( $normalized, -$window );
		}

		return $normalized;
	}

	/**
	 * Build full-content context for small FAQ sets.
	 *
	 * @param array[] $catalog FAQ catalog entries.
	 * @return string Context string for LLM.
	 */
	protected function build_full_context( array $catalog ): string {
		$lines = [ 'Available FAQs (full content):' ];
		foreach ( $catalog as $item ) {
			$entry = sprintf( "\n[ID:%d] %s", $item['id'], $item['title'] );
			if ( ! empty( $item['category'] ) ) {
				$entry .= sprintf( ' (Category: %s)', $item['category'] );
			}
			$entry  .= "\n" . $item['content'];
			$lines[] = $entry;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Build catalog context with title + excerpt for large FAQ sets.
	 *
	 * @param array[] $catalog FAQ catalog entries.
	 * @return string Context string for LLM.
	 */
	protected function build_catalog_context( array $catalog ): string {
		$lines = [ 'Available FAQs (title + summary):' ];
		foreach ( $catalog as $item ) {
			$entry = sprintf( "\n[ID:%d] %s", $item['id'], $item['title'] );
			if ( ! empty( $item['category'] ) ) {
				$entry .= sprintf( ' (Category: %s)', $item['category'] );
			}
			$entry  .= "\n" . $item['excerpt'];
			$lines[] = $entry;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Get system prompt for LLM.
	 *
	 * @param string $context FAQ context.
	 * @return string System prompt.
	 */
	protected function get_system_prompt( string $context ): string {
		$site_context = Settings::get_site_context();

		$base = 'You are a FAQ support assistant.';
		if ( ! empty( $site_context ) ) {
			$base .= "\n\n" . $site_context;
		}

		$user_context = $this->get_user_context();
		if ( ! empty( $user_context ) ) {
			$base .= "\n\n" . $user_context;
		}

		$base .= "\n\n" . 'Answer user questions based on the provided FAQ content.

IMPORTANT RULES:
- Select the most relevant FAQs from the provided list and answer the question.
- In your answer, refer to FAQs by their ID in the format [ID:42].
- DO NOT include a separate "Related FAQ" or "参考FAQ" or "関連FAQ" section at the end.
- The system will automatically display FAQ links based on the cited_ids you return.
- Only include IDs of FAQs you actually reference in cited_ids.
- If no FAQ is relevant, provide a helpful answer with an empty cited_ids array.
- Keep your response concise and helpful.
- If earlier conversation turns are provided, use them as context and answer follow-up questions accordingly.
- Respond in the same language as the user question.
- If user information is provided, you may address them by name and tailor your response to their context (e.g., role, membership). Do not repeat their personal information back to them.

OUTPUT FORMAT:
You MUST respond with a JSON object containing exactly these fields:
- "answer": (string) Your response text.
- "cited_ids": (array of integers) IDs of FAQs you referenced. Empty array if none.
Example: {"answer": "Your answer here.", "cited_ids": [42, 55]}';

		/**
		 * Filter the AI system prompt.
		 *
		 * @param string $prompt The system prompt.
		 */
		$base = apply_filters( 'hamelp_ai_system_prompt', $base );

		return $base . "\n\n" . $context;
	}

	/**
	 * Get user context string for personalization.
	 *
	 * Returns empty string for logged-out users.
	 *
	 * @return string User context for system prompt, empty if not logged in.
	 */
	protected function get_user_context(): string {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return '';
		}

		$lines   = [ 'Current user information:' ];
		$lines[] = sprintf( 'Display name: %s', $user->display_name );

		// Filter roles to prevent exposing internal plugin roles.
		$display_roles = $this->get_display_roles( $user );
		if ( ! empty( $display_roles ) ) {
			$lines[] = sprintf( 'Role: %s', implode( ', ', $display_roles ) );
		}

		$lines[] = sprintf( 'Registered: %s', $user->user_registered );

		$context = implode( "\n", $lines );

		/**
		 * Filter user context included in the AI system prompt.
		 *
		 * Allows sites to add custom user-specific information
		 * (e.g., subscription status, purchase history, membership tier)
		 * that helps the AI provide personalized answers.
		 *
		 * Return an empty string to disable user personalization entirely.
		 *
		 * @param string   $context The user context string.
		 * @param \WP_User $user    The current WordPress user object.
		 */
		$context = apply_filters( 'hamelp_user_context', $context, $user );

		return is_string( $context ) ? $context : '';
	}

	/**
	 * Get user roles filtered by whitelist for display.
	 *
	 * Filters out internal plugin roles (e.g., backup plugins)
	 * that should not be exposed to the AI.
	 *
	 * @param \WP_User $user The user object.
	 * @return string[] Filtered role names safe for display.
	 */
	protected function get_display_roles( \WP_User $user ): array {
		/**
		 * Filter the list of allowed user roles to display in AI context.
		 *
		 * Only roles in this list will be shown to the AI.
		 * This prevents internal plugin roles from being exposed.
		 *
		 * @param string[] $allowed_roles List of role slugs to allow.
		 */
		$allowed_roles = apply_filters(
			'hamelp_allowed_user_roles',
			[
				// WordPress core roles.
				'administrator',
				'editor',
				'author',
				'contributor',
				'subscriber',
				// WooCommerce roles.
				'customer',
				'shop_manager',
			]
		);

		$display_roles = array_intersect( $user->roles, $allowed_roles );

		/**
		 * Filter the user roles to be displayed in AI context.
		 *
		 * Called after whitelist filtering. Allows further customization
		 * such as translating role slugs to human-readable names.
		 *
		 * @param string[] $display_roles Roles to display (already filtered).
		 * @param \WP_User $user          The user object.
		 */
		return apply_filters( 'hamelp_display_user_roles', $display_roles, $user );
	}
}
