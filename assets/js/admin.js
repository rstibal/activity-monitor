/* Activity Monitor — Admin JS v1.1.0 */
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
	   have been removed entirely. */
	$(document).on('click', '.am-view-detail-v2', function () {
		var id = $(this).data('id');
		openModal('Event Details');
		$.post(amData.ajaxUrl, { action: 'am_get_v2_event_detail', entry_id: id, nonce: amData.nonce })
		.done(function (r) { $('#am-modal-body').html(r.success ? r.data.html : '<p>Error.</p>'); })
		.fail(function () { $('#am-modal-body').html('<p>Request failed.</p>'); });
	});

	/* FIX #2: Only pass user_id + token_hash to the session detail handler.
	   All other data is now re-fetched server-side from session_tokens. */
	$(document).on('click', '.am-view-session-detail', function () {
		var $btn = $(this);
		openModal('Session Details');
		$.post(amData.ajaxUrl, {
			action:     'am_get_session_detail',
			nonce:      amData.nonce,
			user_id:    $btn.data('user-id'),
			token_hash: $btn.data('token-hash')
		})
		.done(function (r) { $('#am-modal-body').html(r.success ? r.data.html : '<p>Error.</p>'); })
		.fail(function () { $('#am-modal-body').html('<p>Request failed.</p>'); });
	});

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

	/* Page/hit detail modal. Triggered by clicking the Details button in
	   the Traffic tab's live feed's Actions column -- shows referrer,
	   visitor, IP, and browser for that specific page view (fields the
	   live feed row itself doesn't have room for). The Page column
	   itself is now a plain link straight to the actual page (opens in
	   a new tab), separate from this. Fetches by hit id via
	   ajax_traffic_hit_detail() rather than passing the row's data
	   through the DOM, matching how the other detail modals work. */
	$(document).on('click', '.am-traffic-hit-detail', function (e) {
		e.preventDefault();
		var id = $(this).data('id');
		var url = $(this).data('url');
		if (!id) return;
		openModal(url ? ('Page View: ' + url) : 'Page View Details');
		$.post(amData.ajaxUrl, { action: 'am_traffic_hit_detail', id: id, nonce: amData.nonce })
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
		openModal($(this).text());
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

	/* Digest preview + test send (spec §4). Preview loads the same HTML
	   AM_Digest::send()/send_test() would actually mail, rendered inline
	   via an iframe so the layout/styling can be seen accurately without
	   sending anything. */
	$('#am-digest-preview').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		var frequency = $('#am-digest-preview-frequency').val();
		$.post(amData.ajaxUrl, { action: 'am_digest_preview', nonce: amData.nonce, frequency: frequency })
		.done(function (r) {
			if (r.success) {
				var frame = document.getElementById('am-digest-preview-frame');
				frame.srcdoc = r.data.html;
				$('#am-digest-preview-frame-wrap').show();
			}
		})
		.always(function () { $btn.prop('disabled', false); });
	});

	$('#am-digest-send-test').on('click', function () {
		var $btn   = $(this).prop('disabled', true);
		var email  = $('#am-digest-test-email').val();
		var frequency = $('#am-digest-preview-frequency').val();
		var $result = $('#am-digest-test-result');
		$result.text('');
		$.post(amData.ajaxUrl, { action: 'am_digest_send_test', nonce: amData.nonce, email: email, frequency: frequency })
		.done(function (r) {
			$result.text(r.success ? r.data.message : (r.data && r.data.message ? r.data.message : 'Error.'));
		})
		.fail(function () { $result.text('Request failed.'); })
		.always(function () { $btn.prop('disabled', false); });
	});

	/* Email Digest management (Settings > Alerts & Reports). Same
	   add/edit/save/delete-via-modal pattern as notification channels
	   above -- see that block's comment for the shared reasoning. One
	   digest-specific addition: the "Day of week" field only applies
	   to a weekly digest, so it's shown/hidden as the Frequency select
	   changes, both right after the modal form loads and live as the
	   person changes it. */
	function openDigestModal(id) {
		openModal('Loading…');
		$.post(amData.ajaxUrl, { action: 'am_digest_config_form', id: (id === undefined ? '' : id), nonce: amData.nonce })
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

	$(document).on('click', '.am-add-digest-btn', function () { openDigestModal(); });
	$(document).on('click', '.am-edit-digest-btn', function () { openDigestModal($(this).data('id')); });

	$(document).on('change', '#am-digest-modal-frequency', function () {
		$('#am-digest-modal-day-row').toggle($(this).val() === 'weekly');
	});

	function refreshDigestTable(html, isEmpty) {
		if (isEmpty) {
			// Same reasoning as refreshChannelsTable()'s empty-state
			// case: deleting the last remaining digest should be rare
			// enough that a full reload (to show the section's empty
			// state) isn't worth a separate client-side markup swap.
			window.location.reload();
			return;
		}
		var $tbody = $('#am-digest-table-body');
		if ($tbody.length) {
			$tbody.html(html);
		} else {
			// First digest just added and there was no table yet.
			window.location.reload();
		}
	}

	$(document).on('submit', '#am-digest-modal-form', function (e) {
		e.preventDefault();
		var $form = $(this);
		var $btn  = $('#am-digest-save-btn').prop('disabled', true);
		var $err  = $('#am-digest-modal-error').hide();

		$.post(amData.ajaxUrl, $form.serialize() + '&action=am_save_digest_config&nonce=' + amData.nonce)
		.done(function (r) {
			if (r.success) {
				$('#am-modal-overlay').hide();
				refreshDigestTable(r.data.html, false);
			} else {
				$err.text((r.data && r.data.message) ? r.data.message : 'Could not save. Check the values and try again.').show();
			}
		})
		.fail(function () { $err.text('Request failed.').show(); })
		.always(function () { $btn.prop('disabled', false); });
	});

	$(document).on('click', '#am-digest-delete-btn', function () {
		if (!window.confirm('Remove this digest?')) return;
		var id   = $(this).data('id');
		var $btn = $(this).prop('disabled', true);

		$.post(amData.ajaxUrl, { action: 'am_delete_digest_config', id: id, nonce: amData.nonce })
		.done(function (r) {
			if (r.success) {
				$('#am-modal-overlay').hide();
				refreshDigestTable(r.data.html, r.data.empty);
			}
		})
		.always(function () { $btn.prop('disabled', false); });
	});

	/* Live traffic feed (Traffic tab). Polls am_get_live_traffic at the
	   interval/limit the person configured in Settings (localized as
	   trafficLivePollMs / trafficLiveFeedLimit). Only runs when the
	   feed table is actually on the page, and stops itself once the
	   element is gone (e.g. the person switched tabs and the page
	   re-rendered) rather than leaving an orphaned timer running. */
	var $liveBody = $('#am-live-traffic-body');
	if ($liveBody.length) {
		var lastSeenId = 0;
		var pollMs = (amData.trafficLivePollMs > 0) ? amData.trafficLivePollMs : 10000;

		function escapeHtml(s) {
			return $('<div>').text(s == null ? '' : s).html();
		}

		function pollLiveTraffic() {
			if (!$('#am-live-traffic-body').length) {
				return; // Left the Traffic tab; stop polling.
			}
			$.post(amData.ajaxUrl, { action: 'am_get_live_traffic', nonce: amData.nonce, after_id: lastSeenId })
			.done(function (r) {
				if (!r.success) return;

				$('#am-traffic-today-count').text(r.data.today_total);

				var hits = r.data.hits || [];
				if (hits.length) {
					// Server returns newest-first; prepend in that same
					// order so the newest hit ends up at the very top.
					var rows = hits.map(function (h) {
						var ipCell   = '<a href="#" class="am-ip-lookup" data-ip="' + escapeHtml(h.ip) + '">' + escapeHtml(h.ip) + '</a>';
						var pageCell = '<a href="' + escapeHtml(h.full_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(h.url) + '</a>';
						var actionsCell = '<button class="button button-small am-traffic-hit-detail" data-id="' + escapeHtml(h.id) + '" data-url="' + escapeHtml(h.url) + '">Details</button>';
						return '<tr><td>' + escapeHtml(h.time) + '</td>' +
							'<td class="am-page-cell" title="' + escapeHtml(h.url) + '">' + pageCell + '</td>' +
							'<td>' + escapeHtml(h.user) + '</td>' +
							'<td class="am-ip-cell" title="' + escapeHtml(h.ip) + '">' + ipCell + '</td>' +
							'<td>' + actionsCell + '</td></tr>';
					}).join('');

					var $body = $('#am-live-traffic-body');
					if ($body.find('td[colspan]').length) {
						$body.empty(); // Clear the "waiting" placeholder row.
					}
					$body.prepend(rows);

					var limit = amData.trafficLiveFeedLimit || 25;
					$body.find('tr').slice(limit).remove();

					lastSeenId = Math.max.apply(null, hits.map(function (h) { return h.id; }));
				}
			})
			.always(function () {
				setTimeout(pollLiveTraffic, pollMs);
			});
		}

		pollLiveTraffic();
	}
}(jQuery));
