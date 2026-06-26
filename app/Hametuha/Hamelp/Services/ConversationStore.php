<?php
/**
 * Conversation history persistence.
 *
 * @package hamelp
 */

namespace Hametuha\Hamelp\Services;

/**
 * Stores AI Overview conversations as a private custom post type.
 *
 * One conversation = one post. The first question is the post title and the
 * first answer is the post content (for admin question-mining), while the full
 * transcript is kept as a JSON array in post meta. Ownership is a server-issued
 * UUID stored in meta and treated as an unguessable capability token.
 */
class ConversationStore {

	/**
	 * Post type slug for stored conversations.
	 *
	 * @var string
	 */
	const POST_TYPE = 'hamelp_chat';

	/**
	 * Meta key for the ownership UUID (capability token).
	 *
	 * @var string
	 */
	const META_UUID = '_hamelp_uuid';

	/**
	 * Meta key for the JSON-encoded transcript.
	 *
	 * @var string
	 */
	const META_TURNS = '_hamelp_turns';

	/**
	 * Whether conversation saving is enabled.
	 *
	 * Opt-in: disabled by default for privacy. Site owners enable it on the
	 * settings page, and a filter allows per-request override.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$enabled = (bool) get_option( 'hamelp_save_conversations', '' );

		/**
		 * Filter whether to persist AI Overview conversations.
		 *
		 * @param bool $enabled Whether saving is enabled.
		 */
		return (bool) apply_filters( 'hamelp_save_conversations', $enabled );
	}

	/**
	 * Append a completed exchange to a conversation, creating it if needed.
	 *
	 * Called only after a successful AI answer (so empty/abandoned attempts are
	 * never stored). When $uuid matches an existing conversation the turns are
	 * appended; otherwise a new conversation is created with a fresh UUID.
	 *
	 * @param string|null $uuid      Existing conversation UUID, or null/empty for a new one.
	 * @param string      $question  The user's question.
	 * @param string      $answer    The AI's answer.
	 * @param int[]       $cited_ids Cited FAQ post IDs for this answer.
	 * @return array{uuid:string,post_id:int}
	 */
	public function save_turn( ?string $uuid, string $question, string $answer, array $cited_ids ): array {
		$question  = sanitize_textarea_field( $question );
		$answer    = wp_kses_post( $answer );
		$cited_ids = array_values( array_map( 'intval', $cited_ids ) );

		$now            = time();
		$turn_user      = [
			'role'    => 'user',
			'content' => $question,
			'ts'      => $now,
		];
		$turn_assistant = [
			'role'      => 'assistant',
			'content'   => $answer,
			'cited_ids' => $cited_ids,
			'ts'        => $now,
		];

		$post = $uuid ? $this->get_post_by_uuid( $uuid ) : null;

		if ( $post ) {
			$turns   = $this->read_turns( $post->ID );
			$turns[] = $turn_user;
			$turns[] = $turn_assistant;
			update_post_meta( $post->ID, self::META_TURNS, wp_slash( wp_json_encode( $turns ) ) );
			// Touch modified time so the admin list reflects recent activity.
			wp_update_post(
				[
					'ID'                => $post->ID,
					'post_modified'     => current_time( 'mysql' ),
					'post_modified_gmt' => current_time( 'mysql', true ),
				]
			);
			return [
				'uuid'    => (string) get_post_meta( $post->ID, self::META_UUID, true ),
				'post_id' => $post->ID,
			];
		}

		// New conversation.
		$uuid    = wp_generate_uuid4();
		$turns   = [ $turn_user, $turn_assistant ];
		$post_id = wp_insert_post(
			[
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $question,
				'post_content' => $answer,
				'post_author'  => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return [
				'uuid'    => '',
				'post_id' => 0,
			];
		}

		update_post_meta( $post_id, self::META_UUID, $uuid );
		update_post_meta( $post_id, self::META_TURNS, wp_slash( wp_json_encode( $turns ) ) );

		return [
			'uuid'    => $uuid,
			'post_id' => (int) $post_id,
		];
	}

	/**
	 * Read a conversation by its UUID capability token.
	 *
	 * Returns null when no conversation matches the token. Each assistant turn
	 * is enriched with resolved source links from its cited FAQ IDs.
	 *
	 * @param string $uuid Conversation UUID.
	 * @return array|null `['id'=>int,'title'=>string,'turns'=>array[],'updated'=>int]` or null.
	 */
	public function get_conversation( string $uuid ): ?array {
		if ( '' === $uuid ) {
			return null;
		}
		$post = $this->get_post_by_uuid( $uuid );
		if ( ! $post ) {
			return null;
		}

		$search = new FaqSearchService();
		$turns  = [];
		foreach ( $this->read_turns( $post->ID ) as $turn ) {
			if ( isset( $turn['role'] ) && 'assistant' === $turn['role'] ) {
				$turn['sources'] = $search->resolve_sources( $turn['cited_ids'] ?? [] );
			}
			$turns[] = $turn;
		}

		return [
			'id'      => $post->ID,
			'title'   => get_the_title( $post ),
			'turns'   => $turns,
			'updated' => (int) get_post_timestamp( $post, 'modified' ),
		];
	}

	/**
	 * Delete anonymous conversations older than the retention period.
	 *
	 * Only anonymous conversations (post_author = 0) are purged. Conversations
	 * owned by a logged-in user are kept, because their data is removed when the
	 * user account itself is deleted. A value of 0 (or less) disables purging.
	 *
	 * @param int $days Retention period in days. 0 disables deletion.
	 * @param int $limit Maximum conversations to delete per run (batch cap).
	 * @return int Number of conversations deleted.
	 */
	public function purge_expired( int $days, int $limit = 200 ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'author__in'     => [ 0 ], // Anonymous only; logged-in conversations are excluded.
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => [
					[
						'column' => 'post_modified_gmt',
						'before' => $before,
					],
				],
			]
		);

		$count = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Find a conversation post by its UUID.
	 *
	 * @param string $uuid Conversation UUID.
	 * @return \WP_Post|null
	 */
	protected function get_post_by_uuid( string $uuid ): ?\WP_Post {
		$posts = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'meta_key'         => self::META_UUID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => false,
				'no_found_rows'    => true,
			]
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Read and decode the stored transcript for a conversation.
	 *
	 * @param int $post_id Conversation post ID.
	 * @return array[] Decoded turns (empty array when missing/invalid).
	 */
	protected function read_turns( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_TURNS, true );
		if ( ! $raw ) {
			return [];
		}
		$turns = json_decode( $raw, true );
		return is_array( $turns ) ? $turns : [];
	}
}
