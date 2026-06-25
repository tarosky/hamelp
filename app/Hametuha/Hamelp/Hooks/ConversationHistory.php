<?php
/**
 * Conversation history hook handler.
 *
 * @package hamelp
 */

namespace Hametuha\Hamelp\Hooks;

use Hametuha\Hamelp\Pattern\Singleton;
use Hametuha\Hamelp\Services\ConversationStore;

/**
 * Registers the private conversation post type used for question mining.
 *
 * The post type is intentionally non-public: it is not queryable on the front
 * end and is excluded from search and the REST listing. It is only surfaced in
 * wp-admin so site owners can review what visitors actually asked.
 */
class ConversationHistory extends Singleton {

	/**
	 * Initialize hooks.
	 */
	protected function init() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_filter( 'manage_' . ConversationStore::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . ConversationStore::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	/**
	 * Register the conversation post type.
	 */
	public function register_post_type() {
		register_post_type(
			ConversationStore::POST_TYPE,
			[
				'label'               => __( 'Conversations', 'hamelp' ),
				'labels'              => [
					'name'          => __( 'Conversations', 'hamelp' ),
					'singular_name' => __( 'Conversation', 'hamelp' ),
					'menu_name'     => __( 'AI Conversations', 'hamelp' ),
				],
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'menu_icon'           => 'dashicons-format-chat',
				'menu_position'       => 21,
				'supports'            => [ 'title', 'editor', 'author' ],
				'map_meta_cap'        => true,
				// Read-only data: prevent creating new conversations by hand.
				'capabilities'        => [
					'create_posts' => 'do_not_allow',
				],
			]
		);
	}

	/**
	 * Customize admin list columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new[ $key ]  = __( 'First Question', 'hamelp' );
				$new['turns'] = __( 'Turns', 'hamelp' );
			} else {
				$new[ $key ] = $label;
			}
		}
		return $new;
	}

	/**
	 * Render custom column values.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'turns' !== $column ) {
			return;
		}
		$raw   = get_post_meta( $post_id, ConversationStore::META_TURNS, true );
		$turns = $raw ? json_decode( $raw, true ) : [];
		echo esc_html( is_array( $turns ) ? (string) count( $turns ) : '0' );
	}
}
