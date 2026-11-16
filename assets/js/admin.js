/* Activity Monitor — Admin JS v1.3.0 */
(function ($) {
	'use strict';

	/* Every string this file shows, translated server-side: see
	   AM_Admin::enqueue_assets(). */
	var L = amData.i18n;

	/* A one-line message in the modal body. .text(), not .html(): some of
	   these come back from the server. */
	function amModalMessage(text) {
		$('#am-modal-body').empty().append($('<p>').text(text));
	}

	/* The shared modal is a real dialog to assistive tech and the keyboard
	   (role/aria-modal are on the markup, see render_screen_close()): focus
	   moves into it on open, Tab stays inside it (handler further down), and
	   on close focus goes back to whatever opened it. A modal opened from
	   inside the modal (a username in Event Details) keeps the original
	   opener as the place to return to. */
	var amModalReturnFocus = null;

	function openModal(title) {
		var $overlay = $('#am-modal-overlay');
		if (!$overlay.is(':visible')) amModalReturnFocus = document.activeElement;
		$('#am-modal-title').text(title || L.details);
		$overlay.show();
		$('#am-modal-body').empty().append($('<p class="am-loading">').text(L.loading));
		$overlay.find('.am-modal').trigger('focus');
	}

	function closeModal() {
		$('#am-modal-overlay').hide();
		// The opener may be gone: a table refresh replaces its rows.
		if (amModalReturnFocus && document.body.contains(amModalReturnFocus)) amModalReturnFocus.focus();
		amModalReturnFocus = null;
	}

	/* Reads am_events via the am_get_v2_event_detail AJAX action. Name kept
	   as-is (not renamed to drop the "v2") to avoid an unnecessary
	   JS/PHP action-name rename with no functional benefit, now that the
	   old v1.x AM_DB-backed handler and its am-view-detail button/action
	   have been removed entirely. Shared by the log table's row click
	   and the am_event_id deep-link below. */
	function loadEventDetail(id) {
		openModal(L.eventDetails);
		$.post(amData.ajaxUrl, { action: 'am_get_v2_event_detail', entry_id: id, nonce: amData.nonce })
		.done(function (r) { if (r.success) { $('#am-modal-body').html(r.data.html); } else { amModalMessage(L.error); } })
		.fail(function () { amModalMessage(L.requestFailed); });
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
	/* Digits only, checked before the value goes anywhere near a selector:
	   anything else (e.g. 1"]) made the attribute selector below throw,
	   which aborted this whole file and left every handler after it
	   unbound. The param is then dropped from the address bar, so a reload
	   or a back-navigation to this entry doesn't reopen the modal -- and
	   it's also left out of every link the screen builds (see
	   AM_Admin::render_log_content()'s $current_url). */
	var amUrlParams = new URLSearchParams(window.location.search);
	var amDeepLinkEventId = amUrlParams.get('am_event_id');
	if (amDeepLinkEventId !== null) {
		if (/^\d+$/.test(amDeepLinkEventId)) {
			loadEventDetail(amDeepLinkEventId);
			$('.am-view-detail-v2[data-id="' + amDeepLinkEventId + '"]').closest('tr').addClass('am-row-highlighted');
		}
		amUrlParams.delete('am_event_id');
		var amCleanQs = amUrlParams.toString();
		window.history.replaceState(window.history.state, '', window.location.pathname + (amCleanQs ? '?' + amCleanQs : '') + window.location.hash);
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
		openModal(L.ipLookup.replace('%s', ip));
		$.post(amData.ajaxUrl, { action: 'am_ip_lookup', ip: ip, nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-modal-body').html(r.data.html);
			} else {
				amModalMessage(r.data && r.data.message ? r.data.message : L.error);
			}
		})
		.fail(function () { amModalMessage(L.requestFailed); });
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
		openModal(L.userDetails);
		$.post(amData.ajaxUrl, { action: 'am_user_profile', user_id: userId, nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-modal-body').html(r.data.html);
			} else {
				amModalMessage(r.data && r.data.message ? r.data.message : L.error);
			}
		})
		.fail(function () { amModalMessage(L.requestFailed); });
	});

	$('#am-modal-close').on('click', closeModal);
	$('#am-modal-overlay').on('click', function (e) { if (e.target === this) closeModal(); });
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && $('#am-modal-overlay').is(':visible')) closeModal();
	});

	/* Keeps Tab inside the open modal: off the last control wraps to the
	   first, and Shift+Tab off the first (or off the dialog itself, where
	   focus sits right after opening) wraps to the last. */
	$('#am-modal-overlay').on('keydown', function (e) {
		if (e.key !== 'Tab') return;
		var dialog = $(this).find('.am-modal')[0];
		var $stops = $(this).find('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])').filter(':visible');
		if (!$stops.length) { e.preventDefault(); return; }
		var first = $stops[0];
		var last  = $stops[$stops.length - 1];
		if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	});

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
		openModal(L.loading);
		$.post(amData.ajaxUrl, { action: 'am_channel_form', type: type, index: (index === undefined ? '' : index), nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-modal-title').text(r.data.title);
				$('#am-modal-body').html(r.data.html);
			} else {
				amModalMessage(L.error);
			}
		})
		.fail(function () { amModalMessage(L.requestFailed); });
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
				closeModal();
				refreshChannelsTable(r.data.html, false);
			} else {
				$err.text((r.data && r.data.message) ? r.data.message : L.saveFailed).show();
			}
		})
		.fail(function () { $err.text(L.requestFailed).show(); })
		.always(function () { $btn.prop('disabled', false); });
	});

	$(document).on('click', '#am-channel-delete-btn', function () {
		if (!window.confirm(L.confirmRemoveChannel)) return;
		var index = $(this).data('index');
		var $btn  = $(this).prop('disabled', true);

		$.post(amData.ajaxUrl, { action: 'am_delete_channel', index: index, nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				closeModal();
				refreshChannelsTable(r.data.html, r.data.empty);
			}
		})
		.always(function () { $btn.prop('disabled', false); });
	});

	/* Send Test, in a saved channel's edit form: sends one alert through the
	   channel as stored (see AM_Admin::ajax_test_channel()) and shows the
	   outcome under the form. */
	$(document).on('click', '#am-channel-test-btn', function () {
		var $btn    = $(this).prop('disabled', true);
		var $err    = $('#am-channel-modal-error').hide();
		var $status = $('#am-channel-modal-status').text(L.sendingTest).show();

		$.post(amData.ajaxUrl, { action: 'am_test_channel', index: $btn.data('index'), nonce: amData.nonce })
		.done(function (r) {
			var message = (r.data && r.data.message) ? r.data.message : L.error;
			if (r.success) {
				$status.text(message);
			} else {
				$status.hide();
				$err.text(message).show();
			}
		})
		.fail(function () { $status.hide(); $err.text(L.requestFailed).show(); })
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

	/* One refresh of either container. Three things it does beyond the swap:
	   - am-busy dims the container and blocks clicks until the answer is in
	     (see admin.css), so a click visibly did something.
	   - Anything short of a rendered result falls back to loading the same
	     URL as an ordinary page. That covers a tab left open past its nonce's
	     lifetime, a session that has ended (the page load lands on the login
	     screen), and a failed request -- all of which used to leave the click
	     doing nothing at all, silently.
	   - A reply that a later click has already overtaken is dropped, so a
	     slow first answer can't overwrite the second. */
	var amRefreshSeq = {};

	function amRefresh(action, selector, qs, pushUrl) {
		var $box = $(selector).addClass('am-busy').attr('aria-busy', 'true');
		var seq  = amRefreshSeq[action] = (amRefreshSeq[action] || 0) + 1;

		function fallBack() {
			if (seq !== amRefreshSeq[action]) return;
			// From a link or form: go where it pointed. From back/forward:
			// the address bar already holds the target, so reload it.
			if (pushUrl) {
				window.location.assign(window.location.pathname + '?' + qs);
			} else {
				window.location.reload();
			}
		}

		$.post(amData.ajaxUrl, { action: action, qs: qs, nonce: amData.nonce })
		.done(function (r) {
			if (seq !== amRefreshSeq[action]) return;
			if (!r || !r.success || !r.data) { fallBack(); return; }
			$box.html(r.data.html).removeClass('am-busy').removeAttr('aria-busy');
			if (pushUrl) amPushState(qs);
		})
		.fail(fallBack);
	}

	function amRefreshLog(qs, pushUrl) {
		amRefresh('am_log_table', '#am-log-app', qs, pushUrl);
	}

	function amRefreshStats(qs, pushUrl) {
		amRefresh('am_stats_content', '#am-stats-content', qs, pushUrl);
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

	/* Ledger Console theme toggle. Two things need to flip together, both
	   read by admin.css's token block: .am-wrap's data-am-theme attribute
	   (drives the hardcoded per-severity dark colors that aren't tokenized)
	   and a class on <body> (drives every var(--am-*) token, including the
	   ones #wpcontent/#wpbody/#wpbody-content read -- those are ANCESTORS
	   of .am-wrap, so they can only pick up a class placed on an element
	   that contains them, i.e. <body>, never an attribute set further down
	   the tree on .am-wrap alone). AM_Admin::filter_admin_body_class() sets
	   the same class server-side on the next full page load; this only
	   needs to keep the *current* page in sync until then. Saves over AJAX
	   -- see AM_Admin::ajax_save_theme(). Delegated on document, not bound
	   directly to the button: the toggle itself sits inside
	   render_screen_open(), outside #am-log-app/#am-stats-content, so it's
	   never replaced by an AJAX refresh, but delegation costs nothing and
	   matches every other handler in this file. */
	$(document).on('click', '.am-theme-toggle', function () {
		var $wrap = $(this).closest('.am-wrap');
		var next = $wrap.attr('data-am-theme') === 'dark' ? 'light' : 'dark';
		$wrap.attr('data-am-theme', next);
		$('body').toggleClass('am-theme-dark', next === 'dark').toggleClass('am-theme-light', next === 'light');
		$(this).attr('aria-pressed', next === 'dark' ? 'true' : 'false');
		$(this).find('.am-tt-label').text(next === 'dark' ? L.dark : L.light);
		$.post(amData.ajaxUrl, { action: 'am_save_theme', theme: next, nonce: amData.nonce });
	});

	/* Style picker, next to the light/dark toggle. A style is a token set keyed
	   on an am-style-<slug> class on <body> (same ancestor-reach reason as
	   am-theme-dark above), so switching swaps that one class and saves over
	   AJAX -- see AM_Admin::ajax_save_style(). */
	$(document).on('change', '.am-style-select', function () {
		var next = $(this).val();
		var $body = $('body');
		$body.attr('class', $body.attr('class').replace(/\bam-style-\S+/g, '').replace(/\s+/g, ' ') + ' am-style-' + next);
		$.post(amData.ajaxUrl, { action: 'am_save_style', style: next, nonce: amData.nonce });
	});

}(jQuery));
