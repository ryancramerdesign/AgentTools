/**
 * Measuring activity indicator
 *
 * An ambient "work in progress" indicator for long running operations. Instead of a spinner,
 * it draws architectural dimension lines across elements already on the screen: a line grows
 * at a constant rate beneath a heading or button while a live pixel count follows its leading
 * edge, holds, and fades. Some of the time it sketches a dashed wireframe of an element that
 * does not exist ("modal", "sidebar") and measures that on both axes instead.
 *
 * It shows activity, not progress, so it is meant to accompany a real phase/round/token readout
 * rather than replace one.
 *
 *   PWMeasureActivity.start({ getCaption: function() { return currentActivityText; } });
 *   PWMeasureActivity.stop();
 *
 * No dependencies. Purely decorative: the layer is aria-hidden, ignores pointer events, and
 * disables itself under prefers-reduced-motion.
 *
 */
window.PWMeasureActivity = (function() {
	'use strict';

	var defaults = {
		// Curated pool. Random elements mostly produce unreadable measurements, so this
		// lists things with a deliberate width: headings, buttons, labels, nav items.
		selectors: [
			'#pw-content-head h1', '.InputfieldHeader', '.pw-panel-header',
			'.ui-button', '.uk-button', '#pw-masthead a', 'th', 'td'
		],
		// Regions the user is reading or that reflow while work runs. The progress log is
		// both the most distracting target and the one most likely to move mid-animation.
		// Put 'at-no-measure' on the whole wrapper rather than the log body alone: a heading
		// beside the log is still close enough that its caption lands on top of the entries.
		exclude: '.at-no-measure, .at-no-measure *, .pw-notices, .pw-notices *',
		velocity: 0.4,        // px per ms; a 600px headline takes ~1500ms
		minDuration: 420,
		maxDuration: 2400,
		holdTime: 420,        // pause on the completed measurement before fading
		fadeTime: 280,
		gapMin: 500,          // idle time between measurements
		gapMax: 1200,
		ghostChance: 0.35,    // share of measurements that sketch a fictional element
		minWidth: 40,
		maxWidth: 620,
		minTextLength: 3,     // skip near-empty targets: a lone "6" reads as arbitrary
		captionMinWidth: 120, // narrower targets get the pixel count only
		resizeDelay: 250,
		getCaption: null,     // function returning the current activity text, or null
		ghosts: [
			{ label: 'modal', w: 480, h: 320 },
			{ label: 'card', w: 300, h: 210 },
			{ label: 'sidebar', w: 240, h: 420 },
			{ label: 'hero', w: 620, h: 260 },
			{ label: 'field row', w: 360, h: 64 }
		]
	};

	var css = '' +
		'.pw-measure-layer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; ' +
			'pointer-events: none; overflow: hidden; z-index: 50; }' +
		'.pw-measure { position: absolute; color: var(--pw-main-color, #eb1d61); ' +
			'transition: opacity 280ms ease; }' +
		'.pw-measure i { position: absolute; background: currentColor; display: block; }' +
		'.pw-measure b { position: absolute; font: 500 11px/1 ui-monospace, SFMono-Regular, Menlo, monospace; ' +
			'color: currentColor; white-space: nowrap; }' +
		'.pw-measure s { position: absolute; font: 500 11px/1 ui-monospace, SFMono-Regular, Menlo, monospace; ' +
			'color: var(--pw-muted-color, #999); white-space: nowrap; text-decoration: none; }' +
		'.pw-measure u { position: absolute; border: 1px dashed currentColor; opacity: 0.45; ' +
			'border-radius: 3px; text-decoration: none; }' +
		'@media (prefers-reduced-motion: reduce) { .pw-measure-layer { display: none; } }';

	var options = null, layer = null, running = false;
	var timer = null, frame = null, resizeTimer = null, styled = false;

	function extend(target, source) {
		for(var key in source) if(source.hasOwnProperty(key)) target[key] = source[key];
		return target;
	}

	function injectStyles() {
		if(styled) return;
		styled = true;
		var style = document.createElement('style');
		style.textContent = css;
		document.head.appendChild(style);
	}

	function syncLayerSize() {
		if(!layer) return;
		layer.style.width = Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) + 'px';
		layer.style.height = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight) + 'px';
	}

	function reducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	function randomOf(items) {
		return items[Math.floor(Math.random() * items.length)];
	}

	/**
	 * Elements worth measuring: visible, a sensible width, carrying some text, outside
	 * excluded regions.
	 *
	 */
	function candidates() {
		var found = [], nodes = document.querySelectorAll(options.selectors.join(','));
		for(var n = 0; n < nodes.length; n++) {
			var el = nodes[n];
			if(options.exclude && el.closest(options.exclude)) continue;
			if((el.textContent || '').trim().length < options.minTextLength) continue;
			var r = el.getBoundingClientRect();
			if(r.width < options.minWidth || r.width > options.maxWidth || r.height < 8) continue;
			if(r.bottom < 0 || r.top > window.innerHeight) continue;
			found.push(el);
		}
		return found;
	}

	/**
	 * Document coordinates rather than viewport coordinates, so scrolling needs no handler:
	 * the overlay scrolls with the page it annotates.
	 *
	 */
	function documentRect(el) {
		var r = el.getBoundingClientRect();
		return { x: r.left + window.pageXOffset, y: r.top + window.pageYOffset, w: r.width, h: r.height };
	}

	function hasRoomFor(text, rect, vertical) {
		var width = text.length * 6.2; // 11px monospace, near enough for a fit test
		var left = vertical ? rect.x + rect.w + 13 : rect.x;
		if(left + width > layer.clientWidth - 16) return false;
		return vertical || rect.w >= options.captionMinWidth;
	}

	/**
	 * Draw and animate one measurement.
	 *
	 * @param rect Document-coordinate box being measured
	 * @param config vertical, ghost, caption
	 * @param done Called when the measurement has faded
	 *
	 */
	function measure(rect, config, done) {

		var vertical = !!config.vertical;
		var span = vertical ? rect.h : rect.w;
		var gap = 7;

		var group = document.createElement('div');
		group.className = 'pw-measure';
		group.style.cssText = 'left:0; top:0; width:0; height:0';

		if(config.ghost) {
			var ghost = document.createElement('u');
			ghost.style.cssText = 'left:' + rect.x + 'px; top:' + rect.y + 'px; ' +
				'width:' + rect.w + 'px; height:' + rect.h + 'px';
			group.appendChild(ghost);
		}

		var line = document.createElement('i');
		var capStart = document.createElement('i');
		var capEnd = document.createElement('i');
		var label = document.createElement('b');

		if(vertical) {
			var x = rect.x + rect.w + gap;
			line.style.cssText = 'left:' + x + 'px; top:' + rect.y + 'px; width:1px; height:0';
			capStart.style.cssText = 'left:' + (x - 3) + 'px; top:' + rect.y + 'px; width:7px; height:1px';
			capEnd.style.cssText = capStart.style.cssText;
			label.style.cssText = 'left:' + (x + 6) + 'px; top:' + rect.y + 'px';
		} else {
			var y = rect.y + rect.h + gap;
			line.style.cssText = 'left:' + rect.x + 'px; top:' + y + 'px; height:1px; width:0';
			capStart.style.cssText = 'left:' + rect.x + 'px; top:' + (y - 3) + 'px; width:1px; height:7px';
			capEnd.style.cssText = capStart.style.cssText;
			label.style.cssText = 'left:' + rect.x + 'px; top:' + (y + 6) + 'px';
		}

		group.appendChild(line);
		group.appendChild(capStart);
		group.appendChild(capEnd);
		group.appendChild(label);

		if(config.caption && hasRoomFor(config.caption, rect, vertical)) {
			var note = document.createElement('s');
			note.textContent = config.caption;
			note.style.cssText = vertical
				? 'left:' + (rect.x + rect.w + gap + 6) + 'px; top:' + (rect.y + 18) + 'px'
				: 'left:' + rect.x + 'px; top:' + (rect.y + rect.h + gap + 22) + 'px';
			group.appendChild(note);
		}

		layer.appendChild(group);

		// Constant rate, so a nav label finishes quickly and a headline takes its time.
		var duration = Math.min(options.maxDuration, Math.max(options.minDuration, span / options.velocity));
		var start = null;

		function step(now) {
			if(!running) { group.parentNode && group.parentNode.removeChild(group); return done(); }
			if(start === null) start = now;
			var t = Math.min(1, (now - start) / duration);
			var length = span * t;
			if(vertical) {
				line.style.height = length + 'px';
				capEnd.style.top = (rect.y + length) + 'px';
				label.style.top = (rect.y + length - 5) + 'px';
			} else {
				line.style.width = length + 'px';
				capEnd.style.left = (rect.x + length) + 'px';
				label.style.left = (rect.x + length + 6) + 'px';
			}
			label.textContent = Math.round(length) + 'px';
			if(t < 1) { frame = requestAnimationFrame(step); return; }
			setTimeout(function() {
				group.style.opacity = '0';
				setTimeout(function() {
					group.parentNode && group.parentNode.removeChild(group);
					done();
				}, options.fadeTime);
			}, options.holdTime);
		}

		frame = requestAnimationFrame(step);
	}

	function caption() {
		if(typeof options.getCaption !== 'function') return '';
		var text = options.getCaption();
		return typeof text === 'string' ? text : '';
	}

	function next() {
		if(!running) return;
		timer = setTimeout(tick, options.gapMin + Math.random() * (options.gapMax - options.gapMin));
	}

	function tick() {

		if(!running) return;
		syncLayerSize();

		if(Math.random() < options.ghostChance) {
			// A dashed sketch of something that is not there yet. It reads as design work
			// rather than as a rendering fault, and it does not depend on live layout.
			var ghost = randomOf(options.ghosts);
			// Leave room to the right for the vertical measurement and its label, and
			// shrink rather than overflow when the viewport is narrower than the sketch.
			var width = Math.min(ghost.w, Math.max(120, window.innerWidth - 100));
			var height = Math.min(ghost.h, Math.max(80, window.innerHeight - 220));
			var spanX = Math.max(0, window.innerWidth - width - 80);
			var spanY = Math.max(0, window.innerHeight - height - 260);
			var rect = {
				x: window.pageXOffset + 20 + Math.random() * spanX,
				y: window.pageYOffset + 140 + Math.random() * spanY,
				w: width,
				h: height
			};
			measure(rect, { ghost: true, caption: ghost.label }, function() {
				if(!running) return;
				measure(rect, { ghost: true, vertical: true, caption: caption() }, next);
			});
			return;
		}

		var pool = candidates();
		if(!pool.length) return next();
		measure(documentRect(randomOf(pool)), { caption: caption() }, next);
	}

	/**
	 * Resizing invalidates every rect we may be part way through drawing, so stop, clear,
	 * and begin again once it settles.
	 *
	 */
	function onResize() {
		if(!layer) return;
		halt();
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(function() {
			if(!layer) return;
			syncLayerSize();
			if(!document.hidden) resume();
		}, options.resizeDelay);
	}

	function onVisibilityChange() {
		if(!layer) return;
		if(document.hidden) halt(); else resume();
	}

	function halt() {
		running = false;
		if(timer) clearTimeout(timer);
		if(frame) cancelAnimationFrame(frame);
		timer = frame = null;
		if(layer) layer.innerHTML = '';
	}

	function resume() {
		if(running || reducedMotion()) return;
		running = true;
		timer = setTimeout(tick, 200);
	}

	return {

		/**
		 * Begin the indicator
		 *
		 * @param config Any of the documented defaults, notably getCaption
		 *
		 */
		start: function(config) {
			if(layer) this.stop();
			options = extend(extend({}, defaults), config || {});
			if(reducedMotion()) return;
			injectStyles();
			layer = document.createElement('div');
			layer.className = 'pw-measure-layer';
			layer.setAttribute('aria-hidden', 'true');
			(options.container || document.body).appendChild(layer);
			syncLayerSize();
			window.addEventListener('resize', onResize);
			document.addEventListener('visibilitychange', onVisibilityChange);
			resume();
		},

		/**
		 * Stop the indicator and remove its layer
		 *
		 */
		stop: function() {
			halt();
			clearTimeout(resizeTimer);
			window.removeEventListener('resize', onResize);
			document.removeEventListener('visibilitychange', onVisibilityChange);
			if(layer && layer.parentNode) layer.parentNode.removeChild(layer);
			layer = null;
		},

		/**
		 * Is the indicator currently drawing?
		 *
		 */
		isRunning: function() {
			return running;
		}
	};

})();
