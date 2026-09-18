<?php namespace ProcessWire;

/**
 * ProcessWire admin client for the resumable AgentTools Site Builder.
 *
 */
class ProcessAgentToolsSiteBuilder extends ProcessAgentToolsHelper {

	/** @var string */
	protected $metaKey = 'site_builder_id';

	/**
	 * Render the Site Builder's current screen and process form actions.
	 *
	 * @return string
	 *
	 */
	public function executeSiteBuilder(): string {
		$this->headline($this->label('site-builder'));
		$this->loadAssets();

		if(!$this->at->getPrimaryAgent()) {
			$this->error(sprintf(
				$this->_('At least one agent must be configured. Please configure one in [Agents](%s).'),
				$this->url('agents/')
			), Notice::allowMarkdown);
			return '';
		}

		if($this->wire()->input->get('new')) {
			$this->setActiveId('');
			$this->wire()->session->location($this->url('site-builder/'));
		}

		$id = $this->getRequestedId();
		$id = $this->processPost($id);
		if($id === '') return $this->renderDescribe();

		try {
			$state = $this->at->siteBuilder()->getState($id);
		} catch(\Throwable $e) {
			$state = [];
		}
		if(!$state) {
			$this->setActiveId('');
			$this->error($this->_('Site Builder session not found or inaccessible.'));
			return $this->renderDescribe();
		}
		$this->setActiveId($id);

		$phase = (string) ($state['phase'] ?? '');
		$status = (string) ($state['status'] ?? '');
		if($phase === AgentToolsSiteBuilder::phaseDescribe || $status === 'reset') {
			return $this->renderDescribe((string) ($state['description'] ?? ''));
		}
		if($status === 'awaiting-approval' || ($phase === AgentToolsSiteBuilder::phasePlan && $status === 'paused' && !empty($state['plan']))) {
			return $this->renderPlan($id, $state);
		}
		if($phase === AgentToolsSiteBuilder::phaseDone || $status === 'done') return $this->renderDone($id, $state);
		return $this->renderProgress($id, $state);
	}

