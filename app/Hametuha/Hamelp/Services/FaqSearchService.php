<?php
/**
 * FAQ Search Service for AI Overview.
 *
 * @package hamelp
 */

namespace Hametuha\Hamelp\Services;

use Hametuha\Hamelp\Hooks\PostType;
use Hametuha\Hamelp\Hooks\Settings;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;

/**
 * Service class for FAQ catalog-based AI response generation.
 */
class FaqSearchService {


	/**
	 * Generate AI overview response based on user query.
	 *
	 * Uses a catalog of all FAQs as context, letting the LLM
	 * select relevant FAQs and generate a response in one call.
	 *
	 * The conversation is stateless: prior turns are supplied by the client
	 * via $history on every request and are not persisted server-side.
	 *
	 * @param string  $query   User's question (the new turn).
	 * @param array[] $history Prior conversation turns, each `['role' => 'user'|'assistant', 'content' => string]`.
	 * @return array|\WP_Error Response with answer and sources, or error.
	 */
	public function generate_overview( string $query, array $history = [] ) {
		$builder = new FaqCatalogBuilder();
		$catalog = $builder->get_accessible_catalog();

		// No FAQs available.
		if ( empty( $catalog ) ) {
			return [
				'answer'    => __( 'No FAQ content is available at this time.', 'hamelp' ),
				'sources'   => [],
				'cited_ids' => [],
			];
		}

		/**
		 * Threshold for switching between full-dump and catalog mode.
		 *
		 * When FAQ count is at or below this number, full content is included.
		 * Above this number, only title + excerpt (300 chars) is used.
		 *
		 * @param int $threshold Default 30.
		 */
		$threshold = apply_filters( 'hamelp_full_dump_threshold', 30 );

		if ( count( $catalog ) <= $threshold ) {
			$context = $this->build_full_context( $catalog );
		} else {
			$context = $this->build_catalog_context( $catalog );
		}

		$system_prompt = $this->get_system_prompt( $context );

		// Build the message list: prior turns (windowed/sanitized) + the new question.
		// The whole FAQ catalog stays in the system instruction, so a long
		// conversation grows only by the history window, not the catalog.
		$messages = [];
		foreach ( $this->prepare_history( $history ) as $turn ) {
			$part       = new MessagePart( $turn['content'] );
			$messages[] = ( 'assistant' === $turn['role'] )
				? new ModelMessage( [ $part ] )
				: new UserMessage( [ $part ] );
		}
		$messages[] = new UserMessage( [ new MessagePart( $query ) ] );

		/**
		 * Filter the HTTP timeout (seconds) for FAQ overview generation.
		 *
		 * The whole FAQ catalog is sent as context, so a large site can exceed
		 * the AI client's 30-second default. Scoped to Hamelp's own request only.
		 *
		 * @param float $timeout Timeout in seconds. Default 60.
		 */
		$timeout = static function () {
			return (float) apply_filters( 'hamelp_ai_request_timeout', 60 );
		};
		// The AI client reads the default timeout in the builder's constructor.
		add_filter( 'wp_ai_client_default_request_timeout', $timeout );
		$prompt = wp_ai_client_prompt( $messages )
			->using_system_instruction( $system_prompt );
		remove_filter( 'wp_ai_client_default_request_timeout', $timeout );

		/**
		 * Filter the preferred AI model for FAQ overview generation.
		 *
		 * The default is resolved from the Hamelp settings screen and validated
		 * against the live provider registry, so a selection whose connector was
		 * disabled outside Hamelp falls back to auto-selection. Return a value to
		 * override. Accepted forms mirror the AI client's model preference API:
		 *
		 * - a model ID string, e.g. `'gemini-2.5-flash'`
		 * - a `[ provider_id, model_id ]` pair, e.g. `[ 'anthropic', 'claude-opus-4-1' ]`
		 *
		 * @param string|array|null $model Preferred model. Null auto-selects.
		 */
		$model = apply_filters( 'hamelp_ai_model', AiModelResolver::get_effective_model_preference() );
		if ( ! empty( $model ) ) {
			$prompt = $prompt->using_model_preference( $model );
		}

		/**
		 * Filter the sampling temperature for FAQ overview generation.
		 *
		 * The default is resolved from the Hamelp settings screen. Return `null`
		 * to omit the temperature entirely. This is required for models that
		 * reject the parameter (e.g. Claude Opus responds with a 400 error when a
		 * temperature is supplied).
		 *
		 * @param float|null $temperature Sampling temperature. Null omits it.
		 */
		$temperature = apply_filters( 'hamelp_ai_temperature', AiModelResolver::get_effective_temperature() );
		if ( null !== $temperature ) {
			$prompt = $prompt->using_temperature( (float) $temperature );
		}

		$response = $prompt
			->as_json_response( self::response_schema() )
			->generate_text();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = self::decode_json_response( $response );
		if ( null === $data ) {
			// JSON parse failed: treat response as plain text.
			return [
				'answer'    => self::normalize_answer_text( $response ),
				'sources'   => [],
				'cited_ids' => [],
			];
		}

		// Build sources from cited IDs. Restricted to the accessible catalog and
		// to citable entries, so neither an inaccessible FAQ nor a non-published
		// one is surfaced even if the model cites it anyway.
		$cited   = $data['cited_ids'] ?? [];
		$sources = [];
		foreach ( $catalog as $item ) {
			if ( ! FaqCatalogBuilder::is_citable( $item ) ) {
				continue;
			}
			if ( in_array( $item['id'], $cited, true ) ) {
				$sources[] = [
					'id'    => $item['id'],
					'title' => $item['title'],
					'url'   => get_permalink( $item['id'] ),
				];
			}
		}

		$source_ids = array_map( 'intval', wp_list_pluck( $sources, 'id' ) );
		return [
			'answer'    => self::strip_uncited_references(
				self::normalize_answer_text( (string) $data['answer'] ),
				$source_ids
			),
			'sources'   => $sources,
			'cited_ids' => $source_ids,
		];
	}

