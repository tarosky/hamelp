<?php
/**
 * Settings hook handler.
 *
 * @package hamelp
 */

namespace Hametuha\Hamelp\Hooks;

use Hametuha\Hamelp\Pattern\Singleton;
use Hametuha\Hamelp\Services\FaqCatalogBuilder;

/**
 * Registers Hamelp settings page and fields.
 */
class Settings extends Singleton {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'hamelp-settings';

	/**
	 * Option group name.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'hamelp_settings';

	/**
	 * Option prefix for all AI settings.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'hamelp_ai_';

	/**
	 * Option prefix for rate limiting settings.
	 *
	 * @var string
	 */
	const RATE_PREFIX = 'hamelp_rate_';

	/**
	 * Action name for manual catalog rebuild form submission.
	 *
	 * @var string
	 */
	const REBUILD_ACTION = 'hamelp_rebuild_catalog';

	/**
	 * Initialize hooks.
	 */
	protected function init() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_' . self::REBUILD_ACTION, [ $this, 'handle_rebuild' ] );
	}

	/**
	 * Handle manual catalog rebuild form submission.
	 */
	public function handle_rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to rebuild the catalog.', 'hamelp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::REBUILD_ACTION );

		$builder = new FaqCatalogBuilder();
		$catalog = $builder->rebuild();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::PAGE_SLUG,
					'rebuilt' => 1,
					'count'   => count( $catalog ),
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Add settings page under Settings menu.
	 */
	public function add_menu_page() {
		add_options_page(
			__( 'Pubpla AI Help Center', 'hamelp' ),
			__( 'Help Center', 'hamelp' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Get AI context field definitions.
	 *
	 * @return array[] Field definitions keyed by option suffix.
	 */
	protected function get_ai_fields(): array {
		return [
			'site_description' => [
				'label'       => __( 'Site Description', 'hamelp' ),
				'description' => __( 'What is this site? What does it do?', 'hamelp' ),
				'placeholder' => __( 'e.g., This is a fiction publishing platform called "Hametuha".', 'hamelp' ),
				'rows'        => 2,
			],
			'target_users'     => [
				'label'       => __( 'Target Users', 'hamelp' ),
				'description' => __( 'Who are the people likely asking questions?', 'hamelp' ),
				'placeholder' => __( 'e.g., Writers who publish novels, or readers.', 'hamelp' ),
				'rows'        => 2,
			],
			'response_tone'    => [
				'label'       => __( 'Response Tone', 'hamelp' ),
				'description' => __( 'How should the AI respond?', 'hamelp' ),
				'placeholder' => __( 'e.g., Polite and friendly.', 'hamelp' ),
				'rows'        => 1,
			],
			'notes'            => [
				'label'       => __( 'Notes', 'hamelp' ),
				'description' => __( 'Anything the AI should avoid or keep in mind?', 'hamelp' ),
				'placeholder' => __( 'e.g., Do not give legal advice. Do not recommend competing services.', 'hamelp' ),
				'rows'        => 2,
			],
		];
	}

	/**
	 * Get rate limiting field definitions.
	 *
	 * @return array[] Field definitions keyed by option suffix.
	 */
	protected function get_rate_fields(): array {
		return [
			'per_ip'        => [
				'label'       => __( 'Per IP Limit', 'hamelp' ),
				'description' => __( 'Maximum requests per IP within the time window.', 'hamelp' ),
				'type'        => 'number',
				'default'     => 5,
				'min'         => 1,
			],
			'window'        => [
				'label'       => __( 'Time Window (minutes)', 'hamelp' ),
				'description' => __( 'Time window for per-IP rate limiting.', 'hamelp' ),
				'type'        => 'number',
				'default'     => 10,
				'min'         => 1,
			],
			'daily'         => [
				'label'       => __( 'Daily Global Limit', 'hamelp' ),
				'description' => __( 'Maximum total requests per day across all users.', 'hamelp' ),
				'type'        => 'number',
				'default'     => 100,
				'min'         => 1,
			],
			'require_login' => [
				'label'       => __( 'Require Login', 'hamelp' ),
				'description' => __( 'Only allow logged-in users to use AI Overview.', 'hamelp' ),
				'type'        => 'checkbox',
				'default'     => '',
			],
		];
	}

	/**
	 * Register settings and fields.
	 */
	public function register_settings() {
		// AI Overview mode (feature switch).
		register_setting(
			self::OPTION_GROUP,
			'hamelp_ai_overview_mode',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_mode' ],
				'default'           => 'conversation',
			]
		);

		// AI Overview fields.
		$fields = $this->get_ai_fields();
		foreach ( $fields as $suffix => $field ) {
			$option_name = self::OPTION_PREFIX . $suffix;
			register_setting(
				self::OPTION_GROUP,
				$option_name,
				[
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => '',
				]
			);
		}

		add_settings_section(
			'hamelp_ai_section',
			__( 'AI Overview', 'hamelp' ),
			[ $this, 'render_section' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'hamelp_ai_overview_mode',
			__( 'Mode', 'hamelp' ),
			[ $this, 'render_select' ],
			self::PAGE_SLUG,
			'hamelp_ai_section',
			[
				'option_name' => 'hamelp_ai_overview_mode',
				'default'     => 'conversation',
				'choices'     => [
					'conversation' => __( 'Conversation (multi-turn): visitors can ask follow-up questions.', 'hamelp' ),
					'single'       => __( 'Single answer: each question is answered independently (no follow-up, lower cost).', 'hamelp' ),
					'off'          => __( 'Disabled: the AI Overview is turned off everywhere.', 'hamelp' ),
				],
				'description' => __( 'Controls how the AI Overview behaves. Switch to "Single answer" or "Disabled" to cut LLM cost or stop the feature during a request flood or abuse spike.', 'hamelp' ),
			]
		);

		foreach ( $fields as $suffix => $field ) {
			$option_name = self::OPTION_PREFIX . $suffix;
			add_settings_field(
				$option_name,
				$field['label'],
				[ $this, 'render_textarea' ],
				self::PAGE_SLUG,
				'hamelp_ai_section',
				[
					'option_name' => $option_name,
					'description' => $field['description'],
					'placeholder' => $field['placeholder'],
					'rows'        => $field['rows'],
				]
			);
		}

		// Rate limiting fields.
		$rate_fields = $this->get_rate_fields();
		foreach ( $rate_fields as $suffix => $field ) {
			$option_name = self::RATE_PREFIX . $suffix;
			$is_number   = 'number' === $field['type'];
			register_setting(
				self::OPTION_GROUP,
				$option_name,
				[
					'type'              => $is_number ? 'integer' : 'string',
					'sanitize_callback' => $is_number ? 'absint' : 'sanitize_text_field',
					'default'           => $field['default'],
				]
			);
		}

		add_settings_section(
			'hamelp_rate_section',
			__( 'Rate Limiting', 'hamelp' ),
			[ $this, 'render_rate_section' ],
			self::PAGE_SLUG
		);

		foreach ( $rate_fields as $suffix => $field ) {
			$option_name = self::RATE_PREFIX . $suffix;
			$renderer    = 'checkbox' === $field['type'] ? 'render_checkbox' : 'render_number';
			add_settings_field(
				$option_name,
				$field['label'],
				[ $this, $renderer ],
				self::PAGE_SLUG,
				'hamelp_rate_section',
				[
					'option_name' => $option_name,
					'description' => $field['description'],
					'default'     => $field['default'],
					'min'         => $field['min'] ?? null,
				]
			);
		}

		// Conversation history (opt-in, disabled by default for privacy).
		register_setting(
			self::OPTION_GROUP,
			'hamelp_save_conversations',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);

		add_settings_section(
			'hamelp_history_section',
			__( 'Conversation History', 'hamelp' ),
			[ $this, 'render_history_section' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'hamelp_save_conversations',
			__( 'Save Conversations', 'hamelp' ),
			[ $this, 'render_checkbox' ],
			self::PAGE_SLUG,
			'hamelp_history_section',
			[
				'option_name' => 'hamelp_save_conversations',
				'description' => __( 'Store AI Overview conversations so you can review what visitors asked. Questions are saved to your database.', 'hamelp' ),
			]
		);

		register_setting(
			self::OPTION_GROUP,
			'hamelp_retention_days',
			[
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			]
		);

		add_settings_field(
			'hamelp_retention_days',
			__( 'Auto-delete After (days)', 'hamelp' ),
			[ $this, 'render_number' ],
			self::PAGE_SLUG,
			'hamelp_history_section',
			[
				'option_name' => 'hamelp_retention_days',
				'description' => __( 'Automatically delete anonymous conversations older than this many days. 0 means never delete. Conversations from logged-in users are never auto-deleted (delete the user account to remove their data).', 'hamelp' ),
				'default'     => 0,
				'min'         => 0,
			]
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_page() {
		$builder = new FaqCatalogBuilder();
		$catalog = $builder->get_catalog();
		$updated = $builder->get_last_updated();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pubpla AI Help Center', 'hamelp' ); ?></h1>

			<?php if ( isset( $_GET['rebuilt'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: Number of FAQ entries in the catalog. */
							esc_html__( 'FAQ catalog rebuilt. %d entries.', 'hamelp' ),
							isset( $_GET['count'] ) ? (int) $_GET['count'] : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'FAQ Catalog', 'hamelp' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %d: Number of FAQ entries in the catalog. */
					esc_html__( 'Catalog entries: %d', 'hamelp' ),
					count( $catalog )
				);
				if ( $updated ) {
					$date = wp_date(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						$updated
					);
					echo '<br>';
					printf(
						/* translators: %s: Last updated date and time. */
						esc_html__( 'Last updated: %s', 'hamelp' ),
						esc_html( $date )
					);
				} else {
					echo '<br><em>' . esc_html__( 'Catalog has never been built.', 'hamelp' ) . '</em>';
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::REBUILD_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::REBUILD_ACTION ); ?>">
				<?php submit_button( __( 'Rebuild Catalog Now', 'hamelp' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section() {
		printf(
			'<p>%s</p>',
			esc_html__( 'These settings help the AI generate more accurate answers to FAQ questions.', 'hamelp' )
		);
	}

	/**
	 * Render rate limiting section description.
	 */
	public function render_rate_section() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Protect the AI Overview endpoint from excessive usage. Each request costs LLM tokens.', 'hamelp' )
		);
	}

	/**
	 * Render conversation history section description.
	 */
	public function render_history_section() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Optionally keep a record of AI Overview conversations for question mining. Automatic deletion and privacy tools will be added in a future release.', 'hamelp' )
		);
	}

	/**
	 * Render a textarea field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_textarea( array $args ) {
		$value = get_option( $args['option_name'], '' );
		printf(
			'<textarea name="%s" id="%s" class="large-text" rows="%d" placeholder="%s">%s</textarea>',
			esc_attr( $args['option_name'] ),
			esc_attr( $args['option_name'] ),
			(int) $args['rows'],
			esc_attr( $args['placeholder'] ),
			esc_textarea( $value )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html( $args['description'] )
		);
	}

	/**
	 * Render a number input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_number( array $args ) {
		$value = get_option( $args['option_name'], $args['default'] );
		$min   = isset( $args['min'] ) ? sprintf( ' min="%d"', (int) $args['min'] ) : '';
		printf(
			'<input type="number" name="%s" id="%s" value="%d" class="small-text"%s />',
			esc_attr( $args['option_name'] ),
			esc_attr( $args['option_name'] ),
			(int) $value,
			$min // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		printf(
			'<p class="description">%s</p>',
			esc_html( $args['description'] )
		);
	}

	/**
	 * Render a select (dropdown) field.
	 *
	 * @param array $args Field arguments (option_name, choices, default, description).
	 */
	public function render_select( array $args ) {
		$value   = get_option( $args['option_name'], $args['default'] ?? '' );
		$choices = isset( $args['choices'] ) && is_array( $args['choices'] ) ? $args['choices'] : [];
		printf( '<select name="%1$s" id="%1$s">', esc_attr( $args['option_name'] ) );
		foreach ( $choices as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Sanitize the AI Overview mode option.
	 *
	 * @param string $value Submitted value.
	 * @return string A valid mode, defaulting to `conversation`.
	 */
	public function sanitize_mode( $value ) {
		$allowed = [ 'conversation', 'single', 'off' ];
		return in_array( $value, $allowed, true ) ? $value : 'conversation';
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_checkbox( array $args ) {
		$value = get_option( $args['option_name'], '' );
		printf(
			'<label><input type="checkbox" name="%s" id="%s" value="1" %s /> %s</label>',
			esc_attr( $args['option_name'] ),
			esc_attr( $args['option_name'] ),
			checked( $value, '1', false ),
			esc_html( $args['description'] )
		);
	}

	/**
	 * Get composed site context string for system prompt.
	 *
	 * @return string Composed context, empty if nothing is set.
	 */
	public static function get_site_context(): string {
		$parts = [
			'site_description' => __( 'About this site: %s', 'hamelp' ),
			'target_users'     => __( 'Target users: %s', 'hamelp' ),
			'response_tone'    => __( 'Response tone: %s', 'hamelp' ),
			'notes'            => __( 'Important notes: %s', 'hamelp' ),
		];

		$lines = [];
		foreach ( $parts as $suffix => $template ) {
			$value = get_option( self::OPTION_PREFIX . $suffix, '' );
			if ( ! empty( $value ) ) {
				$lines[] = sprintf( $template, trim( $value ) );
			}
		}
		return implode( "\n", $lines );
	}
}
