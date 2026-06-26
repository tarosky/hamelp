<?php
/**
 * Plugin Name:     Pubpla AI Help Center
 * Plugin URI:      https://wordpress.org/plugins/hamelp
 * Description:     AI powered FAQ and Help Document Management Plugin for WordPress.
 * Version:         2.3.0
 * Author:          Tarosky
 * Author URI:      https://tarosky.co.jp
 * Domain Path:     /languages
 * Requires at least: 6.6
 * Requires PHP:    7.4
 * License:         GPL3 or Later
 *
 * Copyright (C) 2018 Tarosky Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
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
 * Plugin deactivation handler.
 *
 * Clears scheduled cron events so they do not linger after deactivation.
 */
function hamelp_deactivate() {
	wp_clear_scheduled_hook( 'hamelp_purge_conversations' );
}
register_deactivation_hook( __FILE__, 'hamelp_deactivate' );


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
	printf( '<div class="error"><p>%s</p></div>', sprintf( esc_html__( 'Pubpla AI Help Center requires PHP %1$s, but your PHP version is %2$s. Please consider upgrade.', 'hamelp' ), '7.4', esc_html( phpversion() ) ) );
}

/**
 * Get the AI Overview operating mode.
 *
 * - `conversation`: multi-turn conversation (default).
 * - `single`: single-shot Q&A; prior turns are ignored (cheaper per request).
 * - `off`: feature disabled (front-end renders nothing, REST is rejected).
 *
 * @return string One of `conversation`, `single`, `off`.
 */
function hamelp_ai_overview_mode() {
	$mode = get_option( 'hamelp_ai_overview_mode', 'conversation' );
	/**
	 * Filter the AI Overview operating mode.
	 *
	 * @param string $mode One of `conversation`, `single`, `off`.
	 */
	$mode = apply_filters( 'hamelp_ai_overview_mode', $mode );
	return in_array( $mode, [ 'conversation', 'single', 'off' ], true ) ? $mode : 'conversation';
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
 * Render FAQ incremental search box.
 *
 * Use this in theme templates or block render templates to output the FAQ
 * incremental search form. The shortcode `[hamelp-search]` and the
 * `hamelp/search-box` block both delegate to this function.
 *
 * @param array $args {
 *     Optional. Search box arguments.
 *
 *     @type string $label         Input placeholder text. Default 'Enter keyword and hit search.'.
 *     @type string $btn           Submit button label. Default 'Search'.
 *     @type string $wrapper_attrs Pre-built wrapper attributes string (used by block render).
 * }
 * @return string HTML output.
 */
function hamelp_render_search_box( $args = [] ) {
	static $localized = false;

	$args = wp_parse_args(
		$args,
		[
			'label'         => __( 'Enter keyword and hit search.', 'hamelp' ),
			'btn'           => __( 'Search', 'hamelp' ),
			'wrapper_attrs' => '',
		]
	);

	wp_enqueue_script( 'hamelp-incsearch' );
	wp_enqueue_style( 'hamelp-incsearch' );
	if ( ! $localized ) {
		wp_localize_script(
			'hamelp-incsearch',
			'HamelpIncSearch',
			[
				'endpoint' => rest_url( '/wp/v2/faq' ),
				'found'    => __( 'Found Posts:', 'hamelp' ),
				'notFound' => __( 'No posts found. Please change the query.', 'hamelp' ),
			]
		);
		$localized = true;
	}

	$post_type_inputs = '';
	foreach ( array_keys( \Hametuha\Hamelp\Hooks\PostType::get()->get_post_types() ) as $post_type ) {
		$post_type_inputs .= sprintf( '<input type="hidden" name="post_type" value="%s" />', esc_attr( $post_type ) );
	}

	$query  = get_search_query();
	$action = esc_url( apply_filters( 'hamelp_endpoint', home_url( '' ) ) );

	if ( empty( $args['wrapper_attrs'] ) ) {
		$args['wrapper_attrs'] = 'class="hamelp-search-box"';
	}

	return sprintf(
		'<form %1$s action="%2$s">%3$s<div class="input-group"><input type="search" class="form-control hamelp-search-input" name="s" placeholder="%4$s" value="%5$s" /><button class="btn btn-secondary hamelp-search-button" type="submit">%6$s</button></div><div class="hamelp-result-wrapper"><div class="hamelp-result list-group"></div></div></form>',
		$args['wrapper_attrs'],
		$action,
		$post_type_inputs,
		esc_attr( $args['label'] ),
		esc_attr( $query ),
		esc_html( $args['btn'] )
	);
}

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
	// When the feature is disabled (e.g. to stop a request flood), render nothing.
	if ( 'off' === hamelp_ai_overview_mode() ) {
		return '';
	}

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

	$mode = hamelp_ai_overview_mode();

	// Build wrapper attributes if not provided (non-block context).
	if ( empty( $args['wrapper_attrs'] ) ) {
		$args['wrapper_attrs'] = sprintf(
			'class="hamelp-ai-overview" data-show-sources="%s" data-mode="%s"',
			$args['show_sources'] ? 'true' : 'false',
			esc_attr( $mode )
		);
	}

	// In conversation mode, offer a "carry over the previous conversation" toggle.
	// Single mode answers each question independently, so no toggle is shown.
	$continue_toggle = '';
	if ( 'conversation' === $mode ) {
		// Hidden until there is at least one exchange; view.js reveals it.
		$continue_toggle = sprintf(
			'<label class="hamelp-ai-overview__continue" hidden><input type="checkbox" class="hamelp-ai-overview__continue-toggle" checked /> %s</label>',
			esc_html__( 'Continue the previous conversation', 'hamelp' )
		);
	}

	return sprintf(
		'<div %1$s>
	<div class="hamelp-ai-overview__thread" aria-live="polite"></div>
	<form class="hamelp-ai-overview__form">
		%2$s
		<div class="hamelp-ai-overview__input-row">
			<input type="text" class="hamelp-ai-overview__input" placeholder="%3$s" required />
			<button type="submit" class="hamelp-ai-overview__button">%4$s</button>
		</div>
	</form>
</div>',
		$args['wrapper_attrs'],
		$continue_toggle, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html__ above.
		esc_attr( $args['placeholder'] ),
		esc_html( $args['button_text'] )
	);
}