	/**
	 * Execute one provider round for the browser fetch loop.
	 *
	 * @return string JSON response
	 *
	 */
	public function executeSiteBuilderStep(): string {
		$this->wire()->config->ajax = true;
		header('Content-Type: application/json; charset=utf-8');
		$bufferLevel = ob_get_level();
		ob_start();
		$completed = false;
		$memoryReserve = str_repeat('x', 262144);
		register_shutdown_function(function() use(&$completed, &$memoryReserve, $bufferLevel): void {
			if($completed) return;
			$memoryReserve = '';
			$error = error_get_last();
			if(!$error || !in_array((int) $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
			while(ob_get_level() > $bufferLevel) ob_end_clean();
			http_response_code(500);
			header('Content-Type: application/json; charset=utf-8');
			echo '{"ok":false,"error":"The Site Builder step was interrupted by a server error."}';
		});
		$data = ['ok' => false, 'error' => $this->_('Unable to run the Site Builder step.')];
		try {
			$this->wire()->session->CSRF()->validate();
			$id = trim((string) $this->wire()->input->post('id'));
			$result = $this->at->siteBuilder()->step($id);
			$state = $this->at->siteBuilder()->getState($id);
			if(!$state) throw new WireException($this->_('Site Builder session not found or inaccessible.'));
			$data = [
				'ok' => true,
				'result' => $result,
				'state' => $this->stateSummary($state),
				'log' => $this->at->siteBuilder()->getLog($id),
			];
		} catch(\Throwable $e) {
			$data['error'] = $e->getMessage();
		}
		$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		$completed = true;
		$memoryReserve = '';
		while(ob_get_level() > $bufferLevel) ob_end_clean();
		return $json === false ? '{"ok":false,"error":"Unable to encode Site Builder response."}' : $json;
	}

	/**
	 * Process a normal form action and redirect after successful mutations.
	 *
	 * @param string $id
	 * @return string
	 *
	 */
	protected function processPost(string $id): string {
		$input = $this->wire()->input;
		$action = '';
		foreach([
			'submit_site_builder_start' => 'start',
			'submit_site_builder_revise' => 'revise',
			'submit_site_builder_approve' => 'approve',
			'submit_site_builder_pause' => 'pause',
			'submit_site_builder_resume' => 'resume',
			'submit_site_builder_extend' => 'extend',
			'submit_site_builder_finish_refinement' => 'finish-refinement',
			'submit_site_builder_start_over' => 'start-over',
			'submit_site_builder_refine' => 'refine',
		] as $name => $value) {
			if($input->post($name) !== null) {
				$action = $value;
				break;
			}
		}
		if($action === '') return $id;

		try {
			$this->wire()->session->CSRF()->validate();
			$builder = $this->at->siteBuilder();
			if($action === 'start') {
				$result = $builder->start(trim((string) $input->post('description')), [
					'agentId' => (string) $input->post('agentId'),
					'preset' => (string) $input->post('preset'),
					'siteName' => (string) $input->post('siteName'),
					'designDirection' => (string) $input->post('designDirection'),
					'colorScheme' => (string) $input->post('colorScheme'),
					'brandColor' => (string) $input->post('brandColor'),
					'cssApproach' => (string) $input->post('cssApproach'),
					'javascript' => (string) $input->post('javascript'),
				]);
				$id = (string) $result['id'];
				$this->setActiveId($id);
			} else {
				if($id === '') throw new WireException($this->_('No Site Builder session is selected.'));
				if($action === 'revise') {
					$builder->revisePlan($id, trim((string) $input->post('revision')));
				} else if($action === 'approve') {
					if($builder->planNeedsUpdateConfirmation($builder->getPlan($id)) && !$input->post('confirmUpdates')) {
						throw new WireException($this->_('Please confirm that you understand this plan will update existing site resources.'));
					}
					$builder->approvePlan($id, (bool) $input->post('acceptOpenQuestions'));
				} else if($action === 'pause') {
					$result = $builder->pause($id);
					if(($result['status'] ?? '') === 'busy') $this->warning($this->_('The current round is still finishing. Try Pause again when it completes.'));
				} else if($action === 'resume') {
					$builder->resume($id);
				} else if($action === 'extend') {
					$builder->extend($id, 10, $this->getWorkExtensionTokens($builder->getState($id), 10));
				} else if($action === 'finish-refinement') {
					$result = $builder->finishRefinement($id);
					if(($result['status'] ?? '') === 'busy') {
						$this->warning($this->_('The current round is still finishing. Pause the refinement before finishing it.'));
					}
				} else if($action === 'start-over') {
					if(!$input->post('confirmStartOver')) throw new WireException($this->_('Please confirm that you want to undo this build.'));
					$result = $builder->startOver($id);
					if(empty($result['rollback']['ok'])) throw new WireException((string) ($result['error'] ?? $this->_('The build could not be fully undone.')));
					$this->message($this->_('Site Builder changes were rolled back.'));
				} else if($action === 'refine') {
					$builder->refine($id, trim((string) $input->post('refinement')));
				}
			}
			$this->wire()->session->location($this->url('site-builder/?id=' . rawurlencode($id)));
		} catch(\Throwable $e) {
			$this->error($e->getMessage());
		}
		return $id;
	}

	/**
	 * Size a work extension to cover approximately the requested number of recent rounds.
	 *
	 * @param array<string,mixed> $state
	 */
	protected function getWorkExtensionTokens(array $state, int $rounds): int {
		$phase = (string) ($state['phase'] ?? '');
		$usedRounds = (int) ($state['phaseRounds'][$phase] ?? 0);
		$usedTokens = (int) ($state['phaseTokenUsage'][$phase]['total'] ?? 0);
		if($usedRounds < 1 || $usedTokens < 1) return 100000;
		$estimated = (int) ceil(($usedTokens / $usedRounds) * max(1, $rounds));
		$rounded = (int) (ceil($estimated / 50000) * 50000);
		return max(100000, min(500000, $rounded));
	}

	/**
	 * Render the initial site description form.
	 *
	 * @param string $description
	 * @return string
	 *
	 */
	protected function renderDescribe(string $description = ''): string {
		$form = $this->newForm();
		$sanitizer = $this->wire()->sanitizer;
		$presets = [
			'blog' => [$this->_('Blog'), $this->_('Build a polished publication with a homepage, article index, article pages, about page, and contact page.')],
			'portfolio' => [$this->_('Portfolio'), $this->_('Build a distinctive portfolio with a homepage, projects index, project pages, about page, and contact page.')],
			'business' => [$this->_('Small business'), $this->_('Build a clear business site with a homepage, services, about, testimonials, and contact page.')],
			'documentation' => [$this->_('Documentation'), $this->_('Build a documentation site with an overview, organized sections, article pages, and useful navigation.')],
			'events' => [$this->_('Events'), $this->_('Build an events site with upcoming events, event detail pages, venue information, and contact page.')],
		];

		$f = $form->InputfieldMarkup;
		$f->label = $this->_('Start with an idea');
		$f->icon = 'magic';
		$buttons = '';
		foreach($presets as $name => [$label, $text]) {
			$buttons .= '<button type="button" class="uk-button uk-button-default at-site-builder-preset" ' .
				'aria-pressed="false" data-preset="' . $sanitizer->entities($name) . '" data-description="' . $sanitizer->entities($text) . '">' .
				$sanitizer->entities($label) . '</button> ';
		}
		$f->value = '<div class="at-site-builder-presets">' . $buttons . '</div>' .
			'<input type="hidden" name="preset" id="at-site-builder-preset-value" value="">';
		$form->add($f);

		$f = $form->InputfieldTextarea;
		$f->attr('name', 'description');
		$f->attr('id', 'at-site-builder-description');
		$f->label = $this->_('What would you like to build?');
		$f->description = $this->_('Describe the site, its audience, important pages, content, and any behavior that matters. You can edit a preset or write your own request.');
		$f->attr('rows', 9);
		$f->required = true;
		$f->val($description);
		$form->add($f);

		$f = $form->InputfieldText;
		$f->attr('name', 'siteName');
		$f->label = $this->_('Site or business name');
		$f->description = $this->_('Optional. When blank, the plan will use an obvious placeholder suited to the site type.');
		$f->columnWidth = 50;
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'agentId');
		$f->label = $this->_('Agent / model');
		$f->columnWidth = 50;
		foreach($this->at->getAgents() as $agent) $f->addOption($agent->id, $agent->get('label|model'));
		$primary = $this->at->getPrimaryAgent();
		if($primary) $f->val($primary->id);
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'designDirection');
		$f->label = $this->_('Design direction');
		$f->columnWidth = 50;
		$f->addOptions([
			'editorial' => $this->_('Editorial'),
			'minimal' => $this->_('Minimal'),
			'bold-modern' => $this->_('Bold modern'),
			'friendly' => $this->_('Friendly'),
			'classic' => $this->_('Classic'),
		]);
		$f->val('editorial');
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'colorScheme');
		$f->label = $this->_('Color scheme');
		$f->columnWidth = 50;
		$f->addOptions([
			'auto' => $this->_('Choose for me'),
			'warm' => $this->_('Warm'),
			'cool' => $this->_('Cool'),
			'earthy' => $this->_('Earthy'),
			'vibrant' => $this->_('Vibrant'),
			'soft' => $this->_('Soft (pastels)'),
			'monochrome' => $this->_('Monochrome'),
		]);
		$f->val('auto');
		$form->add($f);

