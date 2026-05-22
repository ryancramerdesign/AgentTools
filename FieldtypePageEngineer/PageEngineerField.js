/**
 * FieldtypePageEngineer page-editor JS
 *
 * Preview overlay: PageEngineer.showProcessingOverlay()
 *
 */

function atEnsureProcessing() {
	if(window.AgentToolsProcessing) return true;
	var scriptUrl = '';
	$('script[src*="/FieldtypePageEngineer/PageEngineerField.js"]').each(function() {
		scriptUrl = this.src;
	});
	if(!scriptUrl) return false;
	var cssUrl = scriptUrl.replace(/FieldtypePageEngineer\/PageEngineerField\.js.*/, 'processing.css');
	if(!$('link[href*="/AgentTools/processing.css"]').length) {
		$('head').append('<link rel="stylesheet" href="' + cssUrl + '">');
	}
	scriptUrl = scriptUrl.replace(/FieldtypePageEngineer\/PageEngineerField\.js.*/, 'processing.js');
	$.ajax({ url: scriptUrl, dataType: 'script', async: false, cache: true });
	return !!window.AgentToolsProcessing;
}

var PageEngineer = {
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
	// Show a processing overlay immediately on form submit when the Page Engineer
	// textarea has content, so the editor knows their request is being processed.
	$(document).on('submit', 'form:has(.PageEngineerInput)', function(e) {
		var val = $(this).find('.PageEngineerInput textarea').val();
		if(!val || !val.trim()) return;
		// Defer to let all other submit handlers run first, then check if the
		// submit was cancelled before showing the overlay.
		setTimeout(function() {
			if(e.defaultPrevented) return;
			PageEngineer.showProcessingOverlay();
		}, 1500);
	});
});
