/*
 * Setup > tron > Audit: the live tail. While "Live" is ticked it asks the same view for the rows newer than the
 * newest one shown ((part)/rows/(after)/<id>, view parameters only) every 5 seconds and puts them on top of the table.
 * Without script the page works as before (the checkbox stays hidden).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */
( function () {
    'use strict';

    function start() {
        var body = document.getElementById( 'tron-audit-rows' );
        var box = document.getElementById( 'tron-live' );
        var toggle = document.querySelector( '[data-tron-live-toggle]' );
        var status = document.getElementById( 'tron-live-status' );
        if ( !body || !box || !toggle || !window.fetch ) {
            return;
        }
        toggle.hidden = false;
        var timer = null;
        var busy = false;

        function poll() {
            if ( busy ) {
                return;
            }
            busy = true;
            var url = body.getAttribute( 'data-live-url' ) + '/(after)/' + ( parseInt( body.getAttribute( 'data-after' ), 10 ) || 0 );
            window.fetch( url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } } )
                .then( function ( r ) { return r.ok ? r.text() : ''; } )
                .then( function ( html ) {
                    busy = false;
                    if ( !html || !html.trim() ) {
                        return;
                    }
                    var t = document.createElement( 'tbody' );
                    t.innerHTML = html;
                    var rows = t.querySelectorAll( 'tr[data-id]' );
                    var max = parseInt( body.getAttribute( 'data-after' ), 10 ) || 0;
                    for ( var i = rows.length - 1; i >= 0; i-- ) {
                        var id = parseInt( rows[i].getAttribute( 'data-id' ), 10 );
                        if ( id > max ) {
                            rows[i].classList.add( 'is-new' );
                            body.insertBefore( rows[i], body.firstChild );
                        }
                    }
                    for ( var j = 0; j < rows.length; j++ ) {
                        max = Math.max( max, parseInt( rows[j].getAttribute( 'data-id' ), 10 ) || 0 );
                    }
                    body.setAttribute( 'data-after', String( max ) );
                    if ( status && rows.length ) {
                        status.textContent = rows.length + ' new';
                    }
                } )
                .catch( function () { busy = false; } );
        }

        box.addEventListener( 'change', function () {
            if ( box.checked ) {
                poll();
                timer = window.setInterval( poll, 5000 );
            } else if ( timer ) {
                window.clearInterval( timer );
                timer = null;
            }
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', start );
    } else {
        start();
    }
}() );
