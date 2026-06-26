# Hamelp

Contributors: tarosky, hametuha, Takahashi_Fumiki    
Tags: faq,help  
Tested up to: 7.0  
Stable Tag: 2.2.3  
License: GPL 3.0 or later  
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI powered FAQ and Help Document Management Plugin for WordPress. 

## Description

This plugin add new custom post type 'FAQ'. With some functionality, you can build help center for your user.
What is help center? We collect examples at our [github wiki](https://github.com/tarosky/hamelp/wiki).

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

You can configure AI behavior and rate limiting from **Settings > Hamelp** in the admin panel. The settings page also includes a **Rebuild Catalog Now** button to manually refresh the FAQ catalog used as LLM context.

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

### 2.3.0

- Change ownership to Tarosky.
- AI Overview now supports **multi-turn conversations**. Follow-up questions keep the previous exchanges as context, and answers stack as a Q&A thread. Conversation history is held in the browser and sent with each request, so nothing is stored on the server.
- Add `hamelp_history_window` filter to limit how many prior messages are sent to the LLM (default 10).
- Optionally **save conversations** for question mining (off by default). When enabled on the settings page, conversations are stored as a private post type viewable in the admin, so you can see what visitors actually ask. Toggle via the **Save Conversations** setting or the `hamelp_save_conversations` filter.
- Add an **Auto-delete After (days)** retention setting. A daily cron removes anonymous conversations older than the configured number of days (0 = never delete). Conversations from logged-in users are never auto-deleted.
- Add an AI Overview **Mode** setting: *Conversation* (multi-turn, default), *Single answer* (no follow-up, lower cost), or *Disabled*. Use Single/Disabled to cut cost or stop the feature during a request flood. Also available via the `hamelp_ai_overview_mode` filter.

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