		$f = $form->InputfieldText;
		$f->attr('name', 'brandColor');
		$f->label = $this->_('Brand color');
		$f->description = $this->_('Optional. If provided, the palette will be built around this color.');
		$f->attr('placeholder', '#2563eb');
		$f->attr('pattern', '#[0-9A-Fa-f]{6}');
		$f->attr('maxlength', 7);
		$f->columnWidth = 50;
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'cssApproach');
		$f->label = $this->_('CSS approach');
		$f->columnWidth = 50;
		$f->addOptions([
			'agenttools-base' => $this->_('Site profile / existing CSS'),
			'uikit' => 'UIkit',
			'bootstrap' => 'Bootstrap',
		]);
		$f->val('agenttools-base');
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'javascript');
		$f->label = $this->_('JavaScript');
		$f->columnWidth = 50;
		$f->addOptions(['vanilla' => $this->_('Vanilla'), 'htmx' => 'htmx']);
		$f->val('vanilla');
		$form->add($f);

		$f = $form->InputfieldMarkup;
		$f->value = '<p class="detail">' . $sanitizer->entities($this->_('Site content and code needed for planning and building are sent to the selected AI provider, which may retain or use them under its own policies.')) . '</p>';
		$form->add($f);

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_site_builder_start');
		$f->icon = 'magic';
		$f->val($this->_('Create site plan'));
		$f->showInHeader(true);
		$form->add($f);
		return $form->render();
	}

	/**
	 * Render the approved-plan review and revision controls.
	 *
	 * @param string $id
	 * @param array<string,mixed> $state
	 * @return string
	 *
	 */
	protected function renderPlan(string $id, array $state): string {
		$form = $this->newForm();
		$plan = (array) ($state['plan'] ?? []);
		$planErrors = array_values((array) ($state['planErrors'] ?? []));
		$planWarnings = array_values((array) ($state['planWarnings'] ?? []));
		$correctedBuildError = trim((string) ($state['correctedBuildError'] ?? ''));
		if($correctedBuildError !== '') {
			$f = $form->InputfieldMarkup;
			$f->label = $this->_('Build plan corrected');
			$f->icon = 'refresh';
			$f->value = '<p class="uk-alert uk-alert-warning">' .
				$this->wire()->sanitizer->entities($this->_('The previous build attempt was safely rolled back. Site Builder corrected the plan; review it and approve it again to continue.')) .
				'</p><p class="detail">' . $this->wire()->sanitizer->entities($correctedBuildError) . '</p>';
			$form->add($f);
		}
		if($planErrors) {
			$f = $form->InputfieldMarkup;
			$f->label = $this->_('Plan requires correction');
			$f->icon = 'warning';
			$f->value = '<p class="uk-alert uk-alert-warning">' . $this->wire()->sanitizer->entities($this->_('These validation errors must be corrected before the site can be built.')) . '</p>' .
				'<ul class="uk-list uk-list-bullet">';
			foreach($planErrors as $item) $f->value .= '<li>' . $this->wire()->sanitizer->entities((string) $item) . '</li>';
			$f->value .= '</ul>';
			$form->add($f);
		}
		if($planWarnings) {
			$f = $form->InputfieldMarkup;
			$f->label = $this->_('Plan adjustments');
			$f->icon = 'info-circle';
			$f->value = '<p class="uk-alert uk-alert-warning">' . $this->wire()->sanitizer->entities($this->_('Unsupported settings were omitted so the plan can continue safely.')) . '</p><ul class="uk-list uk-list-bullet">';
			foreach($planWarnings as $item) $f->value .= '<li>' . $this->wire()->sanitizer->entities((string) $item) . '</li>';
			$f->value .= '</ul>';
			$form->add($f);
		}
		$f = $form->InputfieldMarkup;
		$f->label = $this->_('Review the site plan');
		$f->icon = 'clipboard';
		$f->value = $this->renderPlanSummary($plan);
		$form->add($f);

		$f = $form->InputfieldTextarea;
		$f->attr('name', 'revision');
		$f->label = $planErrors ? $this->_('Additional guidance') : $this->_('Request changes');
		$f->description = $planErrors ?
			$this->_('The planner already has the validation errors above. Optionally describe any other changes it should make.') :
			$this->_('Describe anything the planner should add, remove, or change, then create a revised plan.');
		$f->attr('rows', 4);
		$form->add($f);

		$openQuestions = array_values((array) ($plan['openQuestions'] ?? []));
		if($openQuestions) {
			$f = $form->InputfieldCheckbox;
			$f->attr('name', 'acceptOpenQuestions');
			$f->label = $this->_('Accept unresolved questions');
			$f->description = $this->_('Build using the assumptions in this plan even though it still contains open questions.');
			$form->add($f);
		}

		if($this->at->siteBuilder()->planNeedsUpdateConfirmation($plan)) {
			$f = $form->InputfieldCheckbox;
			$f->attr('name', 'confirmUpdates');
			$f->label = $this->_('Confirm existing-site changes');
			$f->description = $this->_('I understand this plan will update existing site content or files.');
			$f->notes = $this->_('Ensure you have a current database and file backup before building.');
			$f->icon = 'warning';
			$f->required = true;
			$form->add($f);
		}

		$this->addHiddenId($form, $id);
		if(!$planErrors) {
			$f = $form->InputfieldSubmit;
			$f->attr('name', 'submit_site_builder_approve');
			$f->icon = 'check';
			$f->val($this->_('Approve and build'));
			$f->showInHeader(true);
			$form->add($f);
		}

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_site_builder_revise');
		$f->icon = 'refresh';
		$f->val($planErrors ? $this->_('Correct plan') : $this->_('Revise plan'));
		if($planErrors) $f->showInHeader(true); else $f->setSecondary();
		$form->add($f);

		$f = $form->InputfieldButton;
		$f->href = $this->url('site-builder/?new=1');
		$f->icon = 'arrow-left';
		$f->val($this->_('New description'));
		$f->setSecondary();
		$form->add($f);

		$raw = $form->InputfieldMarkup;
		$raw->wrapClass('at-site-builder-plan-json');
		$raw->label = $this->_('Plan JSON');
		$raw->icon = 'code';
		$raw->collapsed = Inputfield::collapsedYes;
		$raw->value = $this->pre(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$raw->themeOffset = 1;
		$form->add($raw);
		return $form->render();
	}

	/**
	 * Render a running, paused, or recoverable-error build.
	 *
	 * @param string $id
	 * @param array<string,mixed> $state
	 * @return string
	 *
	 */
	protected function renderProgress(string $id, array $state): string {
		$form = $this->newForm();
		$status = (string) ($state['status'] ?? 'ready');
		$active = in_array($status, ['ready', 'running', 'continue', 'busy'], true);
		$auto = $active ? '1' : '0';
		if($status === 'paused') {
			$currentText = $this->_('The build is paused.');
		} else if($status === 'error') {
			$currentText = $this->_('The build needs attention.');
		} else {
			$currentText = $this->_('Ready for the next step.');
		}
		$csrf = $this->wire()->session->CSRF();
		$attrs = [
			'id' => 'at-site-builder-progress',
			'data-id' => $id,
			'data-endpoint' => $this->url('site-builder-step/'),
			'data-csrf-name' => $csrf->getTokenName(),
			'data-csrf-value' => $csrf->getTokenValue(),
			'data-auto' => $auto,
			'data-text-working' => $this->_('Working on the next step…'),
			'data-text-retrying' => $this->_('Connection interrupted. Retrying…'),
			'data-text-retry' => $this->_('Retrying…'),
			'data-text-stopped' => $this->_('The build stopped because the request could not be completed.'),
			'data-text-retry-stopped' => $this->_('The server interrupted several attempts. Refresh this page to try recovering again.'),
			'data-text-updating' => $this->_('Step complete. Updating the screen…'),
			'data-text-busy' => $this->_('Another step is still finishing…'),
			'data-text-continuing' => $this->_('Step complete. Continuing…'),
			'data-text-elapsed' => $this->_('elapsed'),
			'data-text-empty-log' => $this->_('No progress has been recorded yet.'),
			'data-label-phase' => $this->_('Phase'),
			'data-label-round' => $this->_('Round'),
			'data-label-tokens' => $this->_('tokens'),
			'data-measure-modal' => $this->_('modal'),
			'data-measure-card' => $this->_('card'),
			'data-measure-sidebar' => $this->_('sidebar'),
			'data-measure-hero' => $this->_('hero'),
			'data-measure-field-row' => $this->_('field row'),
		];
		$attr = '';
		foreach($attrs as $name => $value) $attr .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';

		$f = $form->InputfieldMarkup;
		$f->description = $this->_('Please be patient, this may take awhile.');
		$f->label = $this->_('Building and verifying your site');
		$f->icon = 'magic';
		$f->value = '<div class="at-no-measure"' . $attr . '>' .
			$this->renderStateLine($state) .
			'<p id="at-site-builder-current" class="detail" aria-live="polite">' .
				'<span>' . $this->wire()->sanitizer->entities($currentText) . '</span>' .
				($active ? ' <span id="at-site-builder-elapsed" aria-hidden="true"></span>' : '') .
			'</p>' .
			'<ol id="at-site-builder-log" class="at-site-builder-log">' . $this->renderLogItems($this->at->siteBuilder()->getLog($id)) . '</ol>' .
			'</div>';
		$form->add($f);

		if(!empty($state['error'])) {
			$f = $form->InputfieldMarkup;
			$f->label = $this->_('Needs attention');
			$f->icon = 'warning';
			$f->value = '<p class="uk-alert uk-alert-warning">' . $this->wire()->sanitizer->entities((string) $state['error']) . '</p>';
			$form->add($f);
		}

		$this->addHiddenId($form, $id);
		if(in_array($status, ['paused', 'error'], true)) {
			$allowMore = $this->isPhaseWorkLimitReached($state);
			$f = $form->InputfieldSubmit;
			$f->attr('name', $allowMore ? 'submit_site_builder_extend' : 'submit_site_builder_resume');
			$f->icon = $allowMore ? 'plus-circle' : 'play';
			$f->val($allowMore ? $this->_('Allow more work and resume') : $this->_('Resume'));
			$f->showInHeader(true);
			$form->add($f);
			if(($state['phase'] ?? '') === AgentToolsSiteBuilder::phaseRefine) {
				$f = $form->InputfieldSubmit;
				$f->attr('name', 'submit_site_builder_finish_refinement');
				$f->icon = 'check';
				$f->val($this->_('Finish refinement and verify'));
				$f->setSecondary();
				$form->add($f);
			}
		} else {
			$f = $form->InputfieldSubmit;
			$f->attr('name', 'submit_site_builder_pause');
			$f->icon = 'pause';
			$f->val($this->_('Pause after this round'));
			$f->setSecondary();
			$form->add($f);
		}
		return $form->render();
	}

	/**
	 * Is the current phase paused at its configured round or token limit?
	 *
	 * @param array<string,mixed> $state
	 * @return bool
	 *
	 */
	protected function isPhaseWorkLimitReached(array $state): bool {
		$phase = (string) ($state['phase'] ?? '');
		if(!in_array($phase, [AgentToolsSiteBuilder::phasePlan, AgentToolsSiteBuilder::phaseBuild, AgentToolsSiteBuilder::phaseRefine, AgentToolsSiteBuilder::phaseVerify], true)) return false;
		$options = (array) ($state['options'] ?? []);
		$roundLimit = (int) ($options[$phase . 'RoundLimit'] ?? 0);
		$tokenLimit = (int) ($options[$phase . 'TokenLimit'] ?? 0);
		$rounds = (int) ($state['phaseRounds'][$phase] ?? 0);
		$tokens = (int) ($state['phaseTokenUsage'][$phase]['total'] ?? 0);
		return ($roundLimit > 0 && $rounds >= $roundLimit) || ($tokenLimit > 0 && $tokens >= $tokenLimit);
	}

	/**
	 * Render the completed build and rollback controls.
	 *
	 * @param string $id
	 * @param array<string,mixed> $state
	 * @return string
	 *
	 */
	protected function renderDone(string $id, array $state): string {
		$form = $this->newForm();
		$manifest = $this->at->siteBuilder()->getManifest($id);
		$f = $form->InputfieldMarkup;
		$f->label = ($state['verificationPurpose'] ?? 'build') === 'refine' ? $this->_('Site refinement complete') : $this->_('Site build complete');
		$f->icon = 'check';
		$f->value = $this->renderStateLine($state) . $this->renderManifestSummary($manifest) .
			'<p class="uk-margin-top">' .
			'<a class="uk-button uk-button-primary" target="_blank" rel="noopener" href="' . $this->wire()->config->urls->root . '">' . wireIconMarkup('external-link') . ' ' . $this->_('View site') . '</a> ' .
			'<a class="uk-button uk-button-default" target="_blank" rel="noopener" href="' . $this->wire()->config->urls->admin . '">' . wireIconMarkup('cog') . ' ' . $this->_('Open admin') . '</a>' .
			'</p>';
		$form->add($f);

		$buildResponse = trim((string) ($state['buildResponse'] ?? ''));
		if($buildResponse !== '') {
			$f = $form->InputfieldMarkup;
			$f->label = $this->_('Builder report');
			$f->icon = 'comment';
			$f->themeOffset = 1;
			$f->value = $this->renderAgentText($buildResponse);
			$form->add($f);
		}

		$refinements = array_values((array) ($state['refinements'] ?? []));
		if($refinements) {
			$refinements = array_slice(array_reverse($refinements), 0, 10);
			foreach($refinements as $index => $refinement) {
				if(!is_array($refinement)) continue;
				$number = (int) ($refinement['number'] ?? 0);
				$f = $form->InputfieldMarkup;
				$f->label = $index === 0 ? $this->_('Latest refinement') : sprintf($this->_('Refinement %d'), $number);
				$f->icon = 'comments';
				$f->themeOffset = 1;
				if($index > 0) $f->collapsed = Inputfield::collapsedYes;
				$f->value =
					'<h4>' . $this->_('Request') . '</h4>' .
					$this->renderAgentText((string) ($refinement['request'] ?? '')) .
					'<h4>' . $this->_('Reply') . '</h4>' .
					$this->renderAgentText((string) ($refinement['response'] ?? $this->_('No reply was recorded.')));
				$form->add($f);
			}
		}

		$f = $form->InputfieldTextarea;
		$f->attr('name', 'refinement');
		$f->label = $this->_('Refine this site');
		$f->icon = 'magic';
		$f->description = $this->_('Describe a small correction, content improvement, or sample-page addition. Refinement uses the approved site plan and runs verification again.');
		$f->notes = $this->_('Refinements adjust what is already built. To add a new section or feature, such as a blog, start a new plan instead; it builds on this site rather than replacing it.');
		$f->attr('rows', 4);
		$form->add($f);

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_site_builder_refine');
		$f->icon = 'magic';
		$f->val($this->_('Refine site'));
		$f->appendMarkup .= '<br><br>';
		$form->add($f);

		$f = $form->InputfieldMarkup;
		$f->label = $this->_('Progress log');
		$f->icon = 'list';
		$f->collapsed = Inputfield::collapsedYes;
		$f->themeOffset = 1;
		$f->value = '<ol class="at-site-builder-log">' . $this->renderLogItems($this->at->siteBuilder()->getLog($id)) . '</ol>';
		$form->add($f);

		$f = $form->InputfieldFieldset;
		$f->label = $this->_('Start over');
		$f->icon = 'undo';
		$f->collapsed = Inputfield::collapsedYes;
		$form->add($f);

		$confirm = $form->InputfieldCheckbox;
		$confirm->attr('name', 'confirmStartOver');
		$confirm->label = $this->_('Undo this build');
		$confirm->description = $this->_('Restore updated resources and remove resources created by this build according to its manifest.');
		$f->add($confirm);

		$this->addHiddenId($form, $id);
		$submit = $form->InputfieldSubmit;
		$submit->attr('name', 'submit_site_builder_start_over');
		$submit->icon = 'undo';
		$submit->val($this->_('Start over'));
		$submit->setSecondary();
		$form->add($submit);

		$new = $form->InputfieldButton;
		$new->href = $this->url('site-builder/?new=1');
		$new->icon = 'plus';
		$new->val($this->_('Plan an addition'));
		$new->setSecondary();
		$form->add($new);
		return $form->render();
	}

	/** Render stored agent or user prose as encoded paragraphs and line breaks only. */
	protected function renderAgentText(string $text): string {
		$text = trim(str_replace(["\r\n", "\r"], "\n", $text));
		if($text === '') return '<p class="detail">' . $this->_('No reply was recorded.') . '</p>';
		$paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
		$out = '';
		foreach($paragraphs as $paragraph) {
			$out .= '<p>' . nl2br($this->wire()->sanitizer->entities(trim($paragraph)), false) . '</p>';
		}
		return $out;
	}

	/** @return InputfieldForm */
	protected function newForm(): InputfieldForm {
		$form = $this->wire()->modules->get('InputfieldForm');
		$form->attr('method', 'post');
		$form->attr('action', $this->url('site-builder/'));
		return $form;
	}

	/** @param InputfieldForm $form */
	protected function addHiddenId(InputfieldForm $form, string $id): void {
		$f = $form->InputfieldHidden;
		$f->attr('name', 'id');
		$f->val($id);
		$form->add($f);
	}

	/** @return string */
	protected function renderPlanSummary(array $plan): string {
		$s = $this->wire()->sanitizer;
		$out = '<h2>' . $s->entities((string) ($plan['title'] ?? $this->_('Site plan'))) . '</h2>';
		if(!empty($plan['summary'])) $out .= '<p class="uk-text-lead">' . $s->entities((string) $plan['summary']) . '</p>';
		foreach(['features' => $this->_('Features'), 'assumptions' => $this->_('Assumptions'), 'openQuestions' => $this->_('Open questions')] as $key => $label) {
			$items = (array) ($plan[$key] ?? []);
			if(!$items) continue;
			$out .= '<h3>' . $s->entities($label) . '</h3><ul class="uk-list uk-list-bullet">';
			foreach($items as $item) {
				if(is_array($item)) $item = trim((string) ($item['label'] ?? $item['summary'] ?? $item['key'] ?? ''));
				$out .= '<li>' . $s->entities((string) $item) . '</li>';
			}
			$out .= '</ul>';
		}
		$out .= '<h3>' . $s->entities($this->_('Resources')) . '</h3><table class="uk-table uk-table-divider uk-table-small"><thead><tr>' .
			'<th>' . $s->entities($this->_('Type')) . '</th><th>' . $s->entities($this->_('Name')) . '</th><th>' . $s->entities($this->_('Action')) . '</th></tr></thead><tbody>';
		$types = [
			'fields' => $this->_('Field'),
			'templates' => $this->_('Template'),
			'pages' => $this->_('Page'),
			'files' => $this->_('File'),
			'modules' => $this->_('Module'),
		];
		foreach($types as $key => $type) {
			foreach((array) ($plan[$key] ?? []) as $item) {
				if(!is_array($item)) continue;
				$name = (string) ($item['name'] ?? $item['key'] ?? $item['path'] ?? '');
				$out .= '<tr><td>' . $s->entities($type) . '</td><td>' . $s->entities($name) . '</td><td>' . $s->entities((string) ($item['disposition'] ?? '')) . '</td></tr>';
			}
		}
		return $out . '</tbody></table>';
	}

	/** @return string */
	protected function renderManifestSummary(array $manifest): string {
		$s = $this->wire()->sanitizer;
		$out = '<table class="uk-table uk-table-divider uk-table-small"><thead><tr><th>' . $s->entities($this->_('Resource')) . '</th><th>' . $s->entities($this->_('Completed')) . '</th></tr></thead><tbody>';
		$labels = [
			'fields' => $this->_('Fields'),
			'templates' => $this->_('Templates'),
			'pages' => $this->_('Pages'),
			'files' => $this->_('Files'),
			'modules' => $this->_('Modules'),
			'verification' => $this->_('Verification checks'),
		];
		foreach($labels as $key => $label) {
			$items = (array) ($manifest[$key] ?? []);
			$count = $key === 'verification' ? count(array_filter($items, function($item) { return !empty($item['ok']); })) : count(array_filter($items, function($item) { return ($item['status'] ?? '') === 'complete'; }));
			$out .= '<tr><td>' . $s->entities($label) . '</td><td>' . $count . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	/** @return string */
	protected function renderStateLine(array $state): string {
		$phase = ucfirst((string) ($state['phase'] ?? ''));
		$status = (string) ($state['status'] ?? '');
		$type = $status === 'done' ? 'success' : (in_array($status, ['error', 'paused'], true) ? 'warning' : '');
		$tokens = (int) ($state['tokenUsage']['total'] ?? 0);
		return '<p class="at-site-builder-state"><strong>' . $this->wire()->sanitizer->entities($this->_('Phase')) . ':</strong> ' .
			$this->wire()->sanitizer->entities($phase) . ' &nbsp; ' . $this->ukLabel(ucfirst($status), $type) .
			' <span class="detail">' . sprintf($this->_('Round %1$d · %2$s tokens'), (int) ($state['round'] ?? 0), number_format($tokens)) . '</span></p>';
	}

	/** @param array<int,array<string,mixed>> $log @return string */
	protected function renderLogItems(array $log): string {
		$s = $this->wire()->sanitizer;
		$out = '';
		foreach($log as $entry) {
			$type = (string) ($entry['type'] ?? 'progress');
			$class = strpos($type, 'error') !== false ? ' class="at-site-builder-log-error"' : '';
			$time = !empty($entry['timestamp']) ? date('H:i:s', (int) $entry['timestamp']) : '';
			$out .= '<li' . $class . '><time title="' . sprintf($s->entities($this->_('Recorded at %s')), $s->entities($time)) . '">' .
				$s->entities($time) . '</time> ' . $s->entities((string) ($entry['message'] ?? '')) . '</li>';
		}
		return $out ?: '<li class="detail">' . $s->entities($this->_('No progress has been recorded yet.')) . '</li>';
	}

	/** @return array<string,mixed> */
	protected function stateSummary(array $state): array {
		return [
			'id' => (string) ($state['id'] ?? ''),
			'phase' => (string) ($state['phase'] ?? ''),
			'status' => (string) ($state['status'] ?? ''),
			'round' => (int) ($state['round'] ?? 0),
			'error' => (string) ($state['error'] ?? ''),
			'tokenUsage' => (array) ($state['tokenUsage'] ?? []),
		];
	}

	/** @return string */
	protected function getRequestedId(): string {
		$id = trim((string) $this->wire()->input->get('id'));
		if($id === '') $id = trim((string) $this->wire()->input->post('id'));
		if($id === '') {
			$meta = $this->wire()->user->meta('AgentTools') ?: [];
			$id = trim((string) ($meta[$this->metaKey] ?? ''));
		}
		return $id;
	}

	protected function setActiveId(string $id): void {
		$user = $this->wire()->user;
		$meta = $user->meta('AgentTools') ?: [];
		if($id === '') unset($meta[$this->metaKey]); else $meta[$this->metaKey] = $id;
		$user->meta('AgentTools', $meta);
	}

	protected function loadAssets(): void {
		$url = $this->wire()->config->urls($this->pat);
		$this->wire()->config->scripts->add($url . 'measure-activity.js');
		$this->wire()->config->scripts->add($url . 'site-builder.js');
		$this->wire()->config->styles->add($url . 'site-builder.css');
	}

	/** @return string */
	protected function url(string $path = ''): string {
		$page = $this->wire()->pages->get('template=admin, process=ProcessAgentTools');
		$url = $page && $page->id ? $page->url : $this->wire()->page->url;
		return rtrim($url, '/') . '/' . ltrim($path, '/');
	}
}
