( function () {
	'use strict';

	var cfg = window.octkConfig;
	if ( ! cfg || ! cfg.endpoint ) {
		return;
	}

	var TOKEN_RE = /^[0-9a-f]{32}$/;

	// Pinned body contract (scope §5, decision 25): form-encoded field "token",
	// identical for sendBeacon and the fetch keepalive fallback.
	function send( token ) {
		var body = new URLSearchParams( { token: token } );
		if ( navigator.sendBeacon ) {
			try {
				if ( navigator.sendBeacon( cfg.endpoint, body ) ) {
					return;
				}
			} catch ( e ) {
				// Fall through to fetch.
			}
		}
		try {
			fetch( cfg.endpoint, { method: 'POST', body: body, keepalive: true } );
		} catch ( e ) {
			// Never interfere with navigation.
		}
	}

	// click covers primary-button and keyboard activation; auxclick covers
	// middle-button (button 1) only. No overlap, so no dedupe needed.
	function handle( event ) {
		if ( event.type === 'auxclick' && event.button !== 1 ) {
			return;
		}
		var target = event.target;
		if ( ! target || typeof target.closest !== 'function' ) {
			return;
		}
		var anchor = target.closest( 'a[data-octk-token]' );
		if ( ! anchor ) {
			return;
		}
		var token = anchor.getAttribute( 'data-octk-token' );
		if ( token && TOKEN_RE.test( token ) ) {
			send( token );
		}
	}

	document.addEventListener( 'click', handle, true );
	document.addEventListener( 'auxclick', handle, true );
} )();
