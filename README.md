# PubPla AI Help Center

Contributors: tarosky, hametuha, Takahashi_Fumiki, bissy    
Tags: faq,help  
Tested up to: 7.0  
Stable Tag: 2.4.3  
License: GPL 3.0 or later  
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI powered FAQ and Help Document Management Plugin for WordPress. 

## Description

This plugin add new custom post type 'FAQ'. With some functionality, you can build help center for your user.
What is help center? We collect examples at our [github wiki](https://github.com/tarosky/hamelp/wiki).

**Live demo:** https://demo.kunoichiwp.com/pubplafaq/ — Try the AI Overview on the front page. The demo is in Japanese, built around a fictional anti-aging subscription "Jovian", but you can still get a feel for how the AI answers real questions from your FAQ content (and admits when something isn't covered).

[![WordPress Plugin Test](https://github.com/tarosky/hamelp/actions/workflows/test.yml/badge.svg)](https://github.com/tarosky/hamelp/actions/workflows/test.yml)

### Creating Portal

This plugin will provide...

-   Custom post type with single page and archive page.
-   Custom taxonomy associated to CPT.
-   Incremental search box.
-   AI Overview(Since 2.0.0)

### AI Overview

AI Overview answers user questions based on your FAQ content using a large language model.
It uses the [wp-ai-client](https://github.com/WordPress/wp-ai-client) bundled with WordPress core since WordPress 7.0, which requires an AI service to be configured in WordPress.

**Requirements:** AI Overview requires **WordPress 7.0 or later**. On older WordPress versions, the AI Overview block and template function will still appear in the editor and on the front-end, but the search form will not work (the REST endpoint that powers it is disabled). Other features of this plugin (FAQ custom post type, incremental search, shortcode) continue to work on WordPress 6.6+. Upgrade WordPress to 7.0 to enable AI Overview.

You can configure AI behavior and rate limiting from **Settings > Help Center** in the admin panel. The settings page also includes a **Rebuild Catalog Now** button to manually refresh the FAQ catalog used as LLM context.

#### The Catalog

The catalog is the set of posts handed to the LLM as context. It is the union of several queries, merged and deduplicated by post ID:

1.  Every **published** post of a supported post type. These can be cited, so they appear under **Sources** in an answer.
2.  Every **private** post assigned to the default term of the **AI Catalog** taxonomy. These are *background material*: their text is used to answer everyone, but they are never cited and never linked. Use this for internal knowledge you do not want as a standalone public page.

To add sources of your own — other post types, a taxonomy filter, a meta condition — use the `hamelp_catalog_sources` filter. Each source is a `get_posts()` argument array plus a `citable` flag; earlier sources win on duplicates.

```php
add_filter( 'hamelp_catalog_sources', function ( $sources ) {
	$sources['pages'] = [
		'args'    => [
			'post_type'   => [ 'page' ],
			'post_status' => [ 'publish' ],
		],
		'citable' => true,
	];
	return $sources;
} );
```

#### Using the Block

Add the **AI FAQ Overview** block in the block editor. The block has the following options:

-   **Placeholder** — Input placeholder text.
-   **Button Text** — Submit button label.
-   **Show Sources** — Display related FAQ links below the answer.

#### Using the Template Function

You can also use `hamelp_render_ai_overview()` in your theme templates:

<pre>
&lt;?php echo hamelp_render_ai_overview(); ?&gt;
</pre>

The function accepts an optional array of arguments:

<pre>
&lt;php
echo hamelp_render_ai_overview( [
    'placeholder'  => 'Ask a question...',
    'button_text'  => 'Ask AI',
    'show_sources' => true,
] );
?&gt;
</pre>

The function automatically enqueues the required JavaScript and CSS assets.

### Search Box

The incremental FAQ search box is available in three forms.

#### Using the Block

Add the **FAQ Search Box** block in the block editor. The block has the following options:

-   **Label** — Input placeholder text.
-   **Button Text** — Submit button label.

#### Using the Shortcode

You can use shortcode `hamelp-search` in page content.

<pre>
[hamelp-search label='Enter your question here.'][/hamelp-search]
</pre>

#### Using the Template Function

You can also call `hamelp_render_search_box()` directly from your theme templates:

<pre>
&lt;?php echo hamelp_render_search_box( [
    'label' => 'Enter your question here.',
    'btn'   => 'Search',
] ); ?&gt;
</pre>

## Installation

Install itself is easy. Auto install from admin panel is recommended. Search with `hamelp`.

1. Donwload and unpack plugin file, upload `hamelp` folder to `/wp-content/plugins` directory.
2. Activate it from admin panel.

## Frequently Asked Questions

### How can I contribute?

You can contribute to our github repo. Any [issues](https://github.com/tarosky/hamelp/issues) or [PRs](https://github.com/tarosky/hamelp/pulls) are welcomed.

## Changelog

For full release notes of each version, see [GitHub Releases](https://github.com/tarosky/hamelp/releases).

### 2.6.0

- Add a **Copy answer** button under every AI Overview answer. It copies the answer exactly as displayed — citation labels and the source list included — so an answer can be pasted into a ticket or a review sheet without reformatting. On plain `http` sites, where the browser withholds the clipboard API, it falls back to the legacy copy path instead of failing silently.
- Block text defaults are now translatable: the question placeholder, the submit button label and the helper text below the input. `block.json` attribute defaults are never translated by WordPress — only the keys listed in the core block i18n schema are — so those defaults were served verbatim in every locale. A Japanese site saw the English `Enter your question...`, and an English site saw Japanese helper text. The defaults now resolve through the translation files when the block is rendered.
- **Note:** the helper text below the input became a new English source string in this release, so it stays in English until its translation lands on translate.wordpress.org. Type the text on the block itself if you need it in your language right away.
- **Note:** if you had deliberately typed a value that matched the old default exactly, WordPress never stored it, so that field now shows the translated default instead. Any text that differs from the old default is untouched.

### 2.5.0

- The AI Overview catalog is now built from **multiple queries** instead of one, merged and deduplicated by post ID. A single query could not express "published OR (private AND opted in)", because `post_status` is always ANDed with the taxonomy and meta conditions.
- Add the **AI Catalog** taxonomy. A **private** post assigned to its default term joins the catalog as *background material*: its text is used to answer every visitor, but it is never cited and never linked. Use it for internal knowledge you do not want to publish as a standalone page. Published posts stay in the catalog automatically and are unaffected.
- Add the `hamelp_catalog_sources` filter so themes and plugins can add their own catalog sources — other post types, a taxonomy filter, a meta condition — each with a `citable` flag.
- Fix citations pointing at the wrong page. When an answer drew on material that cannot be linked, the model would cite a topically similar published FAQ that did not actually contain the answer. An answer may now only reference sources that are really shown, and every other reference is removed from the answer text (this also removes the raw `[ID:123]` markers that a model occasionally invented).
- Fix `Private:` appearing in front of a non-published post's title in the LLM context.
- Rebuild the catalog when a post's **status** changes, and when its AI Catalog terms change.

### 2.4.3

- Fix the AI Overview send button appearing as a horizontal ellipse on themes that set a button min-width. The button's width is now constrained (complementing the height fix in 2.4.2) so it stays a circle regardless of theme styles.

### 2.4.2

- Fix the AI Overview send button rendering as an oversized rectangle (with the arrow sometimes hidden) on themes whose button styles overrode the plugin CSS. The button's structural styles are now scoped so theme styles can no longer override its size and shape.

### 2.4.1

- Redesign the AI Overview question box as a ChatGPT-style auto-growing **textarea** with a circular send button inset at the bottom-right. Submit with **Enter** (Shift+Enter for a newline), with IME composition handled. Adds accessible labels and focus handling, a helper hint, and a responsive full-width layout.

### 2.4.0

- Add an **AI Model** setting to pin the provider/model used for AI Overview. Only configured connectors (WordPress 7.0 Settings → Connectors) are listed, and if the chosen model later becomes unavailable it falls back to auto-selection instead of failing.
- Change the default **temperature** to omitted. Some models (e.g. Claude Opus/Sonnet) reject a temperature and returned a 400 error when auto-selected; omitting is safe for every model. Set a value on the settings page only if you need it.
- Add `hamelp_ai_model` and `hamelp_ai_temperature` filters to override the model and temperature from code.
- Improve source citations: rename the "Related FAQs" heading to **Sources**, and render the list as a numbered (ordered) list so its numbers line up with the inline `(Ref. N)` citations in the answer.

### 2.3.1

- User can change the reference prefix (Ref. 1).

### 2.3.0

- Change ownership to Tarosky.
- Rename the plugin to **PubPla AI Help Center**. The plugin slug, text domain, REST routes, and PHP APIs stay `hamelp` for backward compatibility, so no configuration or code changes are required.
- AI Overview now supports **multi-turn conversations**. Follow-up questions keep the previous exchanges as context, and answers stack as a Q&A thread. Conversation history is held in the browser and sent with each request, so nothing is stored on the server.
- Add `hamelp_history_window` filter to limit how many prior messages are sent to the LLM (default 10).
- Optionally **save conversations** for question mining (off by default). When enabled on the settings page, conversations are stored as a private post type viewable in the admin, so you can see what visitors actually ask. Toggle via the **Save Conversations** setting or the `hamelp_save_conversations` filter.
- Add an **Auto-delete After (days)** retention setting. A daily cron removes anonymous conversations older than the configured number of days (0 = never delete). Conversations from logged-in users are never auto-deleted.
- Add an AI Overview **Mode** setting: *Conversation* (multi-turn, default), *Single answer* (no follow-up, lower cost), or *Disabled*. Use Single/Disabled to cut cost or stop the feature during a request flood. Also available via the `hamelp_ai_overview_mode` filter.
- The front-end now reflects the mode: *Single answer* replaces the previous answer on each question, and *Conversation* shows a "Continue the previous conversation" toggle so visitors can either keep asking follow-ups or start a fresh conversation (which clears the thread).

### 2.2.3

- Change CSS structure `--wp--preset--color--*` to fit with Theme design. Thank you [bissy](https://profiles.wordpress.org/bissy/) for Pull requests.

### 2.2.2

- Add **FAQ Search Box** block (`hamelp/search-box`). The existing `[hamelp-search]` shortcode continues to work and now shares the same render logic.
- Expose `hamelp_render_search_box()` as a public template function so themes can render the search box without going through the shortcode parser.

### 2.2.0

- Remove bundled [wp-ai-client](https://github.com/WordPress/wp-ai-client) Composer dependency. AI Overview now uses the wp-ai-client bundled with WordPress core, which requires **WordPress 7.0 or later**.
- On WordPress versions earlier than 7.0, the AI Overview block and search form still render but submissions fail (no REST route). FAQ custom post type, incremental search, and other features remain functional.
- Auto-rebuild the FAQ catalog on plugin activation, so the AI Overview works out of the box without manually running `wp hamelp rebuild`.
- Add a **Rebuild Catalog Now** button to the settings page for manual catalog refresh.

### 2.1.0

-   Add user context to AI Overview for personalized responses.
-   Add whitelist-based user role filtering for security (`hamelp_allowed_user_roles` filter).
-   Add `hamelp_user_context` and `hamelp_display_user_roles` filters for customization.
-   Add development hooks support for local environment testing.
-   Remove bundled translations in favor of GlotPress (WordPress.org).

### 2.0.0

-   Add AI Overview Feature.
-   Bump minimum requirements: PHP >=7.4, WordPress >= 6.6

### 1.0.4

-   Add [structured data](https://developers.google.com/search/docs/data-types/faqpage) for FAQPage.

### 1.0.3

-   Bugfix and change glocal functions.

### 1.0.2

-   Fix taxonomy to be shown in Gutenberg.

### 1.0.1

-   Fix no vendor directory bug.

### 1.0.0

-   Initial release.
