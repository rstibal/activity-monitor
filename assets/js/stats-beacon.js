/**
 * AM_Stats front-end beacon. Fires once per pageview from the browser
 * rather than a PHP-side hook, so it counts correctly on pages served from
 * a full-page cache (a PHP hook only runs on cache misses). Reads the URL
 * path, title, and referrer directly from the browser -- theme- and
 * cache-agnostic, since none of it comes from server-rendered markup.
 */
( function () {
	if ( typeof amStatsData === 'undefined' ) {
		return;
	}

	var data = new FormData();
	data.append( 'action', 'am_stats_track' );
	data.append( 'nonce', amStatsData.nonce );
	data.append( 'url', window.location.pathname + window.location.search );
	data.append( 'title', document.title );
	data.append( 'referrer', document.referrer );

	if ( navigator.sendBeacon ) {
		navigator.sendBeacon( amStatsData.ajaxUrl, data );
		return;
	}

	fetch( amStatsData.ajaxUrl, {
		method: 'POST',
		credentials: 'omit',
		body: data,
	} );
} )();
