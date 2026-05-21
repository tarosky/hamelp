<?php
/**
 * FAQ Search Box Block Render Template
 *
 * @package hamelp
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

$wrapper_attributes = get_block_wrapper_attributes(
	[
		'class' => 'hamelp-search-box',
	]
);

echo hamelp_render_search_box( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	[
		'label'         => $attributes['label'] ?? __( 'Enter keyword and hit search.', 'hamelp' ),
		'btn'           => $attributes['btn'] ?? __( 'Search', 'hamelp' ),
		'wrapper_attrs' => $wrapper_attributes,
	]
);
