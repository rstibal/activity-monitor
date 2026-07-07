/* Activity Monitor — Admin JS v1.1.0 */
(function ($) {
	'use strict';

	function openModal() {
		$('#am-modal-overlay').show();
		$('#am-modal-body').html('<p class="am-loading">Loading…</p>');
	}

	$(document).on('click', '.am-view-detail', function () {
		var id = $(this).data('id');
		openModal();
		$.post(amData.ajaxUrl, { action: 'am_get_event_detail', entry_id: id, nonce: amData.nonce })
		.done(function (r) { $('#am-modal-body').html(r.success ? r.data.html : '<p>Error.</p>'); })
		.fail(function () { $('#am-modal-body').html('<p>Request failed.</p>'); });
	});

	/* FIX #2: Only pass user_id + token_hash to the session detail handler.
	   All other data is now re-fetched server-side from session_tokens. */
	$(document).on('click', '.am-view-session-detail', function () {
		var $btn = $(this);
		openModal();
		$.post(amData.ajaxUrl, {
			action:     'am_get_session_detail',
			nonce:      amData.nonce,
			user_id:    $btn.data('user-id'),
			token_hash: $btn.data('token-hash')
		})
		.done(function (r) { $('#am-modal-body').html(r.success ? r.data.html : '<p>Error.</p>'); })
		.fail(function () { $('#am-modal-body').html('<p>Request failed.</p>'); });
	});

	$('#am-modal-close').on('click', function () { $('#am-modal-overlay').hide(); });
	$('#am-modal-overlay').on('click', function (e) { if (e.target === this) $(this).hide(); });
	$(document).on('keydown', function (e) { if (e.key === 'Escape') $('#am-modal-overlay').hide(); });

	var channelIndex = $('#am-channels-list .am-channel-card').length;
	function addChannel(type) {
		var $tpl = $('#am-template-' + type);
		if (!$tpl.length) return;
		var html = $tpl.html().replace(/__INDEX__/g, channelIndex++);
		$('#am-channels-list').append(html);
	}
	$('#am-add-email').on('click', function () { addChannel('email'); });
	$('#am-add-slack').on('click', function () { addChannel('slack'); });
	$(document).on('click', '.am-remove-channel', function () { $(this).closest('.am-channel-card').remove(); });
	$(document).on('change', 'select[name="am_type"]', function () { $(this).closest('form').submit(); });
}(jQuery));
