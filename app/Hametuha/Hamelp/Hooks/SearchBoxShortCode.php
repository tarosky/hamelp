<?php

namespace Hametuha\Hamelp\Hooks;

use Hametuha\Hamelp\Pattern\ShortCode;

/**
 * Render search box.
 *
 * @package Render search box.
 */
class SearchBoxShortCode extends ShortCode {

	/**
	 * Shortcode name.
	 *
	 * @var string
	 */
	protected $code = 'hamelp-search';

	/**
	 * Dashicon class name for shortcode UI.
	 *
	 * @var string
	 */
	protected $dashicons = 'dashicons-search';

	/**
	 * Return label for this shortcode.
	 *
	 * @return string
	 */
	protected function get_label() {
		return __( 'FAQ Search Box', 'hamelp' );
	}

	/**
	 * Render shortcode content
	 *
	 * @param array  $atts
	 * @param string $content
	 * @return string
	 */
	public function render_code( $atts, $content = '' ) {
		return hamelp_render_search_box(
			[
				'label' => $atts['label'],
				'btn'   => $atts['btn'],
			]
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_code_attributes() {
		return [
			[
				'attr'    => 'label',
				'label'   => __( 'Label', 'hamelp' ),
				'type'    => 'text',
				'default' => __( 'Enter keyword and hit search.', 'hamelp' ),
			],
			[
				'attr'    => 'btn',
				'label'   => __( 'Button Text', 'hamelp' ),
				'type'    => 'text',
				'default' => __( 'Search', 'hamelp' ),
			],
		];
	}
}
