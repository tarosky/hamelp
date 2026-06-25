<?php
/**
 * Conversation persistence tests.
 *
 * @package Hamelp
 */

use Hametuha\Hamelp\Services\ConversationStore;

/**
 * Tests for ConversationStore (save/append/read by UUID).
 */
class ConversationStoreTest extends WP_UnitTestCase {

	/**
	 * @var ConversationStore
	 */
	protected $store = null;

	/**
	 * Set up store and ensure the post type is registered.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! post_type_exists( ConversationStore::POST_TYPE ) ) {
			\Hametuha\Hamelp\Hooks\ConversationHistory::get()->register_post_type();
		}
		$this->store = new ConversationStore();
	}

	/**
	 * Saving is opt-in: disabled by default, toggled by option and filter.
	 */
	public function test_is_enabled() {
		$this->assertFalse( $this->store->is_enabled() );

		update_option( 'hamelp_save_conversations', '1' );
		$this->assertTrue( $this->store->is_enabled() );

		update_option( 'hamelp_save_conversations', '' );
		$filter = static function () {
			return true;
		};
		add_filter( 'hamelp_save_conversations', $filter );
		$this->assertTrue( $this->store->is_enabled() );
		remove_filter( 'hamelp_save_conversations', $filter );
	}

	/**
	 * A new conversation creates one post with title=Q, content=A, 2 turns.
	 */
	public function test_save_turn_creates_conversation() {
		$saved = $this->store->save_turn( null, 'How do I return an item?', 'Within 30 days.', [] );

		$this->assertNotEmpty( $saved['uuid'] );
		$this->assertGreaterThan( 0, $saved['post_id'] );

		$post = get_post( $saved['post_id'] );
		$this->assertSame( ConversationStore::POST_TYPE, $post->post_type );
		$this->assertSame( 'How do I return an item?', $post->post_title );
		$this->assertStringContainsString( 'Within 30 days.', $post->post_content );

		$turns = json_decode( get_post_meta( $saved['post_id'], ConversationStore::META_TURNS, true ), true );
		$this->assertCount( 2, $turns );
		$this->assertSame( 'user', $turns[0]['role'] );
		$this->assertSame( 'assistant', $turns[1]['role'] );
	}

	/**
	 * A matching UUID appends to the same post instead of creating a new one.
	 */
	public function test_save_turn_appends_to_same_post() {
		$first = $this->store->save_turn( null, 'Q1', 'A1', [] );
		$again = $this->store->save_turn( $first['uuid'], 'Q2', 'A2', [] );

		$this->assertSame( $first['post_id'], $again['post_id'] );
		$this->assertSame( $first['uuid'], $again['uuid'] );

		$turns = json_decode( get_post_meta( $first['post_id'], ConversationStore::META_TURNS, true ), true );
		$this->assertCount( 4, $turns );
		$this->assertSame( 'Q2', $turns[2]['content'] );
		$this->assertSame( 'A2', $turns[3]['content'] );

		// Only one conversation post exists.
		$count = ( new WP_Query(
			[
				'post_type'      => ConversationStore::POST_TYPE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		) )->found_posts;
		$this->assertSame( 1, $count );
	}

	/**
	 * An unknown/empty UUID with a follow-up starts a fresh conversation.
	 */
	public function test_unknown_uuid_creates_new() {
		$saved = $this->store->save_turn( 'nonexistent-uuid', 'Q', 'A', [] );
		$this->assertNotEmpty( $saved['uuid'] );
		$this->assertNotSame( 'nonexistent-uuid', $saved['uuid'] );
	}

	/**
	 * get_conversation returns the transcript for a matching UUID, null otherwise.
	 */
	public function test_get_conversation() {
		$faq   = self::factory()->post->create(
			[
				'post_type'  => 'faq',
				'post_title' => 'Return policy',
			]
		);
		$saved = $this->store->save_turn( null, 'Can I return?', 'Yes [ID:' . $faq . ']', [ $faq ] );

		$conv = $this->store->get_conversation( $saved['uuid'] );
		$this->assertIsArray( $conv );
		$this->assertSame( 'Can I return?', $conv['title'] );
		$this->assertCount( 2, $conv['turns'] );
		// Assistant turn is enriched with resolved sources.
		$this->assertSame( $faq, $conv['turns'][1]['sources'][0]['id'] );
		$this->assertSame( 'Return policy', $conv['turns'][1]['sources'][0]['title'] );

		$this->assertNull( $this->store->get_conversation( 'no-such-uuid' ) );
		$this->assertNull( $this->store->get_conversation( '' ) );
	}

	/**
	 * Stored content is sanitized (script tags removed from the question).
	 */
	public function test_question_sanitized() {
		$saved = $this->store->save_turn( null, '<script>alert(1)</script>Hello', 'Answer', [] );
		$post  = get_post( $saved['post_id'] );
		$this->assertStringNotContainsString( '<script>', $post->post_title );
		$this->assertStringContainsString( 'Hello', $post->post_title );
	}
}
