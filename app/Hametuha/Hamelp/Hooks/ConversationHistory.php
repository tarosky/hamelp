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
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
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
				$new[ $key ]      = __( 'First Question', 'hamelp' );
				$new['exchanges'] = __( 'Exchanges', 'hamelp' );
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
		if ( 'exchanges' !== $column ) {
			return;
		}
		echo esc_html( (string) $this->count_exchanges( $this->get_turns( $post_id ) ) );
	}

	/**
	 * Register the conversation transcript meta box.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'hamelp_conversation',
			__( 'Conversation', 'hamelp' ),
			[ $this, 'render_meta_box' ],
			ConversationStore::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the conversation transcript meta box (read-only).
	 *
	 * @param \WP_Post $post Conversation post.
	 */
	public function render_meta_box( $post ) {
		$uuid  = (string) get_post_meta( $post->ID, ConversationStore::META_UUID, true );
		$turns = $this->get_turns( $post->ID );

		echo '<p><strong>' . esc_html__( 'Owner token (UUID):', 'hamelp' ) . '</strong> ';
		echo '<code>' . esc_html( $uuid ? $uuid : __( '(none)', 'hamelp' ) ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Exchanges:', 'hamelp' ) . '</strong> ';
		echo esc_html( (string) $this->count_exchanges( $turns ) ) . '</p>';

		if ( empty( $turns ) ) {
			echo '<p>' . esc_html__( 'No conversation data.', 'hamelp' ) . '</p>';
			return;
		}

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<div class="hamelp-conversation-log">';
		foreach ( $turns as $turn ) {
			$role    = isset( $turn['role'] ) ? (string) $turn['role'] : '';
			$is_user = 'user' === $role;
			$label   = $is_user ? __( 'Question', 'hamelp' ) : __( 'Answer', 'hamelp' );
			$bg      = $is_user ? '#f0f6fc' : '#f6f7f7';
			$time    = ! empty( $turn['ts'] ) ? wp_date( $date_format, (int) $turn['ts'] ) : '';
			$content = isset( $turn['content'] ) ? (string) $turn['content'] : '';

			printf(
				'<div style="margin:0 0 12px;padding:10px 14px;border:1px solid #dcdcde;border-radius:4px;background:%s">',
				esc_attr( $bg )
			);
			printf(
				'<p style="margin:0 0 6px;font-weight:600">%s <span style="font-weight:400;color:#787c82">%s</span></p>',
				esc_html( $label ),
				esc_html( $time )
			);
			echo '<div style="white-space:pre-wrap">' . esc_html( $content ) . '</div>';

			// Cited sources for assistant turns.
			if ( ! $is_user && ! empty( $turn['cited_ids'] ) && is_array( $turn['cited_ids'] ) ) {
				$links = [];
				foreach ( $turn['cited_ids'] as $cid ) {
					$cid   = (int) $cid;
					$title = get_the_title( $cid );
					if ( $title ) {
						$links[] = sprintf(
							'<a href="%s">%s</a>',
							esc_url( (string) get_edit_post_link( $cid ) ),
							esc_html( $title )
						);
					}
				}
				if ( $links ) {
					echo '<p style="margin:8px 0 0;font-size:12px;color:#787c82">'
						. esc_html__( 'Sources:', 'hamelp' ) . ' '
						. wp_kses( implode( ', ', $links ), [ 'a' => [ 'href' => [] ] ] )
						. '</p>';
				}
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Decode the stored transcript for a conversation post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Decoded turns.
	 */
	protected function get_turns( $post_id ) {
		$raw   = get_post_meta( $post_id, ConversationStore::META_TURNS, true );
		$turns = $raw ? json_decode( $raw, true ) : [];
		return is_array( $turns ) ? $turns : [];
	}

	/**
	 * Count exchanges (answers) in a transcript.
	 *
	 * One exchange = one question + one answer, so the assistant turns are counted.
	 *
	 * @param array[] $turns Decoded turns.
	 * @return int
	 */
	protected function count_exchanges( $turns ) {
		$count = 0;
		foreach ( $turns as $turn ) {
			if ( isset( $turn['role'] ) && 'assistant' === $turn['role'] ) {
				++$count;
			}
		}
		return $count;
	}
}
