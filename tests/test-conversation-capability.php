<?php
/**
 * Conversation access control tests.
 *
 * @package Hamelp
 */

use Hametuha\Hamelp\Hooks\ConversationHistory;
use Hametuha\Hamelp\Services\ConversationStore;

/**
 * Stored conversations are visible only to Editors and above by default.
 */
class ConversationCapabilityTest extends WP_UnitTestCase {

	/**
	 * Conversation post ID asked by an anonymous visitor.
	 *
	 * @var int
	 */
	protected $post_id = 0;

	/**
	 * Register the post type and save a conversation.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->register();
		wp_set_current_user( 0 );
		$saved         = ( new ConversationStore() )->save_turn( null, 'Private question', 'Answer', [] );
		$this->post_id = $saved['post_id'];
	}

	/**
	 * Restore the default registration.
	 */
	public function tearDown(): void {
		remove_all_filters( 'hamelp_conversation_capability' );
		$this->register();
		parent::tearDown();
	}

	/**
	 * (Re-)register the post type so that filters are applied.
	 */
	protected function register() {
		unregister_post_type( ConversationStore::POST_TYPE );
		ConversationHistory::get()->register_post_type();
	}

	/**
	 * Whether the user can open the admin list and the conversation itself.
	 *
	 * @param string $role Role name.
	 * @return bool[] [ can list, can read, can edit ]
	 */
	protected function access_of( $role ) {
		$user      = self::factory()->user->create( [ 'role' => $role ] );
		$post_type = get_post_type_object( ConversationStore::POST_TYPE );
		return [
			user_can( $user, $post_type->cap->edit_posts ),
			user_can( $user, 'read_post', $this->post_id ),
			user_can( $user, 'edit_post', $this->post_id ),
		];
	}

	/**
	 * Roles below Editor cannot list or read conversations.
	 */
	public function test_writers_cannot_access() {
		foreach ( [ 'subscriber', 'contributor', 'author' ] as $role ) {
			$this->assertSame( [ false, false, false ], $this->access_of( $role ), $role );
		}
	}

	/**
	 * Editors and Administrators can access conversations.
	 */
	public function test_editors_can_access() {
		foreach ( [ 'editor', 'administrator' ] as $role ) {
			$this->assertSame( [ true, true, true ], $this->access_of( $role ), $role );
		}
	}

	/**
	 * Nobody can create a conversation by hand.
	 */
	public function test_nobody_can_create() {
		$admin     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$post_type = get_post_type_object( ConversationStore::POST_TYPE );
		$this->assertFalse( user_can( $admin, $post_type->cap->create_posts ) );
	}

	/**
	 * The required capability can be changed by filter.
	 */
	public function test_capability_filter() {
		add_filter( 'hamelp_conversation_capability', function () {
			return 'manage_options';
		} );
		$this->register();
		$this->assertSame( [ false, false, false ], $this->access_of( 'editor' ) );
		$this->assertSame( [ true, true, true ], $this->access_of( 'administrator' ) );
	}
}
