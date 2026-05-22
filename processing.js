/**
 * AgentTools shared processing overlay
 *
 */

var AgentToolsProcessing = {
	formulas: [
		'E = mc^2',
		'F = ma',
		'O(n log n)',
		'$pages->find()',
		'$fields->get()',
		'$templates->save()',
		'sanitize(input)',
		'schema -> migration',
		'cache.clear()',
		'API.md -> answer',
		'if($page->id)',
		'foreach($items as $item)',
		'\u0394x / \u0394t',
		'\u03a3 fields',
		'\u03bb = data',
		'\u03c0 r^2',
		'\u221a(site)',
		'prompt -> tools -> result',
		'ProcessWire.alert("patience")',
		'∫ insight',
		'Σ migrations',
	],

	initialized: false,

	init: function() {
		if(this.initialized) return;
		var cfg = ProcessWire.config.AgentTools || {};
		var formulas = cfg.formulas || [];
		for(var n = 0; n < formulas.length; n++) {
			AgentToolsProcessing.addFormula(formulas[n]);
		}
		this.initialized = true;
	},

	escapeHtml: function(text) {
		return $('<div>').text(text).html();
	},

	addFormula: function(formula) {
		AgentToolsProcessing.formulas.push(formula);
	},

	startCanvas: function(canvas) {
		if(!canvas) return;
		if(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

		var ctx = canvas.getContext('2d');
		if(!ctx) return;

		var particles = [];
		var width = 0;
		var height = 0;
		var dpr = 1;
		var lastTime = 0;
		var running = true;
		var lightStreaks = [];
		var formulas = AgentToolsProcessing.formulas;

		function resize() {
			dpr = Math.min(window.devicePixelRatio || 1, 1.35);
			width = window.innerWidth;
			height = window.innerHeight;
			canvas.width = Math.round(width * dpr);
			canvas.height = Math.round(height * dpr);
			canvas.style.width = width + 'px';
			canvas.style.height = height + 'px';
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
			lightStreaks = [];
			for(var n = 0; n < 3; n++) {
				lightStreaks.push({
					x: Math.random() * width,
					speed: 12 + Math.random() * 18,
					width: 12 + n * 7
				});
			}
		}

		function resetParticle(p, initial) {
			var depth = initial ? Math.random() : 0.05;
			p.text = formulas[Math.floor(Math.random() * formulas.length)];
			p.x = (Math.random() - 0.5) * width * 1.8;
			p.y = (Math.random() - 0.5) * height * 1.8;
			p.depth = depth;
			p.speed = 0.12 + Math.random() * 0.28;
			p.size = 13 + Math.random() * 19;
			p.angle = (Math.random() - 0.5) * 0.35;
			p.drift = (Math.random() - 0.5) * 14;
			p.glow = Math.random() > 0.82;
		}

		function buildParticles() {
			var count = Math.max(18, Math.min(42, Math.floor((width * height) / 32000)));
			particles = [];
			for(var n = 0; n < count; n++) {
				var p = {};
				resetParticle(p, true);
				particles.push(p);
			}
		}

		function drawStreaks(time) {
			ctx.save();
			ctx.globalCompositeOperation = 'screen';
			ctx.strokeStyle = 'rgba(255,255,255,0.055)';
			for(var n = 0; n < lightStreaks.length; n++) {
				var streak = lightStreaks[n];
				var x = (streak.x + time * 0.001 * streak.speed) % (width * 1.25) - width * 0.12;
				ctx.lineWidth = streak.width;
				ctx.beginPath();
				ctx.moveTo(x, -height * 0.1);
				ctx.lineTo(x + width * 0.35, height * 1.1);
				ctx.stroke();
			}
			ctx.restore();
		}

		function draw(time) {
			if(!running || !document.body.contains(canvas)) return;
			var elapsed = Math.min((time - lastTime) || 16, 48);
			lastTime = time;

			ctx.clearRect(0, 0, width, height);
			drawStreaks(time);
			ctx.save();
			ctx.translate(width / 2, height / 2);

			for(var n = 0; n < particles.length; n++) {
				var p = particles[n];
				p.depth += p.speed * elapsed / 1000;
				if(p.depth >= 1.2) {
					resetParticle(p, false);
					continue;
				}

				var scale = 0.25 + p.depth * 2.4;
				var x = p.x * scale + Math.sin(time / 1100 + n) * p.drift;
				var y = p.y * scale + Math.cos(time / 1300 + n) * p.drift;
				var alpha = Math.sin(Math.min(p.depth, 1) * Math.PI) * 0.72;
				if(Math.abs(x) > width * 0.72 || Math.abs(y) > height * 0.72) alpha *= 0.45;

				ctx.save();
				ctx.translate(x, y);
				ctx.rotate(p.angle);
				ctx.font = (p.size * scale) + 'px Menlo, Monaco, Consolas, monospace';
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				ctx.globalAlpha = alpha;
				ctx.shadowColor = p.glow ? 'rgba(112, 190, 255, 0.55)' : 'rgba(255,255,255,0.22)';
				ctx.shadowBlur = p.glow ? 10 : 3;
				ctx.fillStyle = p.glow ? 'rgba(190, 225, 255, 0.95)' : 'rgba(255,255,255,0.9)';
				ctx.fillText(p.text, 0, 0);
				ctx.restore();
			}

			ctx.restore();
			window.requestAnimationFrame(draw);
		}

		resize();
		buildParticles();
		$(window).on('resize.atThinkingCanvas', function() {
			resize();
			buildParticles();
		});
		$(canvas).data('at-stop-thinking-canvas', function() {
			running = false;
			$(window).off('resize.atThinkingCanvas');
		});
		window.requestAnimationFrame(draw);
	},

	showOverlay: function(cfg) {
		this.init();
		if($('#at-processing-overlay').length) return;
		cfg = cfg || {};
		var words = cfg.thinkingWords || [];
		var thinkingHtml = '';
		if(words.length >= 2) {
			var idx1 = Math.floor(Math.random() * words.length);
			var idx2;
			do { idx2 = Math.floor(Math.random() * words.length); } while(idx2 === idx1);
			thinkingHtml = '<p class="at-thinking-words" style="display:none">' + AgentToolsProcessing.escapeHtml(words[idx1] + ' and ' + words[idx2] + '\u2026') + '</p>';
		}
		var processingText = AgentToolsProcessing.escapeHtml(cfg.processingText || 'Processing\u2026');
		var timeoutText = AgentToolsProcessing.escapeHtml(cfg.timeoutText || 'If you see a server error, reload the page before resubmitting.');
		var timeoutDelay = typeof cfg.timeoutDelay === 'number' ? cfg.timeoutDelay : 20000;
		$('body').append(
			'<div id="at-processing-overlay">' +
				'<canvas id="at-thinking-canvas" aria-hidden="true"></canvas>' +
				'<div id="at-processing-box">' +
					'<p><strong>' + processingText + '</strong></p>' +
					thinkingHtml +
					'<p class="at-processing-note" hidden>' + timeoutText + '</p>' +
				'</div>' +
			'</div>'
		);
		setTimeout(function() {
			$('#at-processing-box .at-processing-note').prop('hidden', false).hide().fadeIn('slow');
		}, timeoutDelay);
		AgentToolsProcessing.startCanvas(document.getElementById('at-thinking-canvas'));
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
