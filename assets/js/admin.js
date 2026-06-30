/* Activity Monitor — Admin JS v1.1.0 */
(function ($) {
	'use strict';

	// ── Shared modal helper ──────────────────────────────────────────────────

	function openModal() {
		$('#am-modal-overlay').show();
		$('#am-modal-body').html('<p class="am-loading">Loading\u2026</p>');
	}

	// ── Event Detail Modal ───────────────────────────────────────────────────

	$(document).on('click', '.am-view-detail', function () {
		var id = $(this).data('id');
		openModal();

		$.post(amData.ajaxUrl, {
			action:   'am_get_event_detail',
			entry_id: id,
			nonce:    amData.nonce
		})
		.done(function (response) {
			$('#am-modal-body').html(
				response.success ? response.data.html : '<p>Could not load details.</p>'
			);
		})
		.fail(function () {
			$('#am-modal-body').html('<p>Request failed.</p>');
		});
	});

	$(document).on('click', '.am-view-session-detail', function () {
		var $btn = $(this);
		openModal();

		$.post(amData.ajaxUrl, {
			action:       'am_get_session_detail',
			nonce:        amData.nonce,
			user_id:      $btn.data('user-id'),
			user_login:   $btn.data('user-login'),
			display_name: $btn.data('display-name'),
			token_hash:   $btn.data('token-hash'),
			login_ts:     $btn.data('login-ts'),
			expiry_ts:    $btn.data('expiry-ts'),
			ip:           $btn.data('ip'),
			ua:           $btn.data('ua'),
			is_current:   $btn.data('is-current')
		})
		.done(function (response) {
			$('#am-modal-body').html(
				response.success ? response.data.html : '<p>Could not load details.</p>'
			);
		})
		.fail(function () {
			$('#am-modal-body').html('<p>Request failed.</p>');
		});
	});

	// Close modal on overlay click or close button.
	$('#am-modal-close').on('click', function () {
		$('#am-modal-overlay').hide();
	});

	$('#am-modal-overlay').on('click', function (e) {
		if (e.target === this) {
			$(this).hide();
		}
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') {
			$('#am-modal-overlay').hide();
		}
	});

	// ── Notification channel builder ─────────────────────────────────────────

	// Track the index counter from however many cards are already rendered.
	var channelIndex = $('#am-channels-list .am-channel-card').length;

	function addChannel(type) {
		var $template = $('#am-template-' + type);
		if (!$template.length) { return; }

		var html = $template.html().replace(/__INDEX__/g, channelIndex);
		channelIndex++;
		$('#am-channels-list').append(html);
	}

	$('#am-add-email').on('click', function () { addChannel('email'); });
	$('#am-add-slack').on('click', function () { addChannel('slack'); });

	$(document).on('click', '.am-remove-channel', function () {
		$(this).closest('.am-channel-card').remove();
	});

	// ── Event type filter — auto-submit on change ────────────────────────────
	$(document).on('change', 'select[name="am_type"]', function () {
		$(this).closest('form').submit();
	});

}(jQuery));
