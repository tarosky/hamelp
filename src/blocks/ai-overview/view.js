/**
 * AI Overview Block Frontend Script
 *
 * Stateless multi-turn conversation: the prior turns are kept in memory on the
 * client and sent with every request as `history`. Nothing is persisted here
 * (history saving is a separate feature). The thread stacks question/answer
 * pairs; the form below stays available for follow-up questions.
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
	const input = container.querySelector( 'input' );
	const thread = container.querySelector( '.hamelp-ai-overview__thread' );

	if ( ! form || ! input || ! thread ) {
		return;
	}

	const button = container.querySelector( 'button' );
	const showSources = container.dataset.showSources === 'true';

	// In-memory conversation history: [{ role: 'user'|'assistant', content }].
	const history = [];

	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		const query = input.value.trim();
		if ( ! query ) {
			return;
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
				data: { query, history: sentHistory },
			} );

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