	/**
	 * JSON schema constraining the model's structured output.
	 *
	 * Passed to `as_json_response()` so providers that support structured output
	 * enforce the exact `{ answer, cited_ids }` shape. `additionalProperties` is
	 * explicitly false because some providers (e.g. Anthropic) reject object
	 * schemas without it.
	 *
	 * @return array<string, mixed>
	 */
	protected static function response_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'answer'    => [ 'type' => 'string' ],
				'cited_ids' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
			],
			'required'             => [ 'answer', 'cited_ids' ],
		];
	}

	/**
	 * Decode the model's JSON response, unwrapping double-encoded output.
	 *
	 * Even with a response schema, some models occasionally return the whole
	 * `{ answer, cited_ids }` object as a JSON string nested inside the `answer`
	 * field. This unwraps up to a few levels so the real answer and citations
	 * are recovered instead of leaking raw JSON to the user with empty sources.
	 *
	 * @param string $response Raw text returned by the model.
	 * @return array|null Decoded `{ answer, cited_ids }` array, or null if unusable.
	 */
	public static function decode_json_response( string $response ): ?array {
		$data  = json_decode( $response, true );
		$depth = 0;
		while (
			is_array( $data )
			&& isset( $data['answer'] )
			&& is_string( $data['answer'] )
			&& $depth < 3
		) {
			$trimmed = trim( $data['answer'] );
			if ( '' === $trimmed || '{' !== $trimmed[0] ) {
				break;
			}
			$inner = json_decode( $trimmed, true );
			if ( ! is_array( $inner ) || ! isset( $inner['answer'] ) ) {
				break;
			}
			$data = $inner;
			++$depth;
		}

		if ( ! is_array( $data ) || ! isset( $data['answer'] ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Normalize literal escape sequences in answer text into real characters.
	 *
	 * Some models double-escape newlines in their structured JSON output, so a
	 * single `json_decode()` leaves the two-character sequence `\n` (backslash +
	 * n) in the answer instead of a real line break. The frontend renderer only
	 * understands real newline characters, so those literals leak into the
	 * rendered answer as raw text. This converts the common literal sequences
	 * back into the real characters they represent.
	 *
	 * A `strtr()` map is used so replacement is single-pass and order-independent
	 * (the longer `\r\n` sequence is matched before the shorter `\r`/`\n`).
	 *
	 * @param string $text Answer text possibly containing literal escape sequences.
	 * @return string Text with literal `\r\n`, `\n`, `\r` and `\t` converted.
	 */
	public static function normalize_answer_text( string $text ): string {
		return strtr(
			$text,
			[
				'\\r\\n' => "\n",
				'\\n'    => "\n",
				'\\r'    => "\n",
				'\\t'    => "\t",
			]
		);
	}

	/**
	 * Resolve cited FAQ IDs into source link data.
	 *
	 * Used when rendering a stored conversation, where the in-memory catalog
	 * is not available. Each ID is resolved to its current title and permalink.
	 *
	 * Only published posts are resolved: a post that has since been unpublished
	 * (or that was never public and only fed the answer as background material)
	 * must not leak its title or a permalink that would 404.
	 *
	 * @param int[] $cited_ids FAQ post IDs.
	 * @return array[] List of `['id' => int, 'title' => string, 'url' => string]`.
	 */
	public function resolve_sources( array $cited_ids ): array {
		$sources = [];
		foreach ( $cited_ids as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			if ( ! PostType::get()->is_supported( $post->post_type ) ) {
				continue;
			}
			$sources[] = [
				'id'    => $id,
				'title' => get_the_title( $post ),
				'url'   => (string) get_permalink( $post ),
			];
		}
		return $sources;
	}

	/**
	 * Normalize, sanitize and window a client-supplied conversation history.
	 *
	 * The history is sent by the client on every request (stateless server), so
	 * it must be treated as untrusted: only `user`/`assistant` roles are kept,
	 * content is sanitized, empty turns are dropped, and the list is trimmed to
	 * the most recent N entries to bound token cost.
	 *
	 * @param array $history Raw history from the request.
	 * @return array[] Normalized turns, each `['role' => 'user'|'assistant', 'content' => string]`.
	 */
	public function prepare_history( array $history ): array {
		$normalized = [];
		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) || ! isset( $turn['role'], $turn['content'] ) ) {
				continue;
			}
			$role = (string) $turn['role'];
			if ( ! in_array( $role, [ 'user', 'assistant' ], true ) ) {
				continue;
			}
			$content = sanitize_textarea_field( (string) $turn['content'] );
			if ( '' === $content ) {
				continue;
			}
			$normalized[] = [
				'role'    => $role,
				'content' => $content,
			];
		}

		/**
		 * Maximum number of prior conversation turns (messages) sent to the LLM.
		 *
		 * Limits token cost on long conversations. Counts individual messages,
		 * not exchanges (a Q + A pair is 2).
		 *
		 * @param int $window Default 10.
		 */
		$window = (int) apply_filters( 'hamelp_history_window', 10 );
		if ( $window > 0 && count( $normalized ) > $window ) {
			$normalized = array_slice( $normalized, -$window );
		}

		return $normalized;
	}

	/**
	 * Build full-content context for small FAQ sets.
	 *
	 * @param array[] $catalog FAQ catalog entries.
	 * @return string Context string for LLM.
	 */
	protected function build_full_context( array $catalog ): string {
		return $this->build_context( $catalog, 'content', 'Available FAQs (full content):' );
	}

	/**
	 * Build catalog context with title + excerpt for large FAQ sets.
	 *
	 * @param array[] $catalog FAQ catalog entries.
	 * @return string Context string for LLM.
	 */
	protected function build_catalog_context( array $catalog ): string {
		return $this->build_context( $catalog, 'excerpt', 'Available FAQs (title + summary):' );
	}

	/**
	 * Render the catalog as LLM context.
	 *
	 * Every entry gets an ID, background material included. Hiding the ID of a
	 * non-citable entry does not remove the model's urge to cite something: it
	 * displaces the citation onto a topically similar public FAQ that does not
	 * actually contain the answer, which is worse than no citation at all. So
	 * the model reports what it really used, and the server decides what to
	 * show -- see `strip_uncited_references()`.
	 *
	 * @param array[] $catalog FAQ catalog entries.
	 * @param string  $field   Entry field to render, `content` or `excerpt`.
	 * @param string  $heading Heading for the citable section.
	 * @return string Context string for LLM.
	 */
	protected function build_context( array $catalog, string $field, string $heading ): string {
		$citable    = [ $heading ];
		$background = [];
		foreach ( $catalog as $item ) {
			$entry = sprintf( "\n[ID:%d] %s", $item['id'], $item['title'] );
			if ( ! empty( $item['category'] ) ) {
				$entry .= sprintf( ' (Category: %s)', $item['category'] );
			}
			$entry .= "\n" . ( $item[ $field ] ?? '' );
			if ( FaqCatalogBuilder::is_citable( $item ) ) {
				$citable[] = $entry;
			} else {
				$background[] = $entry;
			}
		}
		if ( $background ) {
			array_unshift( $background, 'Background material. Use it to answer and cite it by ID exactly like any other entry. It is not publicly linkable, so the system removes those citations before display -- that is expected, and it is never a reason to cite a different FAQ instead:' );
			$citable[] = "\n" . implode( "\n", $background );
		}
		return implode( "\n", $citable );
	}

	/**
	 * Remove `[ID:n]` markers the answer is not allowed to reference.
	 *
	 * Enforces one invariant: the answer text may only reference IDs that
	 * survived into `sources`. That covers background material the model
	 * legitimately cited, entries filtered out by access control, and IDs the
	 * model simply made up -- all of which would otherwise be rendered as a raw
	 * `[ID:123]` or, worse, silently point at the wrong page.
	 *
	 * @param string $answer  Answer text from the model.
	 * @param int[]  $allowed IDs present in the final source list.
	 * @return string
	 */
	protected static function strip_uncited_references( string $answer, array $allowed ): string {
		$stripped = preg_replace_callback(
			'/[ \t]*\[ID:\d+(?:,\s*ID:\d+)*\]/u',
			function ( $matches ) use ( $allowed ) {
				preg_match_all( '/ID:(\d+)/', $matches[0], $found );
				$kept = array_values( array_intersect( array_map( 'intval', $found[1] ), $allowed ) );
				if ( ! $kept ) {
					return '';
				}
				return ' [' . implode(
					', ',
					array_map(
						function ( $id ) {
							return 'ID:' . $id;
						},
						$kept
					)
				) . ']';
			},
			$answer
		);
		return is_string( $stripped ) ? $stripped : $answer;
	}

	/**
	 * Get system prompt for LLM.
	 *
	 * @param string $context FAQ context.
	 * @return string System prompt.
	 */
	protected function get_system_prompt( string $context ): string {
		$site_context = Settings::get_site_context();

		$base = 'You are a FAQ support assistant.';
		if ( ! empty( $site_context ) ) {
			$base .= "\n\n" . $site_context;
		}

		$user_context = $this->get_user_context();
		if ( ! empty( $user_context ) ) {
			$base .= "\n\n" . $user_context;
		}

		$base .= "\n\n" . 'Answer user questions based on the provided FAQ content.

IMPORTANT RULES:
- Select the most relevant FAQs from the provided list and answer the question.
- In your answer, refer to FAQs by their ID in the format [ID:42].
- DO NOT include a separate "Related FAQ" or "参考FAQ" or "関連FAQ" section at the end.
- The system will automatically display FAQ links based on the cited_ids you return.
- Only include IDs of FAQs you actually reference in cited_ids.
- NEVER cite an FAQ that does not itself contain the information you used. Do not offer a related FAQ as a substitute for a source you cannot link: citing nothing is correct and expected, and an empty cited_ids array is a valid answer.
- If no FAQ is relevant, provide a helpful answer with an empty cited_ids array.
- Keep your response concise and helpful.
- If earlier conversation turns are provided, use them as context and answer follow-up questions accordingly.
- Respond in the same language as the user question.
- If user information is provided, you may address them by name and tailor your response to their context (e.g., role, membership). Do not repeat their personal information back to them.

OUTPUT FORMAT:
You MUST respond with a JSON object containing exactly these fields:
- "answer": (string) Your response text.
- "cited_ids": (array of integers) IDs of FAQs you referenced. Empty array if none.
Example: {"answer": "Your answer here.", "cited_ids": [42, 55]}';

		/**
		 * Filter the AI system prompt.
		 *
		 * @param string $prompt The system prompt.
		 */
		$base = apply_filters( 'hamelp_ai_system_prompt', $base );

		return $base . "\n\n" . $context;
	}

	/**
	 * Get user context string for personalization.
	 *
	 * Returns empty string for logged-out users.
	 *
	 * @return string User context for system prompt, empty if not logged in.
	 */
	protected function get_user_context(): string {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return '';
		}

		$lines   = [ 'Current user information:' ];
		$lines[] = sprintf( 'Display name: %s', $user->display_name );

		// Filter roles to prevent exposing internal plugin roles.
		$display_roles = $this->get_display_roles( $user );
		if ( ! empty( $display_roles ) ) {
			$lines[] = sprintf( 'Role: %s', implode( ', ', $display_roles ) );
		}

		$lines[] = sprintf( 'Registered: %s', $user->user_registered );

		$context = implode( "\n", $lines );

		/**
		 * Filter user context included in the AI system prompt.
		 *
		 * Allows sites to add custom user-specific information
		 * (e.g., subscription status, purchase history, membership tier)
		 * that helps the AI provide personalized answers.
		 *
		 * Return an empty string to disable user personalization entirely.
		 *
		 * @param string   $context The user context string.
		 * @param \WP_User $user    The current WordPress user object.
		 */
		$context = apply_filters( 'hamelp_user_context', $context, $user );

		return is_string( $context ) ? $context : '';
	}

	/**
	 * Get user roles filtered by whitelist for display.
	 *
	 * Filters out internal plugin roles (e.g., backup plugins)
	 * that should not be exposed to the AI.
	 *
	 * @param \WP_User $user The user object.
	 * @return string[] Filtered role names safe for display.
	 */
	protected function get_display_roles( \WP_User $user ): array {
		/**
		 * Filter the list of allowed user roles to display in AI context.
		 *
		 * Only roles in this list will be shown to the AI.
		 * This prevents internal plugin roles from being exposed.
		 *
		 * @param string[] $allowed_roles List of role slugs to allow.
		 */
		$allowed_roles = apply_filters(
			'hamelp_allowed_user_roles',
			[
				// WordPress core roles.
				'administrator',
				'editor',
				'author',
				'contributor',
				'subscriber',
				// WooCommerce roles.
				'customer',
				'shop_manager',
			]
		);

		$display_roles = array_intersect( $user->roles, $allowed_roles );

		/**
		 * Filter the user roles to be displayed in AI context.
		 *
		 * Called after whitelist filtering. Allows further customization
		 * such as translating role slugs to human-readable names.
		 *
		 * @param string[] $display_roles Roles to display (already filtered).
		 * @param \WP_User $user          The user object.
		 */
		return apply_filters( 'hamelp_display_user_roles', $display_roles, $user );
	}
}
