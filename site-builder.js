(function() {
	'use strict';

	function ready(callback) {
		if(document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
		} else {
			callback();
		}
	}

	function initializePresets() {
		var description = document.getElementById('at-site-builder-description');
		var presetValue = document.getElementById('at-site-builder-preset-value');
		if(!description || !presetValue) return;
		var buttons = document.querySelectorAll('.at-site-builder-preset');
		buttons.forEach(function(button) {
			button.addEventListener('click', function() {
				buttons.forEach(function(item) {
					item.classList.remove('uk-button-primary');
					item.classList.add('uk-button-default');
					item.setAttribute('aria-pressed', 'false');
				});
				button.classList.remove('uk-button-default');
				button.classList.add('uk-button-primary');
				button.setAttribute('aria-pressed', 'true');
				presetValue.value = button.getAttribute('data-preset') || '';
				description.value = button.getAttribute('data-description') || '';
				description.focus();
			});
		});
	}

	function initializeProgress() {
		var root = document.getElementById('at-site-builder-progress');
		if(!root || root.getAttribute('data-auto') !== '1') return;
		var current = document.getElementById('at-site-builder-current');
		var currentText = current ? current.querySelector('span') : null;
		var elapsed = document.getElementById('at-site-builder-elapsed');
		var log = document.getElementById('at-site-builder-log');
		var state = root.querySelector('.at-site-builder-state');
		var stopped = false;
		var failures = 0;
		var startedAt = Date.now();
		var elapsedTimer = null;
		var activityCaption = '';
		var activityStarted = false;
		var text = function(name, fallback) {
			return root.getAttribute('data-' + name) || fallback;
		};
		var updateElapsed = function() {
			if(!elapsed) return;
			var seconds = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
			var duration = seconds < 60 ? seconds + 's' : Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
			elapsed.textContent = '· ' + duration + ' ' + text('text-elapsed', 'elapsed');
		};
		var setCurrent = function(message, active) {
			if(currentText) currentText.textContent = message;
			if(active === false) {
				if(window.PWMeasureActivity && activityStarted) window.PWMeasureActivity.stop();
				activityStarted = false;
				if(elapsedTimer) window.clearInterval(elapsedTimer);
				elapsedTimer = null;
				if(elapsed) elapsed.hidden = true;
			} else {
				if(!activityCaption) activityCaption = message;
				if(window.PWMeasureActivity && !activityStarted) {
					window.PWMeasureActivity.start({
						selectors: [
							'#pw-content-title', '.InputfieldHeader', '.uk-button',
							'#pw-masthead a', '.pw-primary-nav a', 'th', 'td'
						],
						ghosts: [
							{ label: text('measure-modal', 'modal'), w: 480, h: 320 },
							{ label: text('measure-card', 'card'), w: 300, h: 210 },
							{ label: text('measure-sidebar', 'sidebar'), w: 240, h: 420 },
							{ label: text('measure-hero', 'hero'), w: 620, h: 260 },
							{ label: text('measure-field-row', 'field row'), w: 360, h: 64 }
						],
						getCaption: function() { return activityCaption; }
					});
					activityStarted = true;
				}
				if(!elapsed) return;
				elapsed.hidden = false;
				updateElapsed();
				if(!elapsedTimer) elapsedTimer = window.setInterval(updateElapsed, 1000);
			}
		};

		function escapeHtml(value) {
			var node = document.createElement('div');
			node.textContent = value == null ? '' : String(value);
			return node.innerHTML;
		}

		function renderLog(entries) {
			if(!log || !Array.isArray(entries)) return;
			if(!entries.length) {
				log.innerHTML = '<li class="detail">' + escapeHtml(text('text-empty-log', 'No progress has been recorded yet.')) + '</li>';
				return;
			}
			activityCaption = String(entries[entries.length - 1].message || '');
			log.innerHTML = entries.map(function(entry) {
				var date = entry.timestamp ? new Date(Number(entry.timestamp) * 1000) : null;
				var time = date ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '';
				var css = String(entry.type || '').indexOf('error') > -1 ? ' class="at-site-builder-log-error"' : '';
				return '<li' + css + '><time>' + escapeHtml(time) + '</time> ' + escapeHtml(entry.message || '') + '</li>';
			}).join('');
			log.scrollTop = log.scrollHeight;
		}

		function renderState(data) {
			if(!state || !data) return;
			var phase = String(data.phase || '');
			phase = phase ? phase.charAt(0).toUpperCase() + phase.slice(1) : '';
			var status = String(data.status || '');
			var tokens = data.tokenUsage && data.tokenUsage.total ? Number(data.tokenUsage.total).toLocaleString() : '0';
			state.innerHTML = '<strong>' + escapeHtml(text('label-phase', 'Phase')) + ':</strong> ' + escapeHtml(phase) +
				' &nbsp; <span class="uk-label">' + escapeHtml(status) + '</span> ' +
				'<span class="detail">' + escapeHtml(text('label-round', 'Round')) + ' ' + escapeHtml(data.round || 0) + ' · ' + escapeHtml(tokens) + ' ' + escapeHtml(text('label-tokens', 'tokens')) + '</span>';
		}

		function terminal(status) {
			return ['awaiting-approval', 'paused', 'error', 'done', 'reset'].indexOf(status) > -1;
		}

		function schedule(delay) {
			if(!stopped) window.setTimeout(runStep, delay);
		}

		function runStep() {
			if(stopped) return;
			setCurrent(failures ? text('text-retrying', 'Connection interrupted. Retrying…') : text('text-working', 'Working on the next step…'), true);
			var body = new URLSearchParams();
			body.set('id', root.getAttribute('data-id') || '');
			body.set(root.getAttribute('data-csrf-name') || '', root.getAttribute('data-csrf-value') || '');
			fetch(root.getAttribute('data-endpoint') || '', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
				body: body.toString()
			}).then(function(response) {
				if(!response.ok) {
					var responseError = new Error('HTTP ' + response.status);
					responseError.retry = response.status >= 500;
					throw responseError;
				}
				return response.text().then(function(value) {
					try {
						return JSON.parse(value);
					} catch(parseError) {
						parseError.message = 'The server interrupted this step.';
						parseError.retry = true;
						throw parseError;
					}
				});
			}).then(function(data) {
				if(!data.ok) {
					var stepError = new Error(data.error || 'Site Builder step failed.');
					stepError.retry = false;
					throw stepError;
				}
				failures = 0;
				renderState(data.state);
				renderLog(data.log);
				var status = String(data.state && data.state.status || 'error');
				if(terminal(status)) {
					stopped = true;
					setCurrent(text('text-updating', 'Step complete. Updating the screen…'), false);
					window.setTimeout(function() { window.location.reload(); }, 350);
					return;
				}
				setCurrent(status === 'busy' ? text('text-busy', 'Another step is still finishing…') : text('text-continuing', 'Step complete. Continuing…'), true);
				schedule(status === 'busy' ? 1500 : 250);
			}).catch(function(error) {
				if(error.retry === false) {
					stopped = true;
					if(current) {
						setCurrent(error.message || text('text-stopped', 'The build stopped because the request could not be completed.'), false);
						current.classList.add('at-site-builder-error');
					}
					return;
				}
				failures++;
				if(failures >= 6) {
					stopped = true;
					setCurrent(text('text-retry-stopped', 'The server interrupted several attempts. Refresh this page to try recovering again.'), false);
					if(current) current.classList.add('at-site-builder-error');
					return;
				}
				var delay = Math.min(10000, 1000 * Math.pow(2, Math.min(failures - 1, 4)));
				setCurrent((error.message || 'Connection interrupted.') + ' ' + text('text-retry', 'Retrying…'), true);
				schedule(delay);
			});
		}

		window.addEventListener('beforeunload', function() {
			stopped = true;
			if(window.PWMeasureActivity && activityStarted) window.PWMeasureActivity.stop();
			if(elapsedTimer) window.clearInterval(elapsedTimer);
		});
		runStep();
	}

	ready(function() {
		initializePresets();
		initializeProgress();
	});
})();
