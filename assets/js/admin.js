/* global stagingSafety */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var clear = document.getElementById( 'ss-clear-log' );

		if ( clear ) {
			clear.addEventListener( 'click', function ( event ) {
				var message = ( window.stagingSafety && window.stagingSafety.confirmClear ) || 'Weet je het zeker?';

				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		}

		var boxes = function () {
			return document.querySelectorAll( '.ss-cron input[type="checkbox"]' );
		};

		var risky = document.getElementById( 'ss-select-risky' );

		if ( risky ) {
			risky.addEventListener( 'click', function () {
				Array.prototype.forEach.call( boxes(), function ( box ) {
					if ( '1' === box.getAttribute( 'data-risky' ) ) {
						box.checked = true;
					}
				} );
			} );
		}

		var none = document.getElementById( 'ss-select-none' );

		if ( none ) {
			none.addEventListener( 'click', function () {
				Array.prototype.forEach.call( boxes(), function ( box ) {
					box.checked = false;
				} );
			} );
		}
	} );
}() );
