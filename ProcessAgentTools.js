/**
 * ProcessAgentTools admin JS
 *
 */
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
		var $btn = $(this).find('[name="submit_engineer"]');
		// Disabled fields are excluded from POST, so preserve the value via hidden input
		$('<input>').attr({ type: 'hidden', name: $btn.attr('name'), value: $btn.val() }).appendTo(this);
		$btn.prop('disabled', true).find('i.fa').attr('class', 'fa fa-fw fa-spinner fa-spin');
		
		var $thinking = $('#thinking');
		function fadeIn() { $thinking.fadeIn('slow', function() { fadeOut(); }); }
		function fadeOut() { $thinking.fadeOut('slow', function() { fadeIn(); }); }
		$thinking.prop('hidden', false);
		fadeIn();
	});
	
	
});
