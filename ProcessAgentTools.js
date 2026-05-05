/**
 * ProcessAgentTools admin JS
 *
 */

var AtTools = {
	showProcessingOverlay: function() {
		if($('#at-processing-overlay').length) return; // already visible
		var cfg = ProcessWire.config.AgentTools || {};
		$('body').append(
			'<div id="at-processing-overlay">' +
				'<div id="at-processing-box">' +
					'<div uk-spinner="ratio: 2"></div>' +
					'<p><strong>' + (cfg.processingText || 'Still processing\u2026') + '</strong></p>' +
					'<p class="at-processing-note">' + (cfg.timeoutText || 'If you see a server error, reload the page before resubmitting.') + '</p>' +
				'</div>' +
			'</div>'
		);
	}
};

$(function() {

	// Migrations: show/hide checked-action buttons based on checkbox state
	$(document).on('change', '.migration-checkbox', function() {
		var anyChecked = $('.migration-checkbox:checked').length > 0;
		$('#submit_apply_checked, #submit_delete_checked').prop('hidden', !anyChecked);
	});

	// Migrations: confirm before deleting checked migrations
	$(document).on('click', '[name="submit_delete_checked"]', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var $form = $btn.closest('form');
		var message = ProcessWire.config.AgentTools ? ProcessWire.config.AgentTools.confirmDelete : 'Are you sure?';
		ProcessWire.confirm(message, function() {
			$('<input>').attr({ type: 'hidden', name: $btn.attr('name'), value: $btn.val() }).appendTo($form);
			$form.submit();
		}, function() {});
	});

	// Engineer form: disable submit button and show spinner while waiting for response
	$('form').has('[name="submit_engineer"]').on('submit', function() {
		var $form = $(this);
		var $btn = $form.find('[name="submit_engineer"]');
		var hasRequest = !!$form.find('[name="engineer_request"]').val().trim();
		// Disabled fields are excluded from POST, so preserve the value via hidden input
		$('<input>').attr({ type: 'hidden', name: $btn.attr('name'), value: $btn.val() }).appendTo(this);
		$btn.prop('disabled', true).find('i.fa').attr('class', 'fa fa-fw fa-spinner fa-spin');

		var $thinking = $('#thinking');
		function fadeIn() { $thinking.fadeIn('slow', function() { fadeOut(); }); }
		function fadeOut() { $thinking.fadeOut('slow', function() { fadeIn(); }); }
		$thinking.prop('hidden', false);
		fadeIn();

		// After 20s show a full-screen overlay warning that the request is taking a while.
		// This gives the user ~10s of advance notice before the typical 30s FastCGI timeout.
		if(hasRequest) setTimeout(AtTools.showProcessingOverlay, 20000);
	});

	$('.at-apiKey').on('at-apikey-show', function(e) {
		$(this).find('input').prop('type', 'text'); 
	}).on('at-apikey-hide', function(e) {
		$(this).find('input').prop('type', 'password');
	});
	
});
