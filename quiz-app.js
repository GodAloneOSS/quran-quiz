/* global QQ_CONFIG, jspdf */
(function () {
	'use strict';

	var CFG = window.QQ_CONFIG || {};
	var ROOT = document.getElementById( 'qq-quiz-root' );
	if ( ! ROOT ) { return; }

	var STORAGE_KEY = 'qq_session_token';

	var state = {
		screen: 'loading',
		token: null,
		user: null,
		attempt: null,   // { attempt_id, difficulty, time_limit_seconds, questions[], answers:{}, index, remaining, timer, startedAt }
		lastResult: null
	};

	/* ------------------------------------------------------------------ */
	/* Text-to-speech — reads a question + its options aloud so visitors    */
	/* who find reading Tamil script difficult (or are listening rather    */
	/* than looking at the screen) can still take the quiz. Uses the       */
	/* browser's own built-in Web Speech API — no account, key, or server  */
	/* cost — so it silently does nothing on the small number of browsers  */
	/* that don't support it (the button hides itself in that case).       */
	/* ------------------------------------------------------------------ */

	var TTS_LANG = 'ta-IN';
	var TTS_AUTO_KEY = 'qq_tts_auto';
	var ttsActiveQid = null;    // question id currently being spoken (or about to be), else null
	var ttsAutoReadQid = null;  // question id already auto-read once, so re-renders (e.g. picking an option) don't repeat it
	var ttsUnlocked = false;    // see ttsUnlock() below

	function ttsSupported() {
		return !!( window.speechSynthesis && window.SpeechSynthesisUtterance );
	}

	// Auto-read is ON by default for every visitor (no stored preference yet)
	// so people don't have to discover/tap the checkbox — they can still turn
	// it off with the checkbox if they'd rather read silently.
	function ttsAutoReadPref() {
		try {
			var v = localStorage.getItem( TTS_AUTO_KEY );
			return v === null ? true : v === '1';
		} catch ( e ) { return true; }
	}

	function ttsSetAutoReadPref( on ) {
		try { localStorage.setItem( TTS_AUTO_KEY, on ? '1' : '0' ); } catch ( e ) { /* private mode etc. — non-fatal */ }
	}

	// Several mobile browsers (Safari/iOS in particular) only allow the first
	// speechSynthesis.speak() call on a page to happen synchronously inside a
	// real tap/click/keydown handler — if the first real question is read
	// automatically *after* an awaited network request (starting the quiz
	// fetches the questions first), the tap that triggered it has already
	// "expired" by the time speak() runs, and the browser silently drops the
	// audio with no error. Fix: fire one silent, zero-volume utterance
	// synchronously inside the very first genuine tap (starting the quiz —
	// see handleGuestContinue() and the difficulty-card click handler below)
	// to unlock the speech engine for the rest of the page's lifetime, before
	// any async gap has a chance to lose that tap's "user activation".
	function ttsUnlock() {
		if ( ! ttsSupported() || ttsUnlocked ) { return; }
		ttsUnlocked = true;
		try {
			window.speechSynthesis.getVoices(); // nudges some browsers to load voices sooner
			var u = new SpeechSynthesisUtterance( '' );
			u.volume = 0;
			window.speechSynthesis.speak( u );
		} catch ( e ) { /* non-fatal — worst case the first auto-read is silent on some phones */ }
	}

	function ttsStop() {
		if ( ttsSupported() ) { window.speechSynthesis.cancel(); }
	}

	function ttsIsSpeaking() {
		return ttsSupported() && window.speechSynthesis.speaking;
	}

	function ttsResetForNewScreen() {
		ttsStop();
		ttsActiveQid = null;
		ttsAutoReadQid = null;
	}

	function ttsRefreshButton() {
		var btn = document.getElementById( 'qq-tts-btn' );
		if ( ! btn ) { return; }
		var active = ttsIsSpeaking();
		btn.classList.toggle( 'speaking', active );
		btn.innerHTML = active ? '⏹' : '🔊';
		btn.setAttribute( 'aria-label', active ? 'படிப்பதை நிறுத்து' : 'கேள்வியைக் கேளுங்கள்' );
	}

	// Fill-in-the-blank questions use runs of underscores (or dashes/dots) as
	// a visual blank, e.g. "Most _____, Most ______". Read literally, the
	// speech engine spells out every single underscore one by one, which is
	// unusable. Collapse any such run into one spoken Tamil word for
	// "blank" instead, so each blank is announced once.
	function ttsCleanText( str ) {
		if ( ! str ) { return ''; }
		return String( str )
			.replace( /_{2,}/g, ' இடைவெளி ' )
			.replace( /-{3,}/g, ' இடைவெளி ' )
			.replace( /\.{4,}/g, ' இடைவெளி ' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function ttsSpeakQuestion( q ) {
		if ( ! ttsSupported() ) { return; }
		var text = ttsCleanText( q.question ) + '. ' + q.options.map( function ( o ) { return o.key + '. ' + ttsCleanText( o.text ); } ).join( '. ' );
		window.speechSynthesis.cancel();
		var u = new SpeechSynthesisUtterance( text );
		u.lang = TTS_LANG;
		u.rate = 0.92;
		ttsActiveQid = q.id;
		u.onend = function () { if ( ttsActiveQid === q.id ) { ttsActiveQid = null; } ttsRefreshButton(); };
		u.onerror = u.onend;
		window.speechSynthesis.speak( u );
		ttsRefreshButton();
	}

	/* ------------------------------------------------------------------ */
	/* Small helpers                                                       */
	/* ------------------------------------------------------------------ */

	function esc( str ) {
		if ( str === null || str === undefined ) { return ''; }
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	// Backend difficulty keys stay in English ('easy'/'medium'/'hard'/'mixed')
	// so the REST API validation keeps working unchanged — only the label the
	// visitor sees is translated.
	var DIFF_LABELS = { easy: 'எளிது', medium: 'நடுத்தரம்', hard: 'கடினம்', mixed: 'கலவை' };
	function diffLabel( key ) {
		return DIFF_LABELS[ key ] || DIFF_LABELS.mixed;
	}

	function fmtTime( totalSeconds ) {
		totalSeconds = Math.max( 0, Math.floor( totalSeconds ) );
		var m = Math.floor( totalSeconds / 60 );
		var s = totalSeconds % 60;
		return ( m < 10 ? '0' + m : m ) + ':' + ( s < 10 ? '0' + s : s );
	}

	function fmtDate( isoLike ) {
		if ( ! isoLike ) { return ''; }
		var d = new Date( isoLike.replace( ' ', 'T' ) );
		if ( isNaN( d.getTime() ) ) { return isoLike; }
		return d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } ) +
			' · ' + d.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } );
	}

	function toast( msg ) {
		var el = document.createElement( 'div' );
		el.className = 'qq-toast';
		el.textContent = msg;
		document.body.appendChild( el );
		requestAnimationFrame( function () { el.classList.add( 'show' ); } );
		setTimeout( function () {
			el.classList.remove( 'show' );
			setTimeout( function () { el.remove(); }, 300 );
		}, 3200 );
	}

	function api( path, options ) {
		options = options || {};
		var headers = options.headers || {};
		if ( state.token ) {
			headers['Authorization'] = 'Bearer ' + state.token;
		}
		if ( options.body && typeof options.body !== 'string' ) {
			headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify( options.body );
		}
		return fetch( CFG.restUrl + path, {
			method: options.method || 'GET',
			headers: headers,
			body: options.body
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( ( data && data.message ) ? data.message : 'ஏதோ தவறு நடந்துவிட்டது.' );
					err.code = data && data.code;
					err.status = res.status;
					throw err;
				}
				return data;
			} );
		} );
	}

	function clearSession() {
		state.token = null;
		state.user = null;
		try { localStorage.removeItem( STORAGE_KEY ); } catch ( e ) {}
	}

	/* ------------------------------------------------------------------ */
	/* Shell / branding                                                    */
	/* ------------------------------------------------------------------ */

	function shell( innerHtml, opts ) {
		opts = opts || {};
		ttsResetForNewScreen(); // leaving the quiz screen (or landing on any non-quiz screen) always stops any reading in progress
		var backBtn = opts.back ? '<button class="qq-back-link" id="qq-back">&larr; ' + esc( opts.back ) + '</button>' : '';
		ROOT.innerHTML =
			'<div class="qq-brand">' +
				'<div class="qq-bismillah">அல்லாஹ்வின் பெயரால், மிகவும் கருணையுள்ளவன், மிகவும் இரக்கமுள்ளவன்</div>' +
				'<h1>' + esc( CFG.siteName || 'Quranic Quiz' ) + ' — குர்ஆன் வினாடி வினா</h1>' +
				'<p>புனிதமான குர்ஆனைப் பற்றிய உங்கள் அறிவை சோதித்து வளர்த்துக் கொள்ளுங்கள்</p>' +
			'</div>' +
			'<div class="qq-card">' + backBtn + innerHtml + '</div>';
		if ( opts.back ) {
			document.getElementById( 'qq-back' ).addEventListener( 'click', opts.onBack );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Screen: Sign in                                                     */
	/* ------------------------------------------------------------------ */

	function renderSignIn() {
		state.screen = 'signin';
		shell(
			'<div class="qq-signin">' +
				'<div class="qq-signin-icon">📖</div>' +
				'<h2 style="margin:0 0 8px;font-size:22px;">ஆரம்பிக்கலாம், இன்ஷா அல்லாஹ்</h2>' +
				'<p style="color:var(--qq-text-dim);font-size:14px;max-width:380px;margin:0 auto;">உங்கள் சான்றிதழிலும் வினாடி வினா வரலாற்றிலும் இடம்பெற உங்கள் பெயரை உள்ளிடவும். கணக்கோ கடவுச்சொல்லோ தேவையில்லை.</p>' +
				'<div class="qq-name-form">' +
					'<input type="text" class="qq-guest-input" id="qq-guest-name" placeholder="உங்கள் பெயர்" maxlength="60" autocomplete="name">' +
					'<button class="qq-btn qq-btn-primary qq-btn-block qq-btn-lg" id="qq-guest-start" type="button">▶ வினாடி வினாவைத் தொடங்கு</button>' +
				'</div>' +
				'<p class="qq-signin-note">உங்கள் முடிவுகள் இந்த உலாவியில் மட்டுமே சேமிக்கப்படும்.</p>' +
			'</div>'
		);

		var guestNameInput = document.getElementById( 'qq-guest-name' );
		guestNameInput.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) { handleGuestContinue(); }
		} );
		document.getElementById( 'qq-guest-start' ).addEventListener( 'click', handleGuestContinue );
		guestNameInput.focus();
	}

	function handleGuestContinue() {
		ttsUnlock(); // must happen synchronously inside this real tap/Enter — see ttsUnlock()
		var input = document.getElementById( 'qq-guest-name' );
		var btn = document.getElementById( 'qq-guest-start' );
		var name = input ? input.value : '';
		if ( btn ) { btn.disabled = true; btn.textContent = 'தொடங்குகிறது…'; }
		api( '/auth/guest', { method: 'POST', body: { name: name } } )
			.then( function ( data ) {
				state.token = data.token;
				state.user = data.user;
				try { localStorage.setItem( STORAGE_KEY, data.token ); } catch ( e ) {}
				loadMeThenHome();
			} )
			.catch( function ( err ) {
				toast( err.message || 'வினாடி வினாவைத் தொடங்க முடியவில்லை. மீண்டும் முயற்சிக்கவும்.' );
				if ( btn ) { btn.disabled = false; btn.textContent = '▶ வினாடி வினாவைத் தொடங்கு'; }
			} );
	}

	function loadMeThenHome() {
		api( '/me' ).then( function ( me ) {
			state.user = me;
			renderHome();
		} ).catch( function () {
			clearSession();
			renderSignIn();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Screen: Home (difficulty picker)                                    */
	/* ------------------------------------------------------------------ */

	var DIFFS = [
		{ key: 'easy', icon: '🟢', name: 'எளிது', desc: 'தொடங்குவதற்கு ஏற்றது', cls: 'easy' },
		{ key: 'medium', icon: '🟡', name: 'நடுத்தரம்', desc: 'சமச்சீரான சவால்', cls: 'medium' },
		{ key: 'hard', icon: '🔴', name: 'கடினம்', desc: 'உங்கள் ஆழ்ந்த அறிவை சோதிக்கவும்', cls: 'hard' },
		{ key: 'mixed', icon: '✨', name: 'கலவை', desc: 'எல்லாவற்றிலும் சிறிது', cls: 'mixed' }
	];

	function renderHome() {
		state.screen = 'home';
		var u = state.user || {};
		var initials = ( u.name || '?' ).trim().charAt( 0 ).toUpperCase();
		var avatar = u.photo
			? '<img class="qq-avatar" src="' + esc( u.photo ) + '" alt="">'
			: '<div class="qq-avatar-fallback">' + esc( initials ) + '</div>';

		var html =
			'<div class="qq-welcome-user">' +
				avatar +
				'<div class="qq-who"><strong>' + esc( u.name || 'வினாடி வினா பங்கேற்பாளர்' ) + '</strong><span>உங்கள் அறிவை சோதிக்க தயார்</span></div>' +
				'<button class="qq-signout-link" id="qq-signout">பெயரை மாற்று</button>' +
			'</div>' +
			'<h2 style="margin:0 0 4px;font-size:17px;position:relative;z-index:1;">சிரம நிலையைத் தேர்ந்தெடுக்கவும்</h2>' +
			'<p style="margin:0 0 16px;color:var(--qq-text-dim);font-size:13px;position:relative;z-index:1;">ஒவ்வொரு வினாடி வினாவிலும் ' + ( CFG.questionsPerQuiz || 10 ) + ' சீரற்ற கேள்விகளும் ' + Math.round( ( CFG.timeLimitSeconds || 600 ) / 60 ) + ' நிமிட நேரவரம்பும் உள்ளது. நீங்கள் விரும்பும் அளவுக்கு மீண்டும் மீண்டும் விளையாடலாம்.</p>' +
			'<div class="qq-diff-grid">' +
				DIFFS.map( function ( d ) {
					return '<button class="qq-diff-card ' + d.cls + '" data-diff="' + d.key + '">' +
						'<span class="qq-diff-icon">' + d.icon + '</span>' +
						'<span class="qq-diff-name">' + d.name + '</span>' +
						'<span class="qq-diff-desc">' + d.desc + '</span>' +
					'</button>';
				} ).join( '' ) +
			'</div>';

		shell( html );

		document.getElementById( 'qq-signout' ).addEventListener( 'click', function () {
			api( '/auth/logout', { method: 'POST' } ).catch( function () {} ).then( function () {
				clearSession();
				renderSignIn();
			} );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.qq-diff-card' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				ttsUnlock(); // a returning visitor (saved session) can land here as their first tap of the page
				startQuiz( btn.getAttribute( 'data-diff' ) );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Screen: Quiz                                                        */
	/* ------------------------------------------------------------------ */

	function startQuiz( difficulty ) {
		shell( '<div class="qq-loading" style="background:none;box-shadow:none;padding:60px 0;"><div class="qq-loading-spinner"></div><p>உங்கள் வினாடி வினா தயாராகிறது…</p></div>' );
		api( '/questions/random?difficulty=' + encodeURIComponent( difficulty ) )
			.then( function ( data ) {
				state.attempt = {
					attempt_id: data.attempt_id,
					difficulty: data.difficulty,
					time_limit_seconds: data.time_limit_seconds,
					questions: data.questions,
					answers: {},
					index: 0,
					remaining: data.time_limit_seconds,
					startedAtMs: Date.now(),
					timer: null
				};
				state.screen = 'quiz';
				renderQuiz();
				startTimer();
			} )
			.catch( function ( err ) {
				toast( err.message || 'வினாடி வினாவைத் தொடங்க முடியவில்லை.' );
				renderHome();
			} );
	}

	function startTimer() {
		stopTimer();
		state.attempt.timer = setInterval( function () {
			state.attempt.remaining--;
			updateTimerDisplay();
			if ( state.attempt.remaining <= 0 ) {
				stopTimer();
				submitQuiz( 'time_up' );
			}
		}, 1000 );
	}
	function stopTimer() {
		if ( state.attempt && state.attempt.timer ) {
			clearInterval( state.attempt.timer );
			state.attempt.timer = null;
		}
	}
	function updateTimerDisplay() {
		var el = document.getElementById( 'qq-timer-text' );
		var wrap = document.getElementById( 'qq-timer' );
		if ( ! el || ! state.attempt ) { return; }
		el.textContent = fmtTime( state.attempt.remaining );
		if ( wrap ) {
			wrap.classList.toggle( 'qq-timer-low', state.attempt.remaining <= 60 );
		}
	}

	function renderQuiz() {
		var a = state.attempt;
		var q = a.questions[ a.index ];
		var answeredCount = Object.keys( a.answers ).length;
		var progressPct = Math.round( ( ( a.index + 1 ) / a.questions.length ) * 100 );

		// Only stop speech if we've actually moved to a different question —
		// re-rendering the SAME question (e.g. after picking an option) should
		// not interrupt it mid-sentence.
		if ( ttsActiveQid !== null && ttsActiveQid !== q.id ) {
			ttsStop();
			ttsActiveQid = null;
		}

		var dots = a.questions.map( function ( qq, i ) {
			var cls = 'qq-nav-dot';
			if ( a.answers.hasOwnProperty( qq.id ) ) { cls += ' answered'; }
			if ( i === a.index ) { cls += ' current'; }
			return '<div class="' + cls + '" data-idx="' + i + '">' + ( i + 1 ) + '</div>';
		} ).join( '' );

		var options = q.options.map( function ( opt ) {
			var selected = a.answers[ q.id ] === opt.key;
			return '<button class="qq-option' + ( selected ? ' selected' : '' ) + '" data-key="' + opt.key + '">' +
				'<span class="qq-option-key">' + opt.key + '</span><span>' + esc( opt.text ) + '</span>' +
			'</button>';
		} ).join( '' );

		var isLast = a.index === a.questions.length - 1;

		var html =
			'<div class="qq-quiz-header">' +
				'<span class="qq-diff-badge">' + esc( diffLabel( a.difficulty ) ) + '</span>' +
				'<div class="qq-timer" id="qq-timer">⏱ <span id="qq-timer-text">' + fmtTime( a.remaining ) + '</span></div>' +
			'</div>' +
			'<div class="qq-progress-wrap">' +
				'<div class="qq-progress-label"><span>கேள்வி ' + ( a.index + 1 ) + ' / ' + a.questions.length + '</span><span>' + answeredCount + '/' + a.questions.length + ' பதிலளிக்கப்பட்டது</span></div>' +
				'<div class="qq-progress-bar"><div class="qq-progress-fill" style="width:' + progressPct + '%"></div></div>' +
			'</div>' +
			'<div class="qq-nav-dots">' + dots + '</div>' +
			'<div class="qq-question-card">' +
				'<div class="qq-question-head">' +
					'<div class="qq-question-text">' + esc( q.question ) + '</div>' +
					( ttsSupported()
						? '<button class="qq-tts-btn" id="qq-tts-btn" type="button" aria-label="கேள்வியைக் கேளுங்கள்">🔊</button>'
						: '' ) +
				'</div>' +
				( ttsSupported()
					? '<label class="qq-tts-auto"><input type="checkbox" id="qq-tts-auto"> ஒவ்வொரு கேள்வியையும் தானாகவே படிக்க</label>'
					: '' ) +
				'<div class="qq-options">' + options + '</div>' +
			'</div>' +
			'<div class="qq-quiz-actions">' +
				'<button class="qq-btn qq-btn-secondary" id="qq-prev" ' + ( a.index === 0 ? 'disabled' : '' ) + '>&larr; முந்தையது</button>' +
				( isLast
					? '<button class="qq-btn qq-btn-primary" id="qq-submit">வினாடி வினாவை சமர்ப்பி ✓</button>'
					: '<button class="qq-btn qq-btn-primary" id="qq-next">அடுத்தது &rarr;</button>' ) +
			'</div>';

		ROOT.innerHTML = '<div class="qq-card">' + html + '</div>';
		updateTimerDisplay();

		Array.prototype.forEach.call( document.querySelectorAll( '.qq-option' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				a.answers[ q.id ] = btn.getAttribute( 'data-key' );
				renderQuiz();
			} );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.qq-nav-dot' ), function ( dot ) {
			dot.addEventListener( 'click', function () {
				a.index = parseInt( dot.getAttribute( 'data-idx' ), 10 );
				renderQuiz();
			} );
		} );
		var prevBtn = document.getElementById( 'qq-prev' );
		if ( prevBtn ) { prevBtn.addEventListener( 'click', function () { a.index--; renderQuiz(); } ); }
		var nextBtn = document.getElementById( 'qq-next' );
		if ( nextBtn ) { nextBtn.addEventListener( 'click', function () { a.index++; renderQuiz(); } ); }
		var submitBtn = document.getElementById( 'qq-submit' );
		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', function () {
				var unanswered = a.questions.length - Object.keys( a.answers ).length;
				if ( unanswered > 0 ) {
					confirmModal(
						unanswered + ' பதிலளிக்காத கேள்வி' + ( unanswered > 1 ? 'களுடன்' : 'யுடன்' ) + ' சமர்ப்பிக்கவா?',
						'பதிலளிக்காத கேள்விகளுக்கு பூஜ்ஜியம் மதிப்பெண் கிடைக்கும். நீங்கள் திரும்பிச் சென்று முதலில் அவற்றுக்கு பதிலளிக்கலாம்.',
						'எப்படியும் சமர்ப்பி', function () { submitQuiz( 'submitted' ); }
					);
				} else {
					submitQuiz( 'submitted' );
				}
			} );
		}

		var ttsBtn = document.getElementById( 'qq-tts-btn' );
		if ( ttsBtn ) {
			ttsBtn.addEventListener( 'click', function () {
				if ( ttsActiveQid === q.id && ttsIsSpeaking() ) {
					ttsStop();
					ttsActiveQid = null;
					ttsRefreshButton();
				} else {
					ttsSpeakQuestion( q );
				}
			} );
		}
		var ttsAutoCheckbox = document.getElementById( 'qq-tts-auto' );
		if ( ttsAutoCheckbox ) {
			ttsAutoCheckbox.checked = ttsAutoReadPref();
			ttsAutoCheckbox.addEventListener( 'change', function () {
				ttsSetAutoReadPref( ttsAutoCheckbox.checked );
				if ( ttsAutoCheckbox.checked && ttsAutoReadQid !== q.id ) {
					ttsAutoReadQid = q.id;
					ttsSpeakQuestion( q );
				}
			} );
		}
		// Auto-read once per question (not on every re-render of the same one,
		// e.g. after picking an option) when the visitor has the toggle on.
		if ( ttsSupported() && ttsAutoReadPref() && ttsAutoReadQid !== q.id ) {
			ttsAutoReadQid = q.id;
			ttsSpeakQuestion( q );
		}
	}

	function confirmModal( title, body, confirmLabel, onConfirm ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'qq-modal-backdrop';
		wrap.innerHTML =
			'<div class="qq-modal">' +
				'<h3 style="margin:0 0 4px;">' + esc( title ) + '</h3>' +
				'<p>' + esc( body ) + '</p>' +
				'<div class="qq-modal-actions">' +
					'<button class="qq-btn qq-btn-secondary" id="qq-modal-cancel">திரும்பிச் செல்</button>' +
					'<button class="qq-btn qq-btn-primary" id="qq-modal-ok">' + esc( confirmLabel ) + '</button>' +
				'</div>' +
			'</div>';
		document.body.appendChild( wrap );
		wrap.querySelector( '#qq-modal-cancel' ).addEventListener( 'click', function () { wrap.remove(); } );
		wrap.querySelector( '#qq-modal-ok' ).addEventListener( 'click', function () { wrap.remove(); onConfirm(); } );
	}

	function submitQuiz( reason ) {
		stopTimer();
		var a = state.attempt;
		var answers = Object.keys( a.answers ).map( function ( qid ) {
			return { question_id: parseInt( qid, 10 ), selected: a.answers[ qid ] };
		} );
		var timeTaken = Math.max( 0, Math.round( ( Date.now() - a.startedAtMs ) / 1000 ) );

		shell( '<div class="qq-loading" style="background:none;box-shadow:none;padding:60px 0;"><div class="qq-loading-spinner"></div><p>உங்கள் வினாடி வினா மதிப்பீடு செய்யப்படுகிறது…</p></div>' );

		api( '/attempts/' + a.attempt_id + '/submit', {
			method: 'POST',
			body: { answers: answers, time_taken_seconds: timeTaken, ended_reason: reason }
		} ).then( function ( result ) {
			state.lastResult = result;
			state.attempt = null;
			renderResult( result, { fromHistory: false } );
			if ( result.percentage >= 80 ) { launchConfetti(); }
		} ).catch( function ( err ) {
			toast( err.message || 'வினாடி வினாவை சமர்ப்பிக்க முடியவில்லை.' );
			renderHome();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Screen: Result                                                      */
	/* ------------------------------------------------------------------ */

	function motivationalMessage( pct ) {
		if ( pct >= 90 ) { return { title: 'சிறப்பானது! மாஷா அல்லாஹ் 🌟', sub: 'குர்ஆன் பற்றிய அசாதாரண அறிவு.' }; }
		if ( pct >= 70 ) { return { title: 'அருமையான செயல்! 👏', sub: 'உங்களுக்கு நல்ல புரிதல் உள்ளது — தொடருங்கள்.' }; }
		if ( pct >= 50 ) { return { title: 'நல்ல முயற்சி 🙂', sub: 'தொடர்ந்து கற்றுக்கொள்ளுங்கள் — படிக்கும் ஒவ்வொரு வசனமும் ஒரு நற்கூலி.' }; }
		return { title: 'தொடர்ந்து பயிற்சி செய்யுங்கள் 💪', sub: '"என் இறைவா, எனது அறிவை அதிகப்படுத்துவாயாக." (20:114) — மீண்டும் முயற்சிக்கவும்!' };
	}

	function renderResult( r, opts ) {
		opts = opts || {};
		state.screen = 'result';
		var pct = Math.round( r.percentage );
		var circumference = 2 * Math.PI * 70;
		var offset = circumference - ( pct / 100 ) * circumference;
		var msg = motivationalMessage( pct );
		var timeStr = fmtTime( r.time_taken_seconds || 0 );

		var html =
			'<div class="qq-result-top">' +
				( r.ended_reason === 'time_up' ? '<p style="color:var(--qq-amber);font-size:13px;margin:0 0 6px;">⏰ நேரம் முடிந்தது — வினாடி வினா தானாக சமர்ப்பிக்கப்பட்டது</p>' : '' ) +
				'<div class="qq-score-ring">' +
					'<svg viewBox="0 0 160 160">' +
						'<defs><linearGradient id="qq-gold-gradient" x1="0%" y1="0%" x2="100%" y2="0%">' +
							'<stop offset="0%" stop-color="#e8c96b"/><stop offset="100%" stop-color="#d4af37"/>' +
						'</linearGradient></defs>' +
						'<circle class="qq-ring-bg" cx="80" cy="80" r="70"></circle>' +
						'<circle class="qq-ring-fg" cx="80" cy="80" r="70" stroke-dasharray="' + circumference + '" stroke-dashoffset="' + circumference + '" data-final="' + offset + '"></circle>' +
					'</svg>' +
					'<div class="qq-ring-text"><span class="qq-score-num">' + r.score + '/' + r.total + '</span><span class="qq-score-total">' + pct + '%</span></div>' +
				'</div>' +
				'<div class="qq-result-message">' + msg.title + '</div>' +
				'<div class="qq-result-sub">' + esc( msg.sub ) + '</div>' +
			'</div>' +
			'<div class="qq-result-stats">' +
				'<div class="qq-stat-chip"><span class="num green">' + r.correct + '</span><span class="lbl">சரியானவை</span></div>' +
				'<div class="qq-stat-chip"><span class="num red">' + r.incorrect + '</span><span class="lbl">தவறானவை</span></div>' +
				'<div class="qq-stat-chip"><span class="num amber">' + r.unanswered + '</span><span class="lbl">பதிலளிக்காதவை</span></div>' +
				'<div class="qq-stat-chip"><span class="num">' + timeStr + '</span><span class="lbl">எடுத்துக்கொண்ட நேரம்</span></div>' +
			'</div>' +
			'<div class="qq-result-actions">' +
				'<button class="qq-btn qq-btn-primary" id="qq-download-cert">🎓 சான்றிதழைப் பதிவிறக்கு</button>' +
				'<button class="qq-btn qq-btn-secondary" id="qq-share-cert">📤 சான்றிதழைப் பகிர்</button>' +
				'<button class="qq-btn qq-btn-secondary" id="qq-toggle-review">📋 பதில்களை மதிப்பாய்வு செய்</button>' +
				'<button class="qq-btn qq-btn-secondary" id="qq-try-again">🔁 மீண்டும் முயற்சி</button>' +
				( opts.fromHistory ? '<button class="qq-btn qq-btn-secondary" id="qq-back-history">📜 வரலாற்றுக்குத் திரும்பு</button>' : '<button class="qq-btn qq-btn-secondary" id="qq-see-history">📜 வரலாற்றைக் காண்</button>' ) +
			'</div>' +
			'<div class="qq-review-list" id="qq-review-list">' +
				'<div class="qq-review-toolbar">' +
					'<button class="qq-btn qq-btn-secondary" id="qq-download-review">⬇️ மதிப்பாய்வைப் பதிவிறக்கு (PDF)</button>' +
					'<button class="qq-btn qq-btn-secondary" id="qq-share-review">📤 மதிப்பாய்வைப் பகிர்</button>' +
				'</div>' +
				( r.breakdown || [] ).map( renderReviewItem ).join( '' ) +
			'</div>';

		shell( html );

		var ring = document.querySelector( '.qq-ring-fg' );
		if ( ring ) {
			requestAnimationFrame( function () {
				setTimeout( function () { ring.style.strokeDashoffset = ring.getAttribute( 'data-final' ); }, 60 );
			} );
		}

		document.getElementById( 'qq-download-cert' ).addEventListener( 'click', function () { downloadCertificate( r ); } );
		document.getElementById( 'qq-share-cert' ).addEventListener( 'click', function () { shareCertificate( r ); } );
		document.getElementById( 'qq-toggle-review' ).addEventListener( 'click', function () {
			document.getElementById( 'qq-review-list' ).classList.toggle( 'open' );
		} );
		document.getElementById( 'qq-download-review' ).addEventListener( 'click', function () { downloadReview( r ); } );
		document.getElementById( 'qq-share-review' ).addEventListener( 'click', function () { shareReview( r ); } );
		document.getElementById( 'qq-try-again' ).addEventListener( 'click', renderHome );
		var seeHistory = document.getElementById( 'qq-see-history' );
		if ( seeHistory ) { seeHistory.addEventListener( 'click', renderHistory ); }
		var backHistory = document.getElementById( 'qq-back-history' );
		if ( backHistory ) { backHistory.addEventListener( 'click', renderHistory ); }
	}

	function renderReviewItem( item ) {
		var tag = item.selected === null || item.selected === undefined
			? '<span class="qq-review-tag unanswered">பதிலளிக்கவில்லை</span>'
			: ( item.is_correct ? '<span class="qq-review-tag correct">சரி</span>' : '<span class="qq-review-tag incorrect">தவறு</span>' );

		var opts = ( item.options || [] ).map( function ( opt ) {
			var cls = 'qq-option';
			if ( opt.key === item.correct ) { cls += ' qq-correct'; }
			else if ( opt.key === item.selected ) { cls += ' qq-incorrect'; }
			return '<div class="' + cls + '" style="cursor:default;">' +
				'<span class="qq-option-key">' + opt.key + '</span><span>' + esc( opt.text ) + '</span>' +
			'</div>';
		} ).join( '' );

		return '<div class="qq-review-item">' +
			tag +
			'<div class="qq-review-q">' + esc( item.question ) + '</div>' +
			'<div class="qq-options">' + opts + '</div>' +
			( item.reference ? '<div class="qq-review-ref">📖 ' + esc( item.reference ) + '</div>' : '' ) +
			( item.explanation ? '<div class="qq-review-explain">' + esc( item.explanation ) + '</div>' : '' ) +
		'</div>';
	}

	function launchConfetti() {
		var colors = [ '#d4af37', '#e8c96b', '#2fbf71', '#f3dd9a' ];
		for ( var i = 0; i < 40; i++ ) {
			( function () {
				var el = document.createElement( 'div' );
				el.className = 'qq-confetti-piece';
				el.style.left = ( Math.random() * 100 ) + 'vw';
				el.style.background = colors[ Math.floor( Math.random() * colors.length ) ];
				el.style.animationDuration = ( 2.2 + Math.random() * 1.6 ) + 's';
				el.style.animationDelay = ( Math.random() * 0.6 ) + 's';
				document.body.appendChild( el );
				setTimeout( function () { el.remove(); }, 4500 );
			} )();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Screen: History                                                     */
	/* ------------------------------------------------------------------ */

	function renderHistory() {
		state.screen = 'history';
		shell( '<div class="qq-loading" style="background:none;box-shadow:none;padding:40px 0;"><div class="qq-loading-spinner"></div><p>உங்கள் வரலாறு ஏற்றப்படுகிறது…</p></div>' );
		api( '/attempts/history' ).then( function ( data ) {
			var attempts = data.attempts || [];
			var html;
			if ( attempts.length === 0 ) {
				html = '<div class="qq-history-empty">இதுவரை வினாடி வினா முயற்சிகள் இல்லை — உங்கள் முதல் வினாடி வினாவை எடுங்கள்!</div>' +
					'<button class="qq-btn qq-btn-primary qq-btn-block" id="qq-h-start">வினாடி வினாவைத் தொடங்கு</button>';
			} else {
				html = attempts.map( function ( at ) {
					return '<div class="qq-history-item" data-id="' + at.id + '">' +
						'<div class="qq-h-left">' +
							'<span class="qq-diff-badge">' + esc( diffLabel( at.difficulty ) ) + '</span>' +
							'<span class="qq-h-date">' + esc( fmtDate( at.finished_at || at.started_at ) ) + ' · ' + fmtTime( at.time_taken_seconds ) + '</span>' +
						'</div>' +
						'<div class="qq-h-score">' + at.score + '/' + at.total_questions + ' <span style="font-size:12px;color:var(--qq-text-dim);">(' + Math.round( at.percentage ) + '%)</span></div>' +
					'</div>';
				} ).join( '' );
			}
			shell( html, { back: 'திரும்பு', onBack: renderHome } );
			var startBtn = document.getElementById( 'qq-h-start' );
			if ( startBtn ) { startBtn.addEventListener( 'click', renderHome ); }
			Array.prototype.forEach.call( document.querySelectorAll( '.qq-history-item' ), function ( item ) {
				item.addEventListener( 'click', function () {
					renderHistoryDetail( item.getAttribute( 'data-id' ) );
				} );
			} );
		} ).catch( function ( err ) {
			toast( err.message || 'வரலாற்றை ஏற்ற முடியவில்லை.' );
			renderHome();
		} );
	}

	function renderHistoryDetail( id ) {
		shell( '<div class="qq-loading" style="background:none;box-shadow:none;padding:40px 0;"><div class="qq-loading-spinner"></div><p>முயற்சி ஏற்றப்படுகிறது…</p></div>' );
		api( '/attempts/' + id ).then( function ( at ) {
			var result = {
				attempt_id: at.id, difficulty: at.difficulty,
				score: at.score, total: at.total_questions, percentage: at.percentage,
				correct: at.correct_count, incorrect: at.incorrect_count, unanswered: at.unanswered_count,
				time_taken_seconds: at.time_taken_seconds, ended_reason: at.ended_reason,
				finished_at: at.finished_at,
				breakdown: at.breakdown
			};
			renderResult( result, { fromHistory: true } );
		} ).catch( function ( err ) {
			toast( err.message || 'இந்த முயற்சியை ஏற்ற முடியவில்லை.' );
			renderHistory();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Share / Save modal (generic — used by the certificate and the      */
	/* answer-review PDF)                                                  */
	/* ------------------------------------------------------------------ */

	function openShareModal( opts ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'qq-modal-backdrop';

		var canNativeShare = false;
		var file = null;
		try {
			if ( navigator.canShare && window.File ) {
				file = new File( [ opts.blob ], opts.filename, { type: opts.fileType || 'application/pdf' } );
				canNativeShare = navigator.canShare( { files: [ file ] } );
			}
		} catch ( e ) { canNativeShare = false; }

		var quizUrl = window.location.href.split( '#' )[ 0 ].split( '?' )[ 0 ];
		var encodedMsg = encodeURIComponent( opts.text + ' ' + quizUrl );
		var waHref = 'https://wa.me/?text=' + encodedMsg;
		var fbHref = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent( quizUrl ) + '&quote=' + encodeURIComponent( opts.text );
		var twHref = 'https://twitter.com/intent/tweet?text=' + encodedMsg;
		var tgHref = 'https://t.me/share/url?url=' + encodeURIComponent( quizUrl ) + '&text=' + encodeURIComponent( opts.text );
		var mailHref = 'mailto:?subject=' + encodeURIComponent( 'எனது ' + opts.label + ' — ' + ( CFG.siteName || 'Kadavulmattum.org' ) ) + '&body=' + encodedMsg;

		wrap.innerHTML =
			'<div class="qq-modal qq-share-modal">' +
				'<h3 style="margin:0 0 4px;">📤 பகிர் &amp; சேமி</h3>' +
				'<p>முதலில் உங்கள் ' + esc( opts.label ) + 'ஐப் பதிவிறக்கவும், பின்னர் நீங்கள் விரும்பும் வழியில் பகிரவும் — அல்லது உங்கள் சாதனையை நேரடியாகப் பதிவிடவும்.</p>' +
				'<button class="qq-btn qq-btn-primary qq-btn-block" id="qq-share-download">⬇️ ' + esc( opts.label ) + 'ஐப் பதிவிறக்கு</button>' +
				( canNativeShare ? '<button class="qq-btn qq-btn-secondary qq-btn-block qq-share-native-btn" id="qq-share-native">📲 இதன் மூலம் பகிர்…</button>' : '' ) +
				'<div class="qq-share-grid">' +
					'<a class="qq-share-chip" href="' + waHref + '" target="_blank" rel="noopener">💬 WhatsApp</a>' +
					'<a class="qq-share-chip" href="' + fbHref + '" target="_blank" rel="noopener">📘 Facebook</a>' +
					'<a class="qq-share-chip" href="' + twHref + '" target="_blank" rel="noopener">🐦 X</a>' +
					'<a class="qq-share-chip" href="' + tgHref + '" target="_blank" rel="noopener">✈️ Telegram</a>' +
					'<a class="qq-share-chip" href="' + mailHref + '" target="_blank" rel="noopener">✉️ மின்னஞ்சல்</a>' +
					'<a class="qq-share-chip" href="https://drive.google.com/drive/my-drive" target="_blank" rel="noopener">☁️ Google Drive</a>' +
				'</div>' +
				'<p class="qq-signin-note" style="margin-top:14px;">Google Drive புதிய தாவலில் திறக்கும் — முதலில் கோப்பைப் பதிவிறக்கவும், பின்னர் அதை இழுத்து அங்கு சேமிக்கவும்.</p>' +
				'<button class="qq-btn qq-btn-secondary qq-btn-block" id="qq-share-close" style="margin-top:14px;">மூடு</button>' +
			'</div>';
		document.body.appendChild( wrap );

		function downloadNow() {
			var url = URL.createObjectURL( opts.blob );
			var a = document.createElement( 'a' );
			a.href = url;
			a.download = opts.filename;
			document.body.appendChild( a );
			a.click();
			a.remove();
			setTimeout( function () { URL.revokeObjectURL( url ); }, 4000 );
		}

		wrap.querySelector( '#qq-share-download' ).addEventListener( 'click', downloadNow );
		var nativeBtn = wrap.querySelector( '#qq-share-native' );
		if ( nativeBtn ) {
			nativeBtn.addEventListener( 'click', function () {
				navigator.share( { files: [ file ], title: opts.label, text: opts.text } )
					.then( function () { wrap.remove(); } )
					.catch( function ( e ) {
						if ( e && e.name !== 'AbortError' ) { toast( 'பகிர்வுப் பட்டியலைத் திறக்க முடியவில்லை.' ); }
					} );
			} );
		}
		wrap.querySelector( '#qq-share-close' ).addEventListener( 'click', function () { wrap.remove(); } );
		wrap.addEventListener( 'click', function ( e ) { if ( e.target === wrap ) { wrap.remove(); } } );
	}

	/* ------------------------------------------------------------------ */
	/* PDF text helpers — jsPDF's built-in fonts only understand Latin-1  */
	/* (character codes 0-255). Tamil — or anything else outside that     */
	/* range — comes out as garbled, wide-spaced mojibake if drawn with   */
	/* doc.text() directly, so any such text is instead rendered on a     */
	/* canvas (which shapes and measures it correctly, the same way the   */
	/* browser renders it on the page) and embedded into the PDF as a     */
	/* small image.                                                        */
	/* ------------------------------------------------------------------ */

	var PDF_UNICODE_FONT_STACK = '"Noto Sans Tamil", "Nirmala UI", "Latha", "Segoe UI", Tahoma, Arial, sans-serif';
	var __qqScratchCtx = null;
	function pdfScratchCtx() {
		if ( ! __qqScratchCtx ) {
			__qqScratchCtx = document.createElement( 'canvas' ).getContext( '2d' );
		}
		return __qqScratchCtx;
	}

	function pdfNeedsImageText( str ) {
		str = str || '';
		for ( var i = 0; i < str.length; i++ ) {
			if ( str.charCodeAt( i ) > 255 ) { return true; }
		}
		return false;
	}

	// Whether `str` should be treated as an RTL paragraph overall. Kept for
	// parity with the shared PDF helpers (Tamil is left-to-right, so this
	// will normally return false, but a name or quote that happens to mix in
	// Arabic — e.g. a Quranic phrase — still gets correct bidi treatment).
	function pdfIsRtlDominant( str ) {
		str = str || '';
		var rtl = 0, ltr = 0;
		for ( var i = 0; i < str.length; i++ ) {
			var c = str.charCodeAt( i );
			if ( ( c >= 0x0590 && c <= 0x08FF ) || ( c >= 0xFB1D && c <= 0xFDFF ) || ( c >= 0xFE70 && c <= 0xFEFF ) ) {
				rtl++;
			} else if ( ( c >= 0x0041 && c <= 0x005A ) || ( c >= 0x0061 && c <= 0x007A ) ) {
				ltr++;
			}
		}
		return rtl > ltr;
	}

	// Width of `text` in PDF points at `fontPt`, measured via canvas — used to wrap
	// BOTH ordinary Latin text (kept consistent with what pdfDrawText will draw) and
	// Tamil/Unicode text (which jsPDF's own font metrics can't measure at all).
	function pdfTextWidthPt( text, fontPt, bold ) {
		var ctx = pdfScratchCtx();
		var scale = 4;
		ctx.font = ( bold ? 'bold ' : '' ) + ( fontPt * scale ) + 'px ' + PDF_UNICODE_FONT_STACK;
		return ctx.measureText( String( text || '' ) ).width / scale;
	}

	// Greedy word-wrap using canvas metrics, with a small safety margin since the
	// canvas font used to measure isn't pixel-identical to jsPDF's native Helvetica.
	function pdfWrapLines( text, fontPt, maxWidthPt, bold ) {
		var safeWidth = maxWidthPt * 0.95;
		var words = String( text || '' ).split( /\s+/ ).filter( Boolean );
		if ( ! words.length ) { return [ '' ]; }
		var lines = [];
		var current = '';
		words.forEach( function ( word ) {
			var attempt = current ? current + ' ' + word : word;
			if ( current && pdfTextWidthPt( attempt, fontPt, bold ) > safeWidth ) {
				lines.push( current );
				current = word;
			} else {
				current = attempt;
			}
		} );
		if ( current ) { lines.push( current ); }
		return lines;
	}

	// Draws one already-wrapped line at (x, yBaseline). Plain Latin-1 text uses
	// jsPDF's native, crisp vector font; anything else (Tamil, etc.) is rendered via
	// canvas — correct shaping — and embedded as a small image instead.
	function pdfDrawText( doc, text, x, yBaseline, opts ) {
		opts = opts || {};
		var fontPt = opts.size || 11;
		var color = opts.color || [ 245, 243, 236 ];
		var align = opts.align || 'left';

		if ( ! pdfNeedsImageText( text ) ) {
			doc.setFont( opts.font || 'helvetica', opts.style || 'normal' );
			doc.setFontSize( fontPt );
			doc.setTextColor( color[ 0 ], color[ 1 ], color[ 2 ] );
			doc.text( text, x, yBaseline, { align: align } );
			return;
		}

		var scale = 4;
		var pxSize = fontPt * scale;
		var bold = ( opts.style || '' ).indexOf( 'bold' ) !== -1;
		var fontSpec = ( bold ? 'bold ' : '' ) + pxSize + 'px ' + PDF_UNICODE_FONT_STACK;

		var measureCtx = pdfScratchCtx();
		measureCtx.font = fontSpec;
		var metrics = measureCtx.measureText( text );
		var ascent = metrics.actualBoundingBoxAscent || pxSize * 0.82;
		var descent = metrics.actualBoundingBoxDescent || pxSize * 0.24;
		var widthPx = Math.max( 1, Math.ceil( metrics.width ) ) + 6;
		var heightPx = Math.ceil( ascent + descent ) + 4;

		var rtlDominant = pdfIsRtlDominant( text );
		var canvas = document.createElement( 'canvas' );
		canvas.width = widthPx;
		canvas.height = heightPx;
		var ctx = canvas.getContext( '2d' );
		ctx.font = fontSpec;
		ctx.direction = rtlDominant ? 'rtl' : 'ltr';
		ctx.textBaseline = 'alphabetic';
		ctx.textAlign = rtlDominant ? 'right' : 'left';
		ctx.fillStyle = 'rgb(' + color[ 0 ] + ',' + color[ 1 ] + ',' + color[ 2 ] + ')';
		ctx.fillText( text, rtlDominant ? widthPx - 3 : 3, ascent + 2 );

		var widthPt = widthPx / scale;
		var heightPt = heightPx / scale;
		var xPos = x;
		if ( align === 'center' ) { xPos = x - widthPt / 2; }
		else if ( align === 'right' ) { xPos = x - widthPt; }
		doc.addImage( canvas.toDataURL( 'image/png' ), 'PNG', xPos, yBaseline - ( ascent / scale ), widthPt, heightPt );
	}

	/* ------------------------------------------------------------------ */
	/* Certificate (client-side PDF)                                       */
	/* ------------------------------------------------------------------ */

	// Draws a filled 5-point star centered at (cx, cy).
	function pdfStar( doc, cx, cy, outerR, innerR ) {
		var spikes = 5, rot = -Math.PI / 2, step = Math.PI / spikes;
		var pts = [];
		for ( var i = 0; i < spikes; i++ ) {
			pts.push( [ cx + Math.cos( rot ) * outerR, cy + Math.sin( rot ) * outerR ] );
			rot += step;
			pts.push( [ cx + Math.cos( rot ) * innerR, cy + Math.sin( rot ) * innerR ] );
			rot += step;
		}
		var rel = [];
		for ( var j = 1; j < pts.length; j++ ) {
			rel.push( [ pts[ j ][ 0 ] - pts[ j - 1 ][ 0 ], pts[ j ][ 1 ] - pts[ j - 1 ][ 1 ] ] );
		}
		doc.lines( rel, pts[ 0 ][ 0 ], pts[ 0 ][ 1 ], [ 1, 1 ], 'F', true );
	}

	// Draws a small filled diamond (rotated square) — used as an ornamental divider dot.
	function pdfDiamond( doc, cx, cy, size ) {
		doc.lines( [ [ size, size ], [ -size, size ], [ -size, -size ], [ size, -size ] ], cx, cy - size, [ 1, 1 ], 'F', true );
	}

	// A short "line – diamond – line" ornamental divider centered at (cx, y).
	function pdfDivider( doc, cx, y, halfWidth ) {
		doc.setLineWidth( 0.75 );
		doc.line( cx - halfWidth, y, cx - 7, y );
		doc.line( cx + 7, y, cx + halfWidth, y );
		pdfDiamond( doc, cx, y, 3.5 );
	}

	function downloadCertificate( r ) {
		var built = buildCertificatePdf( r );
		if ( ! built ) { return; }
		built.doc.save( built.filename );
	}

	// Sharing uses an image (PNG), not the PDF — image files are what every social
	// app / chat / Google Drive actually previews and attaches cleanly; a PDF often
	// just shows as an anonymous file icon. The "Download Certificate" button above
	// still gives the printable PDF; this is only for the share/save flow.
	function shareCertificate( r ) {
		var built = buildCertificateImage( r );
		var pct = Math.round( r.percentage );
		var text = 'அல்ஹம்துலில்லாஹ்! நான் ' + ( CFG.siteName || 'Kadavulmattum.org' ) + ' குர்ஆன் வினாடி வினாவில் ' +
			r.score + '/' + r.total + ' (' + pct + '%) மதிப்பெண் பெற்றேன். நீங்களும் உங்கள் அறிவைச் சோதியுங்கள்!';
		built.canvas.toBlob( function ( blob ) {
			if ( ! blob ) {
				toast( 'சான்றிதழ் படத்தைத் தயார் செய்ய முடியவில்லை. மீண்டும் முயற்சிக்கவும்.' );
				return;
			}
			openShareModal( {
				blob: blob,
				filename: built.filename,
				fileType: 'image/png',
				label: 'சான்றிதழ்',
				text: text
			} );
		}, 'image/png' );
	}

	function buildCertificatePdf( r ) {
		if ( ! window.jspdf || ! window.jspdf.jsPDF ) {
			toast( 'சான்றிதழ் தயாரிப்பான் இன்னும் ஏற்றப்படுகிறது — சிறிது நேரத்தில் மீண்டும் முயற்சிக்கவும்.' );
			return null;
		}
		var jsPDF = window.jspdf.jsPDF;
		var doc = new jsPDF( { orientation: 'landscape', unit: 'pt', format: 'a4' } );
		var w = doc.internal.pageSize.getWidth();
		var h = doc.internal.pageSize.getHeight();
		var cx = w / 2;
		var navy = [ 10, 17, 40 ];
		var navy2 = [ 17, 27, 58 ];
		var gold = [ 212, 175, 55 ];
		var goldSoft = [ 232, 201, 107 ];
		var ink = [ 245, 243, 236 ];
		var inkDim = [ 185, 194, 221 ];
		var pct = Math.round( r.percentage );
		var name = ( state.user && state.user.name ) ? state.user.name : 'வினாடி வினா பங்கேற்பாளர்';

		// Background + subtle concentric texture rings (no transparency needed).
		doc.setFillColor( navy[ 0 ], navy[ 1 ], navy[ 2 ] );
		doc.rect( 0, 0, w, h, 'F' );
		doc.setDrawColor( 22, 32, 66 );
		doc.setLineWidth( 0.75 );
		doc.circle( cx, h / 2 + 6, 205, 'S' );
		doc.circle( cx, h / 2 + 6, 225, 'S' );

		// Double border frame with small diamond corner ornaments.
		doc.setDrawColor( gold[ 0 ], gold[ 1 ], gold[ 2 ] );
		doc.setLineWidth( 3 );
		doc.rect( 24, 24, w - 48, h - 48 );
		doc.setLineWidth( 1 );
		doc.rect( 32, 32, w - 64, h - 64 );
		doc.setFillColor( gold[ 0 ], gold[ 1 ], gold[ 2 ] );
		[ [ 44, 44 ], [ w - 44, 44 ], [ 44, h - 44 ], [ w - 44, h - 44 ] ].forEach( function ( p ) {
			pdfDiamond( doc, p[ 0 ], p[ 1 ], 5 );
		} );

		// Header.
		pdfDrawText( doc, 'அல்லாஹ்வின் பெயரால், மிகவும் கருணையுள்ளவன், மிகவும் இரக்கமுள்ளவன்', cx, 62, { size: 12, color: gold, align: 'center', font: 'times', style: 'italic' } );

		pdfDrawText( doc, CFG.certificateTitle || 'சாதனைச் சான்றிதழ்', cx, 96, { size: 30, color: gold, align: 'center', font: 'times', style: 'bold' } );
		pdfDivider( doc, cx, 112, 90 );

		pdfDrawText( doc, 'இதன் மூலம் சான்றளிக்கப்படுகிறது', cx, 136, { size: 12.5, color: ink, align: 'center' } );

		// Name — shrink to fit if long, so nothing overflows the card. Measured via
		// canvas so this works correctly for Tamil/non-Latin names too (see the PDF
		// text helpers above) — jsPDF's own getTextWidth() only understands Latin-1.
		var nameSize = 28, maxNameWidth = w - 200;
		while ( nameSize > 15 && pdfTextWidthPt( name, nameSize, true ) > maxNameWidth ) {
			nameSize -= 1;
		}
		pdfDrawText( doc, name, cx, 172, { size: nameSize, color: gold, align: 'center', font: 'times', style: 'bolditalic' } );
		pdfDivider( doc, cx, 184, 55 );

		pdfDrawText( doc, 'குர்ஆன் வினாடி வினாவை வெற்றிகரமாக முடித்துள்ளார் — ' + diffLabel( r.difficulty ) + ' நிலை', cx, 206, { size: 12.5, color: ink, align: 'center' } );

		// Three stat badges: Score / Percentage / Time.
		var badges = [
			{ label: 'மதிப்பெண்', value: r.score + ' / ' + r.total },
			{ label: 'சதவீதம்', value: pct + '%' },
			{ label: 'எடுத்த நேரம்', value: fmtTime( r.time_taken_seconds || 0 ) }
		];
		var bW = 148, bH = 54, bGap = 20, bTotal = badges.length * bW + ( badges.length - 1 ) * bGap;
		var bX = cx - bTotal / 2, bY = 226;
		badges.forEach( function ( b ) {
			doc.setFillColor( navy2[ 0 ], navy2[ 1 ], navy2[ 2 ] );
			doc.setDrawColor( gold[ 0 ], gold[ 1 ], gold[ 2 ] );
			doc.setLineWidth( 1 );
			doc.roundedRect( bX, bY, bW, bH, 7, 7, 'FD' );
			pdfDrawText( doc, b.label, bX + bW / 2, bY + 19, { size: 8.5, color: goldSoft, align: 'center' } );
			pdfDrawText( doc, b.value, bX + bW / 2, bY + 41, { size: 17, color: ink, align: 'center', style: 'bold' } );
			bX += bW + bGap;
		} );

		// Achievement medallion with ribbon tails.
		var TIER_LABELS = { excellent: 'சிறப்பானது', well_done: 'நன்றாகச் செய்தீர்கள்', good_effort: 'நல்ல முயற்சி', completed: 'முடிந்தது' };
		var tier = pct >= 90 ? TIER_LABELS.excellent : pct >= 70 ? TIER_LABELS.well_done : pct >= 50 ? TIER_LABELS.good_effort : TIER_LABELS.completed;
		var medCx = cx, medCy = 356, medR = 40;
		doc.setDrawColor( gold[ 0 ], gold[ 1 ], gold[ 2 ] );
		doc.setFillColor( gold[ 0 ], gold[ 1 ], gold[ 2 ] );
		doc.triangle( medCx - 15, medCy + medR - 6, medCx - 3, medCy + medR - 6, medCx - 9, medCy + medR + 30, 'F' );
		doc.triangle( medCx + 15, medCy + medR - 6, medCx + 3, medCy + medR - 6, medCx + 9, medCy + medR + 30, 'F' );
		doc.setLineWidth( 2 );
		doc.circle( medCx, medCy, medR, 'S' );
		doc.setFillColor( navy2[ 0 ], navy2[ 1 ], navy2[ 2 ] );
		doc.circle( medCx, medCy, medR - 5, 'F' );
		doc.setLineWidth( 0.75 );
		doc.circle( medCx, medCy, medR - 10, 'S' );
		doc.setFillColor( gold[ 0 ], gold[ 1 ], gold[ 2 ] );
		pdfStar( doc, medCx, medCy, 17, 7 );

		pdfDrawText( doc, tier, cx, medCy + medR + 46, { size: 11.5, color: gold, align: 'center', style: 'bold' } );

		// Motivational closing verse.
		pdfDrawText( doc, '"என் இறைவா, எனது அறிவை அதிகப்படுத்துவாயாக."', cx, medCy + medR + 72, { size: 13, color: ink, align: 'center', font: 'times', style: 'italic' } );
		pdfDrawText( doc, 'குர்ஆன் 20:114', cx, medCy + medR + 88, { size: 10.5, color: inkDim, align: 'center' } );

		// Footer: date (left) + site signature (right), certificate number centered.
		var footY = h - 78;
		doc.setDrawColor( gold[ 0 ], gold[ 1 ], gold[ 2 ] );
		doc.setLineWidth( 1 );
		doc.line( 90, footY, 260, footY );
		doc.line( w - 260, footY, w - 90, footY );

		var dateStr = r.finished_at ? fmtDate( r.finished_at ) : new Date().toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
		pdfDrawText( doc, dateStr, 175, footY + 15, { size: 10.5, color: ink, align: 'center' } );
		pdfDrawText( doc, 'முடிந்த தேதி', 175, footY - 8, { size: 8.5, color: goldSoft, align: 'center' } );

		pdfDrawText( doc, CFG.siteName || 'Kadavulmattum.org', w - 175, footY + 15, { size: 13, color: gold, align: 'center', font: 'times', style: 'bolditalic' } );
		pdfDrawText( doc, 'வழங்கியவர்', w - 175, footY - 8, { size: 8.5, color: goldSoft, align: 'center' } );

		var certId = 'QQ-' + ( r.attempt_id || Date.now() ) + '-' + pct;
		pdfDrawText( doc, 'சான்றிதழ் எண். ' + certId, cx, h - 40, { size: 8, color: [ 110, 120, 155 ], align: 'center' } );

		var fname = name.replace( /[^a-z0-9]+/gi, '_' ) || 'certificate';
		return { doc: doc, filename: 'Quranic-Quiz-Certificate-' + fname + '.pdf' };
	}

	/* ------------------------------------------------------------------ */
	/* Certificate as a shareable PNG image                                */
	/* Social apps / chat / Google Drive all preview and attach an image   */
	/* cleanly, while a PDF often just shows as an anonymous file icon —   */
	/* so sharing uses this canvas-drawn twin of the PDF design instead.   */
	/* Pure Canvas 2D, so Tamil/Unicode names render natively (no jsPDF    */
	/* base-14-font limitation to work around here).                      */
	/* ------------------------------------------------------------------ */

	function cvRoundRect( ctx, x, y, w, h, r ) {
		ctx.beginPath();
		ctx.moveTo( x + r, y );
		ctx.arcTo( x + w, y, x + w, y + h, r );
		ctx.arcTo( x + w, y + h, x, y + h, r );
		ctx.arcTo( x, y + h, x, y, r );
		ctx.arcTo( x, y, x + w, y, r );
		ctx.closePath();
	}

	function cvDiamond( ctx, cx, cy, size ) {
		ctx.beginPath();
		ctx.moveTo( cx, cy - size );
		ctx.lineTo( cx + size, cy );
		ctx.lineTo( cx, cy + size );
		ctx.lineTo( cx - size, cy );
		ctx.closePath();
		ctx.fill();
	}

	function cvStar( ctx, cx, cy, outerR, innerR ) {
		var spikes = 5, rot = -Math.PI / 2, step = Math.PI / spikes;
		ctx.beginPath();
		for ( var i = 0; i < spikes; i++ ) {
			ctx.lineTo( cx + Math.cos( rot ) * outerR, cy + Math.sin( rot ) * outerR );
			rot += step;
			ctx.lineTo( cx + Math.cos( rot ) * innerR, cy + Math.sin( rot ) * innerR );
			rot += step;
		}
		ctx.closePath();
		ctx.fill();
	}

	function cvDivider( ctx, cx, y, halfWidth, color ) {
		ctx.strokeStyle = color;
		ctx.lineWidth = 0.75;
		ctx.beginPath(); ctx.moveTo( cx - halfWidth, y ); ctx.lineTo( cx - 7, y ); ctx.stroke();
		ctx.beginPath(); ctx.moveTo( cx + 7, y ); ctx.lineTo( cx + halfWidth, y ); ctx.stroke();
		ctx.fillStyle = color;
		cvDiamond( ctx, cx, y, 3.5 );
	}

	function buildCertificateImage( r ) {
		var w = 842, h = 595; // same as jsPDF a4 landscape pt dimensions
		var scale = 2.4; // render resolution multiplier
		var canvas = document.createElement( 'canvas' );
		canvas.width = Math.round( w * scale );
		canvas.height = Math.round( h * scale );
		var ctx = canvas.getContext( '2d' );
		ctx.scale( scale, scale ); // draw using the same pt-coordinates as buildCertificatePdf

		var cx = w / 2;
		var navy = 'rgb(10,17,40)';
		var navy2 = 'rgb(17,27,58)';
		var gold = 'rgb(212,175,55)';
		var goldSoft = 'rgb(232,201,107)';
		var ink = 'rgb(245,243,236)';
		var inkDim = 'rgb(185,194,221)';
		var pct = Math.round( r.percentage );
		var name = ( state.user && state.user.name ) ? state.user.name : 'வினாடி வினா பங்கேற்பாளர்';
		var SERIF = '"Noto Sans Tamil", "Nirmala UI", Georgia, serif';
		var SANS = PDF_UNICODE_FONT_STACK;

		// Background + texture rings.
		ctx.fillStyle = navy;
		ctx.fillRect( 0, 0, w, h );
		ctx.strokeStyle = 'rgb(22,32,66)';
		ctx.lineWidth = 0.75;
		ctx.beginPath(); ctx.arc( cx, h / 2 + 6, 205, 0, Math.PI * 2 ); ctx.stroke();
		ctx.beginPath(); ctx.arc( cx, h / 2 + 6, 225, 0, Math.PI * 2 ); ctx.stroke();

		// Double border + corner diamonds.
		ctx.strokeStyle = gold;
		ctx.lineWidth = 3;
		ctx.strokeRect( 24, 24, w - 48, h - 48 );
		ctx.lineWidth = 1;
		ctx.strokeRect( 32, 32, w - 64, h - 64 );
		ctx.fillStyle = gold;
		[ [ 44, 44 ], [ w - 44, 44 ], [ 44, h - 44 ], [ w - 44, h - 44 ] ].forEach( function ( p ) {
			cvDiamond( ctx, p[ 0 ], p[ 1 ], 5 );
		} );

		// Header.
		ctx.fillStyle = gold;
		ctx.textAlign = 'center';
		ctx.textBaseline = 'alphabetic';
		ctx.font = 'italic 12px ' + SERIF;
		ctx.fillText( 'அல்லாஹ்வின் பெயரால், மிகவும் கருணையுள்ளவன், மிகவும் இரக்கமுள்ளவன்', cx, 62 );

		ctx.font = 'bold 30px ' + SERIF;
		ctx.fillText( CFG.certificateTitle || 'சாதனைச் சான்றிதழ்', cx, 96 );
		cvDivider( ctx, cx, 112, 90, gold );

		ctx.fillStyle = ink;
		ctx.font = '12.5px ' + SANS;
		ctx.fillText( 'இதன் மூலம் சான்றளிக்கப்படுகிறது', cx, 136 );

		// Name — shrink to fit, and set RTL direction only if it turns out to be
		// Arabic/Hebrew-dominant (Tamil itself is always LTR).
		var nameSize = 28, maxNameWidth = w - 200;
		ctx.direction = pdfIsRtlDominant( name ) ? 'rtl' : 'ltr';
		ctx.fillStyle = gold;
		ctx.font = 'italic bold ' + nameSize + 'px ' + SERIF;
		while ( nameSize > 15 && ctx.measureText( name ).width > maxNameWidth ) {
			nameSize -= 1;
			ctx.font = 'italic bold ' + nameSize + 'px ' + SERIF;
		}
		ctx.fillText( name, cx, 172 );
		ctx.direction = 'ltr';
		cvDivider( ctx, cx, 184, 55, gold );

		ctx.fillStyle = ink;
		ctx.font = '12.5px ' + SANS;
		ctx.fillText( 'குர்ஆன் வினாடி வினாவை வெற்றிகரமாக முடித்துள்ளார் — ' + diffLabel( r.difficulty ) + ' நிலை', cx, 206 );

		// Stat badges.
		var badges = [
			{ label: 'மதிப்பெண்', value: r.score + ' / ' + r.total },
			{ label: 'சதவீதம்', value: pct + '%' },
			{ label: 'எடுத்த நேரம்', value: fmtTime( r.time_taken_seconds || 0 ) }
		];
		var bW = 148, bH = 54, bGap = 20, bTotal = badges.length * bW + ( badges.length - 1 ) * bGap;
		var bX = cx - bTotal / 2, bY = 226;
		badges.forEach( function ( b ) {
			ctx.fillStyle = navy2;
			ctx.strokeStyle = gold;
			ctx.lineWidth = 1;
			cvRoundRect( ctx, bX, bY, bW, bH, 7 );
			ctx.fill(); ctx.stroke();
			ctx.fillStyle = goldSoft;
			ctx.font = '8.5px ' + SANS;
			ctx.fillText( b.label, bX + bW / 2, bY + 19 );
			ctx.fillStyle = ink;
			ctx.font = 'bold 17px ' + SANS;
			ctx.fillText( b.value, bX + bW / 2, bY + 41 );
			bX += bW + bGap;
		} );

		// Medallion.
		var TIER_LABELS = { excellent: 'சிறப்பானது', well_done: 'நன்றாகச் செய்தீர்கள்', good_effort: 'நல்ல முயற்சி', completed: 'முடிந்தது' };
		var tier = pct >= 90 ? TIER_LABELS.excellent : pct >= 70 ? TIER_LABELS.well_done : pct >= 50 ? TIER_LABELS.good_effort : TIER_LABELS.completed;
		var medCx = cx, medCy = 356, medR = 40;
		ctx.fillStyle = gold;
		ctx.beginPath(); ctx.moveTo( medCx - 15, medCy + medR - 6 ); ctx.lineTo( medCx - 3, medCy + medR - 6 ); ctx.lineTo( medCx - 9, medCy + medR + 30 ); ctx.closePath(); ctx.fill();
		ctx.beginPath(); ctx.moveTo( medCx + 15, medCy + medR - 6 ); ctx.lineTo( medCx + 3, medCy + medR - 6 ); ctx.lineTo( medCx + 9, medCy + medR + 30 ); ctx.closePath(); ctx.fill();
		ctx.strokeStyle = gold;
		ctx.lineWidth = 2;
		ctx.beginPath(); ctx.arc( medCx, medCy, medR, 0, Math.PI * 2 ); ctx.stroke();
		ctx.fillStyle = navy2;
		ctx.beginPath(); ctx.arc( medCx, medCy, medR - 5, 0, Math.PI * 2 ); ctx.fill();
		ctx.strokeStyle = gold;
		ctx.lineWidth = 0.75;
		ctx.beginPath(); ctx.arc( medCx, medCy, medR - 10, 0, Math.PI * 2 ); ctx.stroke();
		ctx.fillStyle = gold;
		cvStar( ctx, medCx, medCy, 17, 7 );

		ctx.font = 'bold 11.5px ' + SANS;
		ctx.fillStyle = gold;
		ctx.fillText( tier, cx, medCy + medR + 46 );

		ctx.font = 'italic 13px ' + SERIF;
		ctx.fillStyle = ink;
		ctx.fillText( '"என் இறைவா, எனது அறிவை அதிகப்படுத்துவாயாக."', cx, medCy + medR + 72 );
		ctx.font = '10.5px ' + SANS;
		ctx.fillStyle = inkDim;
		ctx.fillText( 'குர்ஆன் 20:114', cx, medCy + medR + 88 );

		// Footer.
		var footY = h - 78;
		ctx.strokeStyle = gold;
		ctx.lineWidth = 1;
		ctx.beginPath(); ctx.moveTo( 90, footY ); ctx.lineTo( 260, footY ); ctx.stroke();
		ctx.beginPath(); ctx.moveTo( w - 260, footY ); ctx.lineTo( w - 90, footY ); ctx.stroke();

		ctx.font = '10.5px ' + SANS;
		ctx.fillStyle = ink;
		var dateStr = r.finished_at ? fmtDate( r.finished_at ) : new Date().toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
		ctx.fillText( dateStr, 175, footY + 15 );
		ctx.fillStyle = goldSoft;
		ctx.font = '8.5px ' + SANS;
		ctx.fillText( 'முடிந்த தேதி', 175, footY - 8 );

		ctx.font = 'italic bold 13px ' + SERIF;
		ctx.fillStyle = gold;
		ctx.fillText( CFG.siteName || 'Kadavulmattum.org', w - 175, footY + 15 );
		ctx.font = '8.5px ' + SANS;
		ctx.fillStyle = goldSoft;
		ctx.fillText( 'வழங்கியவர்', w - 175, footY - 8 );

		var certId = 'QQ-' + ( r.attempt_id || Date.now() ) + '-' + pct;
		ctx.font = '8px ' + SANS;
		ctx.fillStyle = 'rgb(110,120,155)';
		ctx.fillText( 'சான்றிதழ் எண். ' + certId, cx, h - 40 );

		var fname = name.replace( /[^a-z0-9]+/gi, '_' ) || 'certificate';
		return { canvas: canvas, filename: 'Quranic-Quiz-Certificate-' + fname + '.png' };
	}

	/* ------------------------------------------------------------------ */
	/* Answer Review (client-side PDF)                                     */
	/* ------------------------------------------------------------------ */

	function downloadReview( r ) {
		var built = buildReviewPdf( r );
		if ( ! built ) { return; }
		built.doc.save( built.filename );
	}

	function shareReview( r ) {
		var built = buildReviewPdf( r );
		if ( ! built ) { return; }
		var pct = Math.round( r.percentage );
		var text = ( CFG.siteName || 'Kadavulmattum.org' ) + ' குர்ஆன் வினாடி வினாவில் இருந்து எனது பதில் மதிப்பாய்வு இதோ — ' +
			r.score + '/' + r.total + ' (' + pct + '%) மதிப்பெண் பெற்றேன்.';
		openShareModal( {
			blob: built.doc.output( 'blob' ),
			filename: built.filename,
			fileType: 'application/pdf',
			label: 'பதில் மதிப்பாய்வு',
			text: text
		} );
	}

	function buildReviewPdf( r ) {
		if ( ! window.jspdf || ! window.jspdf.jsPDF ) {
			toast( 'மதிப்பாய்வு தயாரிப்பான் இன்னும் ஏற்றப்படுகிறது — சிறிது நேரத்தில் மீண்டும் முயற்சிக்கவும்.' );
			return null;
		}
		var jsPDF = window.jspdf.jsPDF;
		var doc = new jsPDF( { orientation: 'portrait', unit: 'pt', format: 'a4' } );
		var w = doc.internal.pageSize.getWidth();
		var h = doc.internal.pageSize.getHeight();
		var margin = 42;
		var contentW = w - margin * 2;
		var navy = [ 10, 17, 40 ];
		var navy2 = [ 17, 27, 58 ];
		var gold = [ 212, 175, 55 ];
		var ink = [ 245, 243, 236 ];
		var inkDim = [ 185, 194, 221 ];
		var green = [ 60, 200, 130 ];
		var red = [ 224, 96, 90 ];
		var muted = [ 150, 150, 175 ];
		var pct = Math.round( r.percentage );
		var name = ( state.user && state.user.name ) ? state.user.name : 'வினாடி வினா பங்கேற்பாளர்';
		var siteName = CFG.siteName || 'Kadavulmattum.org';

		function paintPage() {
			doc.setFillColor( navy[ 0 ], navy[ 1 ], navy[ 2 ] );
			doc.rect( 0, 0, w, h, 'F' );
			doc.setDrawColor( gold[ 0 ], gold[ 1 ], gold[ 2 ] );
			doc.setLineWidth( 1 );
			doc.rect( 16, 16, w - 32, h - 32 );
		}

		paintPage();
		var y = 50;

		pdfDrawText( doc, 'அல்லாஹ்வின் பெயரால், மிகவும் கருணையுள்ளவன், மிகவும் இரக்கமுள்ளவன்', w / 2, y, { size: 10.5, color: gold, align: 'center', font: 'times', style: 'italic' } );
		y += 22;

		pdfDrawText( doc, 'வினாடி வினா பதில் மதிப்பாய்வு', w / 2, y, { size: 21, color: gold, align: 'center', font: 'times', style: 'bold' } );
		y += 10;
		pdfDivider( doc, w / 2, y + 6, 70 );
		y += 26;

		pdfDrawText( doc, name, w / 2, y, { size: 12, color: ink, align: 'center', style: 'bold' } );
		y += 16;

		var levelLabel = diffLabel( r.difficulty );
		pdfDrawText(
			doc,
			levelLabel + ' நிலை  ·  மதிப்பெண் ' + r.score + '/' + r.total + ' (' + pct + '%)  ·  நேரம் ' + fmtTime( r.time_taken_seconds || 0 ),
			w / 2, y, { size: 10.5, color: inkDim, align: 'center' }
		);
		y += 24;

		var items = r.breakdown || [];
		var bottomLimit = h - 46;

		var optionIndent = 22; // room for the +/x/- marker, drawn separately from the option text

		items.forEach( function ( item, idx ) {
			// Pre-wrap every text block (canvas-measured — accurate for Tamil and
			// Latin alike) so we know the card's height before drawing it.
			var qLines = pdfWrapLines( ( idx + 1 ) + '. ' + item.question, 12, contentW - 32, true );
			var optionLines = ( item.options || [] ).map( function ( opt ) {
				return pdfWrapLines( opt.key + '.  ' + opt.text, 10.5, contentW - 32 - optionIndent, false );
			} );
			var refLines = item.reference ? pdfWrapLines( 'குறிப்பு: ' + item.reference, 9.5, contentW - 32 ) : [];
			var explainLines = item.explanation ? pdfWrapLines( item.explanation, 9.5, contentW - 32 ) : [];

			var qH = qLines.length * 16;
			var optH = optionLines.reduce( function ( sum, lines ) { return sum + lines.length * 14 + 5; }, 0 );
			var refH = refLines.length ? refLines.length * 13 + 8 : 0;
			var explainH = explainLines.length ? explainLines.length * 13 + 6 : 0;
			var tagH = 24;
			var cardPad = 16;
			var cardH = tagH + qH + 6 + optH + refH + explainH + cardPad * 2;

			if ( y + cardH > bottomLimit ) {
				doc.addPage();
				paintPage();
				y = 46;
			}

			var cardTop = y;
			doc.setFillColor( navy2[ 0 ], navy2[ 1 ], navy2[ 2 ] );
			doc.setDrawColor( 55, 66, 105 );
			doc.setLineWidth( 1 );
			doc.roundedRect( margin, cardTop, contentW, cardH, 8, 8, 'FD' );

			var cy = cardTop + cardPad;

			// Status tag.
			var unanswered = item.selected === null || item.selected === undefined || item.selected === '';
			var tagText = unanswered ? 'பதிலளிக்கவில்லை' : ( item.is_correct ? 'சரி' : 'தவறு' );
			var tagColor = unanswered ? muted : ( item.is_correct ? green : red );
			var tagW = pdfTextWidthPt( tagText, 8.5, true ) + 16;
			doc.setFillColor( tagColor[ 0 ], tagColor[ 1 ], tagColor[ 2 ] );
			doc.roundedRect( margin + cardPad, cy - 3, tagW, 15, 7.5, 7.5, 'F' );
			pdfDrawText( doc, tagText, margin + cardPad + tagW / 2, cy + 7, { size: 8.5, color: [ 10, 14, 30 ], align: 'center', style: 'bold' } );
			cy += 20;

			// Question.
			qLines.forEach( function ( line ) {
				pdfDrawText( doc, line, margin + cardPad, cy, { size: 12, color: ink, style: 'bold' } );
				cy += 16;
			} );
			cy += 6;

			// Options — correct in green, a wrong selection in red, everything else dim.
			// The +/x/- marker is always drawn as plain ASCII, separately from the
			// (possibly non-Latin) option text, so the two never get tangled together.
			( item.options || [] ).forEach( function ( opt, i ) {
				var isCorrect = opt.key === item.correct;
				var isWrongPick = ( ! unanswered ) && opt.key === item.selected && ! isCorrect;
				var color = isCorrect ? green : ( isWrongPick ? red : inkDim );
				var marker = isCorrect ? '+' : ( isWrongPick ? 'x' : '-' );
				var style = ( isCorrect || isWrongPick ) ? 'bold' : 'normal';
				doc.setFont( 'helvetica', style );
				doc.setFontSize( 10.5 );
				doc.setTextColor( color[ 0 ], color[ 1 ], color[ 2 ] );
				doc.text( marker, margin + cardPad + 4, cy );
				optionLines[ i ].forEach( function ( line ) {
					pdfDrawText( doc, line, margin + cardPad + optionIndent, cy, { size: 10.5, color: color, style: style } );
					cy += 14;
				} );
				cy += 5;
			} );

			// Reference + explanation.
			if ( refLines.length ) {
				refLines.forEach( function ( line ) {
					pdfDrawText( doc, line, margin + cardPad, cy, { size: 9.5, color: gold, style: 'italic' } );
					cy += 13;
				} );
				cy += 4;
			}
			if ( explainLines.length ) {
				explainLines.forEach( function ( line ) {
					pdfDrawText( doc, line, margin + cardPad, cy, { size: 9.5, color: inkDim } );
					cy += 13;
				} );
			}

			y = cardTop + cardH + 14;
		} );

		var totalPages = doc.internal.getNumberOfPages();
		for ( var p = 1; p <= totalPages; p++ ) {
			doc.setPage( p );
			pdfDrawText( doc, siteName + '  ·  பக்கம் ' + p + ' / ' + totalPages, w / 2, h - 26, { size: 8, color: [ 110, 120, 155 ], align: 'center' } );
		}

		var fname = name.replace( /[^a-z0-9]+/gi, '_' ) || 'review';
		return { doc: doc, filename: 'Quranic-Quiz-Review-' + fname + '.pdf' };
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                 */
	/* ------------------------------------------------------------------ */

	function init() {
		var saved = null;
		try { saved = localStorage.getItem( STORAGE_KEY ); } catch ( e ) {}
		if ( saved ) {
			state.token = saved;
			loadMeThenHome();
		} else {
			renderSignIn();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
