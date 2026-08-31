/* Activity Monitor — Admin JS v1.2.1 */
(function ($) {
	'use strict';

	function openModal(title) {
		$('#am-modal-title').text(title || 'Details');
		$('#am-modal-overlay').show();
		$('#am-modal-body').html('<p class="am-loading">Loading…</p>');
	}

	/* Reads am_events via the am_get_v2_event_detail AJAX action. Name kept
	   as-is (not renamed to drop the "v2") to avoid an unnecessary
	   JS/PHP action-name rename with no functional benefit, now that the
	   old v1.x AM_DB-backed handler and its am-view-detail button/action
	   have been removed entirely. Shared by the log table's row click
	   and the am_event_id deep-link below. */
	function loadEventDetail(id) {
		openModal('Event Details');
		$.post(amData.ajaxUrl, { action: 'am_get_v2_event_detail', entry_id: id, nonce: amData.nonce })
		.done(function (r) { $('#am-modal-body').html(r.success ? r.data.html : '<p>Error.</p>'); })
		.fail(function () { $('#am-modal-body').html('<p>Request failed.</p>'); });
	}

	/* preventDefault() belongs here even though the button now declares
	   type="button": this row lives inside the filter form, so anything
	   that reaches a default action submits it and reloads the page out
	   from under the modal. Belt and braces on purpose -- that is exactly
	   how this broke in 2.3.0. */
	$(document).on('click', '.am-view-detail-v2', function (e) {
		e.preventDefault();
		loadEventDetail($(this).data('id'));
	});

	/* Deep link from a Slack alert: admin.php?page=activity-monitor&
	   am_event_id=123 lands on the Activity Log screen (that slug is the
	   screen, no routing needed) and this opens that event's Details
	   modal immediately, so the link takes the reader straight to the
	   event instead of just the screen. The row
	   itself also gets highlighted (am-row-highlighted, see admin.css)
	   so it's still easy to spot in the table after the modal is
	   closed -- the highlight is looked up by the same data-id the
	   Details button carries, so it only applies if that event's row
	   is actually rendered on the current (first, unfiltered) page. */
	var amDeepLinkEventId = new URLSearchParams(window.location.search).get('am_event_id');
	if (amDeepLinkEventId) {
		loadEventDetail(amDeepLinkEventId);
		$('.am-view-detail-v2[data-id="' + amDeepLinkEventId + '"]').closest('tr').addClass('am-row-highlighted');
	}

	/* IP address lookup modal. Triggered by clicking any .am-ip-lookup
	   element (the IP text itself, wherever it's shown) rather than a
	   separate button, matching how a person expects a linked-looking
	   value to behave. Fetches geolocation/ISP data server-side via
	   ajax_ip_lookup() -- see that method's doc for why this isn't a
	   direct iframe embed of the lookup provider's own page. */
	$(document).on('click', '.am-ip-lookup', function (e) {
		e.preventDefault();
		var ip = $(this).data('ip');
		if (!ip) return;
		openModal('IP Lookup: ' + ip);
		$.post(amData.ajaxUrl, { action: 'am_ip_lookup', ip: ip, nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-modal-body').html(r.data.html);
			} else {
				$('#am-modal-body').html('<p>' + (r.data && r.data.message ? r.data.message : 'Error.') + '</p>');
			}
		})
		.fail(function () { $('#am-modal-body').html('<p>Request failed.</p>'); });
	});

	/* WordPress user profile modal. Triggered by clicking a username in
	   the Activity Log. Sends the user ID rather than the login for the
	   reason given on ajax_user_profile(): logins can be renamed and
	   reused, IDs can't. A user deleted since the event was logged comes
	   back as an error with an explanatory message, which is shown as-is
	   rather than as a generic failure. */
	$(document).on('click', '.am-user-profile-link', function (e) {
		e.preventDefault();
		var userId = $(this).data('user-id');
		if (!userId) return;
		openModal('User Details');
		$.post(amData.ajaxUrl, { action: 'am_user_profile', user_id: userId, nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-modal-body').html(r.data.html);
			} else {
				$('#am-modal-body').html('<p>' + (r.data && r.data.message ? r.data.message : 'Error.') + '</p>');
			}
		})
		.fail(function () { $('#am-modal-body').html('<p>Request failed.</p>'); });
	});

	$('#am-modal-close').on('click', function () { $('#am-modal-overlay').hide(); });
	$('#am-modal-overlay').on('click', function (e) { if (e.target === this) $(this).hide(); });
	$(document).on('keydown', function (e) { if (e.key === 'Escape') $('#am-modal-overlay').hide(); });

	/* Notification channel management (Settings > Alerts & Reports).
	   Add/Edit both open the shared modal, fetching its form HTML from
	   ajax_channel_form() (server-rendered, since it needs the current
	   AM_Log_Levels list and, for edit, the channel's actual stored
	   values -- not trusted from the DOM). Save posts to
	   ajax_save_channel() immediately (Rob's explicit choice: AJAX save
	   per channel, not a page-level submit button) and replaces the
	   whole table body with the fresh server-rendered rows, so the
	   table never drifts from what's actually stored. */
	function openChannelModal(type, index) {
		openModal('Loading…');
		$.post(amData.ajaxUrl, { action: 'am_channel_form', type: type, index: (index === undefined ? '' : index), nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-modal-title').text(r.data.title);
				$('#am-modal-body').html(r.data.html);
			} else {
				$('#am-modal-body').html('<p>Error.</p>');
			}
		})
		.fail(function () { $('#am-modal-body').html('<p>Request failed.</p>'); });
	}

	$(document).on('click', '.am-add-channel-btn', function () {
		openChannelModal($(this).data('type'));
	});
	$(document).on('click', '.am-edit-channel-btn', function () {
		openChannelModal(null, $(this).data('index'));
	});

	function refreshChannelsTable(html, isEmpty) {
		if (isEmpty) {
			// No channels left after a delete -- there's no table to
			// refresh into, so just reload the page to show the
			// section's empty state. A full client-side swap between
			// the "table" and "no channels yet" markup isn't worth
			// building for what should be a rare path (deleting the
			// last remaining channel).
			window.location.reload();
			return;
		}
		var $tbody = $('#am-channels-table-body');
		if ($tbody.length) {
			$tbody.html(html);
		} else {
			// First channel just added and there was no table yet
			// (empty state was showing instead) -- reload so the
			// section renders its table markup properly rather than
			// trying to construct the table wrapper/head in JS too.
			window.location.reload();
		}
	}

	$(document).on('submit', '#am-channel-modal-form', function (e) {
		e.preventDefault();
		var $form = $(this);
		var $btn  = $('#am-channel-save-btn').prop('disabled', true);
		var $err  = $('#am-channel-modal-error').hide();

		$.post(amData.ajaxUrl, $form.serialize() + '&action=am_save_channel&nonce=' + amData.nonce)
		.done(function (r) {
			if (r.success) {
				$('#am-modal-overlay').hide();
				refreshChannelsTable(r.data.html, false);
			} else {
				$err.text((r.data && r.data.message) ? r.data.message : 'Could not save. Check the values and try again.').show();
			}
		})
		.fail(function () { $err.text('Request failed.').show(); })
		.always(function () { $btn.prop('disabled', false); });
	});

	$(document).on('click', '#am-channel-delete-btn', function () {
		if (!window.confirm('Remove this notification channel?')) return;
		var index = $(this).data('index');
		var $btn  = $(this).prop('disabled', true);

		$.post(amData.ajaxUrl, { action: 'am_delete_channel', index: index, nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-modal-overlay').hide();
				refreshChannelsTable(r.data.html, r.data.empty);
			}
		})
		.always(function () { $btn.prop('disabled', false); });
	});

	/* Activity Log and Visitor Stats: AJAX pagination/filtering, no page
	   reload. Both screens follow the same qs-round-trip shape --
	   render_log_content()/render_stats_content() build every link and
	   form on the page from an explicit $current_url rather than the
	   implicit "current URL" PHP would otherwise default to (which during
	   an AJAX request is admin-ajax.php, not the screen the link is meant
	   for), so any href or serialize()'d form inside the container is
	   already the exact query string to send back and to push into the
	   address bar. That symmetry is what lets one pair of handlers below
	   cover both the initial GET-built links/inputs and every refresh
	   after them without special-casing which one produced the markup. */

	function amQsFromHref(href) {
		var i = href.indexOf('?');
		return i === -1 ? '' : href.slice(i + 1);
	}

	function amPushState(qs) {
		window.history.pushState({ amQs: qs }, '', window.location.pathname + '?' + qs);
	}

	function amRefreshLog(qs, pushUrl) {
		$.post(amData.ajaxUrl, { action: 'am_log_table', qs: qs, nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-log-app').html(r.data.html);
				if (pushUrl) amPushState(qs);
			}
		});
	}

	function amRefreshStats(qs, pushUrl) {
		$.post(amData.ajaxUrl, { action: 'am_stats_content', qs: qs, nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-stats-content').html(r.data.html);
				if (pushUrl) amPushState(qs);
			}
		});
	}

	/* Level filter links, pagination links (top and bottom tablenav), the
	   user-filter chip's remove link, and Reset -- every navigable link
	   inside the log app except the per-row Details/username/IP links
	   (those have their own handlers above and either use href="#" or are
	   buttons) and the CSV/JSON/HTML/TXT export links, which are real
	   downloads and must not be hijacked into an AJAX call. */
	$(document).on('click', '#am-log-app .subsubsub a, #am-log-app .tablenav-pages a, #am-log-app .am-filter-chip-remove, #am-log-app #am-log-reset', function (e) {
		e.preventDefault();
		amRefreshLog(amQsFromHref(this.href), true);
	});

	$(document).on('submit', '#am-log-app #am-filter-form', function (e) {
		e.preventDefault();
		amRefreshLog($(this).serialize(), true);
	});

	/* Rows-per-page dropdown: lives inside #am-filter-form (see CLAUDE.md on
	   why the whole table is one form), so serialize() already carries its
	   value -- this just resets paged back to 1, the same way the Visitor
	   Stats range dropdown resets its own tables' page params below. */
	$(document).on('change', '#am-log-app select[name="am_per_page"]', function () {
		var $form = $(this).closest('form');
		var params = new URLSearchParams($form.serialize());
		params.delete('paged');
		amRefreshLog(params.toString(), true);
	});

	/* Visitor Stats: the range dropdown resets every table's own page back
	   to 1 -- an explicit list of the per-table page params rather than
	   deleting whatever keys happen to be present, so a stale page number
	   left over from the previous range can't strand a table (e.g.
	   Referrers on page 4 of a range that now only has 1) showing nothing
	   with no visible way back. Page-turn clicks on any one table leave
	   every other table's page untouched. */
	var amStatsPageParams = ['am_hits_page', 'am_top_page', 'am_ref_page', 'am_country_page', 'am_browser_page', 'am_os_page', 'am_device_page'];

	function amStatsRangeChanged($form) {
		var params = new URLSearchParams($form.serialize());
		amStatsPageParams.forEach(function (key) { params.delete(key); });
		amRefreshStats(params.toString(), true);
	}

	$(document).on('submit', '#am-stats-filter-form', function (e) {
		e.preventDefault();
		amStatsRangeChanged($(this));
	});

	/* The range <select> has no onchange attribute of its own -- see this
	   handler instead. this.form.submit() (the native DOM method, as
	   opposed to a real click on a submit control) deliberately does not
	   fire a 'submit' event per spec, precisely so a script-triggered
	   submit can't be intercepted the way a user-initiated one can -- so
	   an onchange="this.form.submit()" attribute here would silently fall
	   through to a full page reload instead of the AJAX refresh below.
	   Binding 'change' directly sidesteps that entirely. */
	$(document).on('change', '#am-stats-filter-form select[name="am_range"]', function () {
		amStatsRangeChanged($(this.closest('form')));
	});

	$(document).on('click', '#am-stats-content .tablenav-pages a', function (e) {
		e.preventDefault();
		amRefreshStats(amQsFromHref(this.href), true);
	});

	/* Browser back/forward across either screen's AJAX-pushed states. A
	   history entry that predates the AJAX conversion (or one reached by
	   navigating away and back through wp-admin's own menu) reloads the
	   document instead of firing popstate, so this only ever needs to
	   handle the SPA-style entries pushState created above. */
	window.addEventListener('popstate', function () {
		var qs = window.location.search.replace(/^\?/, '');
		if ($('#am-log-app').length) amRefreshLog(qs, false);
		if ($('#am-stats-content').length) amRefreshStats(qs, false);
	});

}(jQuery));
