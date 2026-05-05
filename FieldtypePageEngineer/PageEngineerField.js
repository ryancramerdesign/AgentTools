/**
 * FieldtypePageEngineer page-editor JS
 * 
 * Preview overlay: PageEngineer.showProcessingOverlay()
 *
 */

var PageEngineer = {
	showProcessingOverlay: function() {
		if($('#at-processing-overlay').length) return; // already visible
		var cfg = ProcessWire.config.FieldtypePageEngineer || {};
		var words = cfg.thinkingWords || [];
		var thinkingHtml = '';
		if(words.length >= 2) {
			var idx1 = Math.floor(Math.random() * words.length);
			var idx2;
			do { idx2 = Math.floor(Math.random() * words.length); } while(idx2 === idx1);
			thinkingHtml = '<p class="at-thinking-words" style="display:none">' + words[idx1] + ' and ' + words[idx2] + '\u2026</p>';
		}
		$('body').append(
			'<div id="at-processing-overlay">' +
				'<div id="at-processing-box">' +
					'<div uk-spinner="ratio: 2"></div>' +
					'<p><strong>' + (cfg.processingText || 'Saving page and processing Engineer request\u2026') + '</strong></p>' +
					thinkingHtml +
					'<p class="at-processing-note">' + (cfg.timeoutText || 'Please be patient, this may take a minute. If you see a server error, the Engineer is still working \u2014 reload the page before resubmitting.') + '</p>' +
				'</div>' +
			'</div>'
		);
		if(thinkingHtml) {
			var $tw = $('#at-processing-box .at-thinking-words');
			function atPickWords() {
				var idx1 = Math.floor(Math.random() * words.length);
				var idx2;
				do { idx2 = Math.floor(Math.random() * words.length); } while(idx2 === idx1);
				$tw.text(words[idx1] + ' and ' + words[idx2] + '\u2026');
			}
			function atFadeIn() { $tw.fadeIn('slow', function() { setTimeout(atFadeOut, 3000); }); }
			function atFadeOut() { $tw.fadeOut('slow', function() { atPickWords(); atFadeIn(); }); }
			atFadeIn();
		}
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
		}, 0);
	});
});
