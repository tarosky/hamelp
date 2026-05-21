<?php
/**
 * Plugin Name:     Hamelp
 * Plugin URI:      https://wordpress.org/plugins/hamelp
 * Description:     FAQ generator by Hametuha.
 * Version:         1.0.4
 * Author:          Hametuha INC.
 * Author URI:      https://hametuha.co.jp
 * Text Domain:     hamelp
 * Domain Path:     /languages
 * Requires at least: 6.6
 * Requires PHP:    7.4
 * License:         GPL3 or Later
 *
 * @package         hamelp
 */

// Do not load directory.
defined( 'ABSPATH' ) || die();

/**
 * Check version and load plugin if possible.
 */
function hamelp_init() {
	// i18n (translations are loaded from WordPress.org via GlotPress).
	load_plugin_textdomain( 'hamelp' );
	if ( version_compare( phpversion(), '7.4.0', '>=' ) ) {
		require __DIR__ . '/vendor/autoload.php';
		call_user_func( [ 'Hametuha\\Hamelp', 'get' ] );
		// Load development hooks (environment check is inside the file).
		if ( file_exists( __DIR__ . '/dev/hooks.php' ) ) {
			require_once __DIR__ . '/dev/hooks.php';
		}
	} else {
		add_action( 'admin_notices', 'hamelp_version_error' );
	}
}
add_action( 'plugins_loaded', 'hamelp_init' );

/**
 * Plugin activation handler.
 *
 * Schedules an immediate FAQ catalog rebuild via wp-cron so the catalog is
 * populated on first use without requiring the user to manually save an FAQ.
 * The actual rebuild runs on the next page load after the cron hook has been
 * registered (during plugin init).
 */
function hamelp_activate() {
	if ( ! wp_next_scheduled( 'hamelp_rebuild_faq_catalog' ) ) {
		wp_schedule_single_event( time(), 'hamelp_rebuild_faq_catalog' );
	}
}
register_activation_hook( __FILE__, 'hamelp_activate' );


/**
 * Register all file in wp-dependencies.json
 *
 * @return void
 */
function hamelp_register_assets() {
	$path = __DIR__ . '/wp-dependencies.json';
	if ( ! file_exists( $path ) ) {
		return;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$deps = json_decode( file_get_contents( $path ), true );
	if ( empty( $deps ) ) {
		return;
	}
	// Register all assets in json
	foreach ( $deps as $dep ) {
		if ( empty( $dep['path'] ) ) {
			continue;
		}
		$url = plugin_dir_url( __DIR__ . '/assets' ) . $dep['path'];
		switch ( $dep['ext'] ) {
			case 'css':
				wp_register_style( $dep['handle'], $url, $dep['deps'], $dep['hash'], $dep['media'] );
				break;
			case 'js':
				$footer = [ 'in_footer' => $dep['footer'] ];
				if ( in_array( $dep['strategy'], [ 'defer', 'async' ], true ) ) {
					$footer['strategy'] = $dep['strategy'];
				}
				wp_register_script( $dep['handle'], $url, $dep['deps'], $dep['hash'], $footer );
				break;
		}
	}
}
add_action( 'init', 'hamelp_register_assets' );

/**
 * Display version error
 *
 * @internal
 */
function hamelp_version_error() {
	// translators: %1$s required PHP version, %2$s current PHP version.
	printf( '<div class="error"><p>%s</p></div>', sprintf( esc_html__( 'Hamelp requires PHP %1$s, but your PHP version is %2$s. Please consider upgrade.', 'hamelp' ), '7.4', esc_html( phpversion() ) ) );
}

/**
 * Get asset url
 *
 * @return string
 */
function hamelp_asset_url() {
	return plugin_dir_url( __FILE__ ) . 'assets';
}

/**
 * Get plugin version.
 */
function hamelp_version() {
	static $version = null;
	if ( is_null( $version ) ) {
		$file_info = get_file_data(
			__FILE__,
			[
				'version' => 'Version:',
			]
		);
		$version   = trim( $file_info['version'] );
	}
	return $version;
}

/**
 * Get accessibility of help document.
 *
 * @param null|int|WP_post $post
 *
 * @return string
 */
function hamelp_get_accessibility( $post = null ) {
	$post = get_post( $post );
	return (string) get_post_meta( $post->ID, '_accessibility', true );
}

/**
 * Register all blocks in assets/blocks directory.
 *
 * @return void
 */
function hamelp_register_blocks() {
	$blocks_dir = __DIR__ . '/assets/blocks';
	if ( ! is_dir( $blocks_dir ) ) {
		return;
	}
	foreach ( scandir( $blocks_dir ) as $block_name ) {
		if ( '.' === $block_name[0] ) {
			continue;
		}
		$block_path = $blocks_dir . '/' . $block_name;
		if ( is_dir( $block_path ) && file_exists( $block_path . '/block.json' ) ) {
			register_block_type( $block_path );
		}
	}
}
add_action( 'init', 'hamelp_register_blocks' );

/**
 * Render AI Overview widget.
 *
 * Use this in theme templates to output the AI FAQ search form.
 *
 * @param array $args {
 *     Optional. Widget arguments.
 *
 *     @type string $placeholder   Input placeholder text. Default 'Enter your question...'.
 *     @type string $button_text   Submit button text. Default 'Ask AI'.
 *     @type bool   $show_sources  Whether to show source FAQ links. Default true.
 *     @type string $wrapper_attrs Pre-built wrapper attributes string (used internally by block render).
 * }
 * @return string HTML output.
 */
function hamelp_render_ai_overview( $args = [] ) {
	$args = wp_parse_args(
		$args,
		[
			'placeholder'   => __( 'Enter your question...', 'hamelp' ),
			'button_text'   => __( 'Ask AI', 'hamelp' ),
			'show_sources'  => true,
			'wrapper_attrs' => '',
		]
	);

	// Enqueue block assets.
	$block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'hamelp/ai-overview' );
	if ( $block_type ) {
		foreach ( $block_type->view_script_handles as $handle ) {
			wp_enqueue_script( $handle );
		}
		foreach ( $block_type->style_handles as $handle ) {
			wp_enqueue_style( $handle );
		}
	}

	// Build wrapper attributes if not provided (non-block context).
	if ( empty( $args['wrapper_attrs'] ) ) {
		$args['wrapper_attrs'] = sprintf(
			'class="hamelp-ai-overview" data-show-sources="%s"',
			$args['show_sources'] ? 'true' : 'false'
		);
	}

	return sprintf(
		'<div %s>
	<form class="hamelp-ai-overview__form">
		<input type="text" class="hamelp-ai-overview__input" placeholder="%s" required />
		<button type="submit" class="hamelp-ai-overview__button">%s</button>
	</form>
	<div class="hamelp-ai-overview__result" aria-live="polite"></div>
</div>',
		$args['wrapper_attrs'],
		esc_attr( $args['placeholder'] ),
		esc_html( $args['button_text'] )
	);
}
