<?php
/**
 * AI Overview Block Render Template
 *
 * @package hamelp
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

$show_sources = ! empty( $attributes['showSources'] ) ? 'true' : 'false';

$wrapper_attributes = get_block_wrapper_attributes(
	[
		'class'             => 'hamelp-ai-overview',
		'data-show-sources' => $show_sources,
		'data-mode'         => hamelp_ai_overview_mode(),
	]
);

echo hamelp_render_ai_overview( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	[
		'placeholder'   => $attributes['placeholder'] ?? __( 'Enter your question...', 'hamelp' ),
		'button_text'   => $attributes['buttonText'] ?? __( 'Ask AI', 'hamelp' ),
		'show_sources'  => ! empty( $attributes['showSources'] ),
		'wrapper_attrs' => $wrapper_attributes,
	]
);
