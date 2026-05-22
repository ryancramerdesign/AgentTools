/**
 * ProcessAgentTools admin JS
 *
 */

function atEnsureProcessing() {
	if(window.AgentToolsProcessing) return true;
	var scriptUrl = '';
	$('script[src*="/AgentTools/ProcessAgentTools.js"]').each(function() {
		scriptUrl = this.src;
	});
	if(!scriptUrl) return false;
	var cssUrl = scriptUrl.replace(/ProcessAgentTools\.js.*/, 'processing.css');
	if(!$('link[href*="/AgentTools/processing.css"]').length) {
		$('head').append('<link rel="stylesheet" href="' + cssUrl + '">');
	}
	scriptUrl = scriptUrl.replace(/ProcessAgentTools\.js.*/, 'processing.js');
	$.ajax({ url: scriptUrl, dataType: 'script', async: false, cache: true });
	return !!window.AgentToolsProcessing;
}

var AtTools = {
	escapeHtml: function(text) {
		if(!atEnsureProcessing()) return $('<div>').text(text).html();
		return AgentToolsProcessing.escapeHtml(text);
	},

	showProcessingOverlay: function() {
		if(!atEnsureProcessing()) return;
		AgentToolsProcessing.showOverlay(ProcessWire.config.AgentTools || {});
	}
};

$(function() {

	// Migrations: show/hide checked-action buttons based on checkbox state
	$(document).on('change', '.migration-checkbox', function() {
		var anyChecked = $('.migration-checkbox:checked').length > 0;
		$('#submit_apply_checked, #submit_export_checked, #submit_delete_checked, #submit_review_checked').prop('hidden', !anyChecked);
	});

	$(document).on('click', '#submit_review_checked', function(e) {
		e.preventDefault();
		var checked = $('.migration-checkbox:checked').map(function() {
			return this.value;
		}).get();
		if(!checked.length) return;
		window.location.href = ProcessWire.config.urls.admin + 'setup/agent-tools/run-task/migration-review/?migrations=' + encodeURIComponent(checked.join(','));
	});

	// Migrations: confirm before applying all pending migrations (list page only)
	$(document).on('click', '[name="submit_apply"][data-confirm]', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var $form = $btn.closest('form');
		var message = ProcessWire.config.AgentTools ? ProcessWire.config.AgentTools.confirmApply : 'Apply all pending migrations?';
		ProcessWire.confirm(message, function() {
			$('<input>').attr({ type: 'hidden', name: $btn.attr('name'), value: $btn.val() }).appendTo($form);
			$form.submit();
		}, function() {});
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

	// Engineer/task forms: disable submit button and show spinner while waiting for response
	$('form').has('.at-show-thinking').on('submit', function() {
		var $form = $(this);
		var $btn = $form.find('.at-show-thinking').first();
		var $request = $form.find('.at-engineer-request');
		var hasRequest = $request.length ? !!$request.val().trim() : true;
		// Disabled fields are excluded from POST, so preserve the value via hidden input
		$('<input>').attr({ type: 'hidden', name: $btn.attr('name'), value: $btn.val() }).appendTo(this);
		$btn.prop('disabled', true).find('i.fa').attr('class', 'fa fa-fw fa-spinner fa-spin');

		var $thinking = $('#thinking');
		function fadeIn() { $thinking.fadeIn('slow', function() { fadeOut(); }); }
		function fadeOut() { $thinking.fadeOut('slow', function() { fadeIn(); }); }
		$thinking.prop('hidden', false);
		fadeIn();

		// After 1.5s show the full-screen processing overlay for longer AI requests.
		if(hasRequest) setTimeout(AtTools.showProcessingOverlay, 1500);
	});

	$('.at-apiKey').on('at-apikey-show', function(e) {
		$(this).find('input').prop('type', 'text');
	}).on('at-apikey-hide', function(e) {
		$(this).find('input').prop('type', 'password');
	});

	// populate task title into task name [-_a-z0-9] or update task name on blur
	var $title = $('#task_title');
	if($title.length) {
		var $name = $('#task_name');
		var updateName = $name.val().length === 0;
		$name.on('blur', function() {
			var $input = $(this);
			var val = $input.val();
			// sanitize to page-name format
			val = val.toLowerCase();
			val = val.replace(/[^-_a-z0-9]/g, '-');
			val = val.replace(/\-\-+/g, '-');
			if(val.indexOf('-') === 0) val = val.substr(1);
			if(val.substr(-1) === '-') val = val.substr(0, val.length - 1);
			$input.val(val);
			updateName = false;
		});
		$title.on('blur', function() {
			if(!updateName) return;
			$name.val($title.val());
			$name.trigger('blur');
		});
	}

});
