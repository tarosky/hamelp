/**
 * AI Overview Block Frontend Script
 *
 * Behavior depends on the mode set on the container (`data-mode`):
 * - `conversation`: prior turns are kept in memory and sent as `history` so the
 *   answer has context. A "Continue the previous conversation" checkbox lets the
 *   visitor either keep asking follow-ups or start fresh (which clears the
 *   thread and starts a new conversation record).
 * - `single`: each question is independent. The thread is cleared on every
 *   submit so only the latest question and answer are shown (no context).
 *
 * Nothing is persisted client-side here (history saving is a separate feature).
 *
 * @package
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { parseMarkdown, replaceIdReferences } from './utils';

/**
 * Render the sources list for a single answer.
 *
 * @param {Array} sources Array of { id, title, url } objects.
 * @return {string} HTML string (empty when there are no sources).
 */
function renderSources( sources ) {
	if ( ! sources?.length ) {
		return '';
	}
	let html =
		'<div class="hamelp-ai-overview__sources"><p>' +
		__( 'Related FAQs:', 'hamelp' ) +
		'</p><ul>';
	sources.forEach( ( source ) => {
		html += `<li><a href="${ source.url }">${ source.title }</a></li>`;
	} );
	html += '</ul></div>';
	return html;
}

document.querySelectorAll( '.hamelp-ai-overview' ).forEach( ( container ) => {
	const form = container.querySelector( 'form' );
	// Select the text input specifically: the form may also contain the
	// "continue" checkbox, so a bare `input` selector would match the wrong one.
	const input = container.querySelector( '.hamelp-ai-overview__input' );
	const thread = container.querySelector( '.hamelp-ai-overview__thread' );

	if ( ! form || ! input || ! thread ) {
		return;
	}

	const button = container.querySelector( 'button' );
	const showSources = container.dataset.showSources === 'true';
	const mode = container.dataset.mode || 'conversation';
	const continueLabel = container.querySelector(
		'.hamelp-ai-overview__continue'
	);
	const continueToggle = container.querySelector(
		'.hamelp-ai-overview__continue-toggle'
	);

	// In-memory conversation history: [{ role: 'user'|'assistant', content }].
	const history = [];
	// Server-issued conversation id (only when saving is enabled). Sent back on
	// follow-up turns so the whole conversation is appended to one record.
	let conversationId = '';

	// The "continue" toggle is only meaningful once there is something to
	// continue, so it stays hidden until the first exchange exists.
	const updateContinueVisibility = () => {
		if ( continueLabel ) {
			continueLabel.hidden = history.length === 0;
		}
	};

	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		const query = input.value.trim();
		if ( ! query ) {
			return;
		}

		// Decide whether this submit continues the current conversation or starts
		// a new one. Single mode is always independent; conversation mode follows
		// the toggle (and the first question always starts fresh).
		const isContinue =
			mode === 'conversation' &&
			!! continueToggle?.checked &&
			history.length > 0;

		if ( ! isContinue ) {
			// Start fresh: clear the visible thread and reset the conversation.
			thread.innerHTML = '';
			history.length = 0;
			conversationId = '';
			updateContinueVisibility();
		}

		// Snapshot history to send (prior turns only, not the new question).
		const sentHistory = history.slice();

		// Append a new turn (question + pending answer) to the thread.
		const turn = document.createElement( 'div' );
		turn.className = 'hamelp-ai-overview__turn is-loading';
		const questionEl = document.createElement( 'div' );
		questionEl.className = 'hamelp-ai-overview__question';
		questionEl.textContent = query;
		const answerEl = document.createElement( 'div' );
		answerEl.className = 'hamelp-ai-overview__answer';
		answerEl.innerHTML =
			'<span class="spinner"></span> ' +
			__( 'Generating answer…', 'hamelp' );
		turn.appendChild( questionEl );
		turn.appendChild( answerEl );
		thread.appendChild( turn );

		input.value = '';
		button.disabled = true;
		turn.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );

		try {
			const data = await apiFetch( {
				path: '/hamelp/v1/ai-overview',
				method: 'POST',
				data: {
					query,
					history: sentHistory,
					conversation_id: conversationId,
				},
			} );

			// Remember the conversation id so later turns append to the same record.
			if ( data.conversation_id ) {
				conversationId = data.conversation_id;
			}

			// Parse markdown, then replace [ID:xxx] with source links.
			const parsedAnswer = replaceIdReferences(
				parseMarkdown( data.answer ),
				data.sources
			);
			let html = parsedAnswer;
			if ( showSources ) {
				html += renderSources( data.sources );
			}
			answerEl.innerHTML = html;
			turn.classList.remove( 'is-loading' );
			turn.classList.add( 'has-result' );

			// Record the completed exchange for the next turn's context.
			history.push( { role: 'user', content: query } );
			history.push( { role: 'assistant', content: data.answer } );
			updateContinueVisibility();
		} catch ( err ) {
			answerEl.innerHTML = `<div class="hamelp-ai-overview__error">${
				err.message || __( 'An error occurred.', 'hamelp' )
			}</div>`;
			turn.classList.remove( 'is-loading' );
			turn.classList.add( 'has-error' );
			// On failure the exchange is not added to history, so the user can retry.
		} finally {
			button.disabled = false;
		}
	} );
} );
