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

function initAgentsForm() {
	var $form = $('#at-agents-form');
	var $inputfields = $form.children('.Inputfields').eq(0);
	if(!$form.length) return;
	
	$inputfields.sortable({
		items: '> .at-agent-item:not(.at-agent-new)',
		handle: '.fa-arrows',
		axis: 'y',
		start: function(e, ui) {
			ui.item.addClass('InputfieldIsHighlight at-sorting');
		},
		stop: function(e, ui) {
			ui.item.removeClass('InputfieldIsHighlight at-sorting');
		},
		update: function(e, ui) {
			ui.item.closest('form').addClass('InputfieldStateChanged');
			$inputfields.children().each(function(n) {
				var $item = $(this);
				if(!$item.hasClass('at-agent-item')) return;
				if($item.hasClass('at-agent-new') || $item.hasClass('at-agent-deleting')) return;
				$item.find('.at-agent-sort').val(n+1);
			});
		}
	});
	
	// apply "ui-state-focus" class when an item is being dragged
	var cls = 'InputfieldIsHighlight';
	$(".fa-arrows", $form).on('mouseenter', function() {
		$(this).closest('.Inputfield').addClass(cls);
	}).on('mouseleave', function() {
		var $f = $(this).closest('.Inputfield');
		if(!$f.hasClass('at-sorting')) $f.removeClass(cls);
	});
	
	// Agents configuration: "Add new agent" link
	$('#at-add-agent-link').on('click', function(e) {
		var $newAgents = $('.at-agent-new');
		if($newAgents.length === 0) return;
		$('.at-agent-new').eq(0).prop('hidden', false).removeClass('at-agent-new');
		if($newAgents.length === 1) $('#at-add-agent').prop('hidden', true);
		e.preventDefault();
	});

	// show/hide API key
	$('.at-apiKey').on('at-apikey-show', function(e) {
		$(this).find('input').prop('type', 'text');
	}).on('at-apikey-hide', function(e) {
		$(this).find('input').prop('type', 'password');
	});
	
	$('.at-agent-item').on('at-agent-delete', function(e) {
		var $f = $(this);
		var $del = $f.find('.at-agent-delete');
		if($f.hasClass('at-agent-deleting')) {
			$f.removeClass('at-agent-deleting');
			$del.val('');
		} else {
			$f.addClass('at-agent-deleting');
			var n = $f.attr('data-agent-n');
			$del.val('delete' + n);
			Inputfields.close($f);
		}
	});
	
	$(document).on('at-agent-clone', function(e, $f) {
		var $src = $f.closest('.at-agent-item');
		var $dst = $src.siblings('.at-agent-new').first();
		if(!$dst.length) {
			ProcessWire.alert('Max agents reached');
			return;
		}
		$src.find('input[type!="hidden"]').each(function() {
			var $f = $(this);
			var $fCopy = $dst.find('input[data-name="' + $f.attr('data-name') + '"]').first();
			$fCopy.val($f.val());
		});
		var $dstLabel = $dst.find('input[data-name="label"]');
		$dstLabel.val($dstLabel.val() + ' (copy)');
		$dst.removeClass('at-agent-new').prop('hidden', false);
		Inputfields.open($dst);
		$('html, body').animate({ scrollTop: $dst.offset().top }, 1000);
	});
	
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

	initAgentsForm();
});
