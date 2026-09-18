<?php namespace ProcessWire;

include_once(__DIR__ . '/AgentToolsSiteBuilderPlan.php');
include_once(__DIR__ . '/AgentToolsSiteBuilderTools.php');

/**
 * Resumable planning, building, and verification service for AI-built sites.
 *
 * The admin and future CLI runner are deliberately thin clients over this
 * service. Each call to step() performs at most one provider round.
 *
 */
class AgentToolsSiteBuilder extends AgentToolsHelper {

	const phaseDescribe = 'describe';
	const phasePlan = 'plan';
	const phaseBuild = 'build';
	const phaseRefine = 'refine';
	const phaseVerify = 'verify';
	const phaseDone = 'done';
	const maxConsecutiveToolFailures = 5;
	const planMaxTokens = 16384;
	const defaultPlanTokenLimit = 100000;
	const defaultBuildTokenLimit = 750000;
	const defaultRefineTokenLimit = 150000;
	const defaultVerifyTokenLimit = 200000;

	/** @var AgentToolsSiteBuilderPlan|null */
	protected $plans = null;

	/** @var string[] */
	protected $stepMessages = [];

	/**
	 * Start a Site Builder session without calling an AI provider.
	 *
	 * @param string $description What the user wants to build
	 * @param array<string,mixed> $options
	 *  - agentId: configured AgentTools agent ID (default primary)
	 *  - preset: optional site preset name
	 *  - siteName: optional site or business name
	 *  - designDirection: editorial, minimal, bold-modern, friendly, or classic
	 *  - colorScheme: auto, warm, cool, earthy, vibrant, soft, or monochrome
	 *  - brandColor: optional six-digit hex color
	 *  - cssApproach: agenttools-base, uikit, or bootstrap
	 *  - javascript: vanilla or htmx
	 *  - planRoundLimit, buildRoundLimit, refineRoundLimit, verifyRoundLimit
	 *  - planTokenLimit, buildTokenLimit, refineTokenLimit, verifyTokenLimit
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function start(string $description, array $options = []): array {
		$this->requireStartAccess();
		$description = trim($description);
		if($description === '') throw new WireException($this->_('Please describe what you would like to build.'));
		$session = $this->newSession();
		if(!$session->lock($this->getLockMaxAge())) throw new WireException($this->_('Unable to lock the new Site Builder session.'));
		try {
			$agent = !empty($options['agentId']) ? $this->at->getAgents()->getById((string) $options['agentId']) : $this->at->getPrimaryAgent();
			if(!$agent) throw new WireException($this->_('No AgentTools agent is configured for Site Builder.'));
			$now = time();
			$state = [
				'id' => $session->getId(),
				'ownerUserId' => $this->isCli() ? 0 : (int) $this->wire()->user->id,
				'phase' => self::phasePlan,
				'status' => 'ready',
				'created' => $now,
				'updated' => $now,
				'finished' => 0,
				'description' => $description,
				'options' => $this->normalizeOptions(array_merge($options, ['agentId' => $agent->id])),
				'plan' => [],
				'planApproved' => false,
				'planErrors' => [],
				'planWarnings' => [],
				'planAttempts' => 0,
				'revisionRequest' => '',
				'correctedBuildError' => '',
				'buildResponse' => '',
				'engineerSessionId' => '',
				'engineerRound' => 0,
				'engineerTokenUsage' => $this->emptyTokenUsage(),
				'round' => 0,
				'phaseRounds' => ['plan' => 0, 'build' => 0, 'refine' => 0, 'verify' => 0],
				'tokenUsage' => $this->emptyTokenUsage(),
				'phaseTokenUsage' => [
					'plan' => $this->emptyTokenUsage(),
					'build' => $this->emptyTokenUsage(),
					'refine' => $this->emptyTokenUsage(),
					'verify' => $this->emptyTokenUsage(),
				],
				'refinementNumber' => 0,
				'refinementRequest' => '',
				'refinements' => [],
				'verificationPurpose' => 'build',
				'consecutiveToolFailures' => 0,
				'pageIds' => [],
				'error' => '',
			];
			$session->save($state);
			$session->saveManifest($this->newManifest($session->getId()));
			$this->log($session, $state, $this->_('Site Builder session started.'));
			return $this->result($state, [$this->_('Site Builder session started.')]);
		} finally {
			$session->unlock();
		}
	}

	/**
	 * Run at most one provider round for the current phase.
	 *
	 * @param string $id
	 * @param array<string,mixed> $runtimeOptions Runtime-only provider options
	 * @return array<string,mixed>
	 *
	 */
	public function step(string $id, array $runtimeOptions = []): array {
		$this->stepMessages = [];
		$session = $this->newSession($id);
		$state = $session->load();
		if(!$state) return $this->errorResult($id, $this->_('Site Builder session not found.'));
		if(!$this->canAccess($state)) return $this->errorResult($id, $this->_('You do not have access to this Site Builder session.'));
		if(($state['status'] ?? '') === 'paused' || ($state['phase'] ?? '') === self::phaseDone) return $this->result($state);
		if(!$session->lock($this->getLockMaxAge($runtimeOptions))) return $this->result($state, [], 'busy');

		try {
			$state = $session->load();
			if(!$state) throw new WireException($this->_('Site Builder state could not be read.'));
			if(!$this->canAccess($state)) throw new WireException($this->_('You do not have access to this Site Builder session.'));
			$state['status'] = 'running';
			$state['error'] = '';
			$this->resetTerminalEngineerSession($state);
			$session->save($state);
			switch((string) $state['phase']) {
				case self::phaseDescribe:
					$state['status'] = 'reset';
					$this->addMessage($session, $state, $this->_('Describe a new site or revise the previous plan to continue.'));
					break;
				case self::phasePlan:
					$this->stepPlan($session, $state, $runtimeOptions);
					break;
				case self::phaseBuild:
					$this->stepBuild($session, $state, $runtimeOptions);
					break;
				case self::phaseRefine:
					$this->stepRefine($session, $state, $runtimeOptions);
					break;
				case self::phaseVerify:
					$this->stepVerify($session, $state, $runtimeOptions);
					break;
				default:
					throw new WireException('Unknown Site Builder phase: ' . (string) $state['phase']);
			}
			$state['updated'] = time();
			$session->save($state);
			return $this->result($state, $this->stepMessages);
		} catch(\Throwable $e) {
			$state['status'] = 'error';
			$state['error'] = $e->getMessage();
			$state['updated'] = time();
			try {
				$session->save($state);
				$this->log($session, $state, $e->getMessage(), 'error');
			} catch(\Throwable $ignored) {
			}
			return $this->result($state, $this->stepMessages);
		} finally {
			$session->unlock();
		}
	}

	/**
	 * Ask the planner to revise its current plan in plain language.
	 *
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function revisePlan(string $id, string $request): array {
		$request = trim($request);
		return $this->updateLocked($id, function(AgentToolsSiteBuilderSession $session, array &$state) use($request): void {
			if(empty($state['plan'])) throw new WireException($this->_('There is no Site Builder plan to revise yet.'));
			$hadErrors = !empty($state['planErrors']);
			$warnings = [];
			$plan = $this->plans()->normalize((array) $state['plan'], $warnings);
			$errors = $this->plans()->validate($plan);
			$state['plan'] = $plan;
			$state['planErrors'] = $errors;
			$state['planWarnings'] = $warnings;
			foreach($warnings as $warning) $this->log($session, $state, $warning, 'warning');
			if($request === '' && !$hadErrors) throw new WireException($this->_('Please describe the requested plan revision.'));
			if($request === '' && !$errors) {
				$state['status'] = 'awaiting-approval';
				$state['error'] = '';
				$state['revisionRequest'] = '';
				$this->log($session, $state, $this->_('Plan normalized and ready for review.'));
				return;
			}
			$correction = $errors ? "Correct the current plan validation errors:\n- " . implode("\n- ", $errors) : '';
			if($request !== '' && $correction !== '') {
				$revisionRequest = $request . "\n\n" . $correction;
			} else {
				$revisionRequest = $request !== '' ? $request : $correction;
			}
			$state['phase'] = self::phasePlan;
			$state['status'] = 'ready';
			$state['planApproved'] = false;
			$state['revisionRequest'] = $revisionRequest;
			$state['engineerSessionId'] = '';
			$state['engineerRound'] = 0;
			$state['engineerTokenUsage'] = $this->emptyTokenUsage();
			$this->log($session, $state, $this->_('Plan revision requested.'));
		});
	}

	/**
	 * Approve the validated plan and make the build phase ready.
	 *
	 * @param bool $acceptOpenQuestions Explicitly accept unresolved questions
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function approvePlan(string $id, bool $acceptOpenQuestions = false): array {
		return $this->updateLocked($id, function(AgentToolsSiteBuilderSession $session, array &$state) use($acceptOpenQuestions): void {
			$plan = is_array($state['plan'] ?? null) ? $state['plan'] : [];
			$errors = $this->plans()->validate($plan);
			if($errors) throw new WireException($this->_('The Site Builder plan is not valid:') . ' ' . implode(' ', $errors));
			if(!$acceptOpenQuestions && !empty($plan['openQuestions'])) throw new WireException($this->_('Resolve or explicitly accept the plan open questions before building.'));
			$state['phase'] = self::phaseBuild;
			$state['status'] = 'ready';
			$state['planApproved'] = true;
			$state['verificationPurpose'] = 'build';
			$state['revisionRequest'] = '';
			$state['correctedBuildError'] = '';
			$state['engineerSessionId'] = '';
			$state['engineerRound'] = 0;
			$state['engineerTokenUsage'] = $this->emptyTokenUsage();
			$this->log($session, $state, $this->_('Plan approved. Build is ready.'));
		});
	}

	/**
	 * Start a bounded refinement of a completed site with a fresh agent session.
	 *
	 * @return array<string,mixed>
	 */
	public function refine(string $id, string $request): array {
		$request = trim($request);
		if($request === '') throw new WireException($this->_('Please describe what you would like to refine.'));
		return $this->updateLocked($id, function(AgentToolsSiteBuilderSession $session, array &$state) use($request): void {
			if(($state['phase'] ?? '') !== self::phaseDone || ($state['status'] ?? '') !== 'done') {
				throw new WireException($this->_('The site build must be complete before it can be refined.'));
			}
			$number = (int) ($state['refinementNumber'] ?? 0) + 1;
			$state['options'] = $this->normalizeOptions((array) ($state['options'] ?? []));
			$state['refinementNumber'] = $number;
			$state['refinementRequest'] = $request;
			$state['refinements'][] = [
				'number' => $number,
				'request' => $request,
				'response' => '',
				'changed' => false,
				'started' => time(),
				'finished' => 0,
			];
			$state['phase'] = self::phaseRefine;
			$state['status'] = 'ready';
			$state['finished'] = 0;
			$state['error'] = '';
			$state['verificationPurpose'] = 'refine';
			$state['phaseRounds']['refine'] = 0;
			$state['phaseTokenUsage']['refine'] = $this->emptyTokenUsage();
			$state['engineerSessionId'] = '';
			$state['engineerRound'] = 0;
			$state['engineerTokenUsage'] = $this->emptyTokenUsage();
			$state['consecutiveToolFailures'] = 0;
			$state['refinementChanged'] = false;
			$manifest = $session->loadManifest();
			$manifest['refinements'][] = [
				'number' => $number,
				'request' => $request,
				'started' => time(),
				'finished' => 0,
				'resources' => [],
			];
			$session->saveManifest($manifest);
			$this->log($session, $state, sprintf($this->_('Refinement %d is ready.'), $number));
		});
	}

	/**
	 * Stop requesting refinement work and verify everything completed so far.
	 *
	 * @return array<string,mixed>
	 */
	public function finishRefinement(string $id): array {
		return $this->updateLocked($id, function(AgentToolsSiteBuilderSession $session, array &$state): void {
			if(($state['phase'] ?? '') !== self::phaseRefine) {
				throw new WireException($this->_('This Site Builder session is not in the refinement phase.'));
			}
			$state['error'] = '';
			if(!empty($state['refinementChanged'])) {
				$this->enterVerifyPhase($session, $state);
			} else {
				$this->completeRefinementWithoutChanges($session, $state);
			}
		});
	}

	/** Pause a build between rounds. @return array<string,mixed> */
	public function pause(string $id): array {
		return $this->updateLocked($id, function(AgentToolsSiteBuilderSession $session, array &$state): void {
			$state['status'] = 'paused';
			$this->log($session, $state, $this->_('Build paused.'));
		});
	}

	/** Resume a paused or work-limited build. @return array<string,mixed> */
	public function resume(string $id): array {
		return $this->updateLocked($id, function(AgentToolsSiteBuilderSession $session, array &$state): void {
			if(($state['phase'] ?? '') === self::phaseDone) throw new WireException($this->_('This Site Builder session is already complete.'));
			$failedPlan = ($state['phase'] ?? '') === self::phasePlan && !empty($state['planErrors']);
			if($failedPlan) {
				$state['engineerSessionId'] = '';
				$state['engineerRound'] = 0;
				$state['engineerTokenUsage'] = $this->emptyTokenUsage();
			} else {
				$this->resetTerminalEngineerSession($state);
			}
			$state['consecutiveToolFailures'] = 0;
			$state['status'] = 'ready';
			$state['error'] = '';
			$this->log($session, $state, $this->_('Build resumed.'));
		});
	}

	/**
	 * Increase a phase round/token allowance before resuming.
	 *
	 * @return array<string,mixed>
	 *
	 */
	public function extend(string $id, int $rounds = 5, int $tokens = 50000): array {
		return $this->updateLocked($id, function(AgentToolsSiteBuilderSession $session, array &$state) use($rounds, $tokens): void {
			$phase = (string) ($state['phase'] ?? '');
			if(!in_array($phase, [self::phasePlan, self::phaseBuild, self::phaseRefine, self::phaseVerify], true)) throw new WireException($this->_('This phase cannot be extended.'));
			$key = $phase . 'RoundLimit';
			$tokenKey = $phase . 'TokenLimit';
			$state['options'][$key] = min(200, (int) $state['options'][$key] + max(1, $rounds));
			$state['options'][$tokenKey] = min(2000000, max(
				$this->getDefaultTokenLimit($phase),
				(int) $state['options'][$tokenKey] + max(1000, $tokens)
			));
			$this->resetTerminalEngineerSession($state);
			$state['consecutiveToolFailures'] = 0;
			$state['status'] = 'ready';
			$state['error'] = '';
			$this->log($session, $state, sprintf($this->_('Allowed more work for the %s phase.'), $phase));
		});
	}

	/**
	 * Undo exactly the resources recorded in this build manifest.
	 *
	 * @return array<string,mixed>
	 *
	 */
	public function startOver(string $id): array {
		$this->stepMessages = [];
		$session = $this->newSession($id);
		$state = $session->load();
		if(!$state || !$this->canAccess($state)) return $this->errorResult($id, $this->_('Site Builder session not found or inaccessible.'));
		if(!$session->lock($this->getLockMaxAge())) return $this->result($state, [], 'busy');
		try {
			$tools = new AgentToolsSiteBuilderTools($this->at, $session, $this->plans());
			$this->wire($tools);
			$rollback = $tools->startOver();
			if($rollback['ok']) {
				$this->resetManifestAfterRollback($session, $id);
				$state['phase'] = self::phaseDescribe;
				$state['status'] = 'reset';
				$state['error'] = '';
				$state['finished'] = time();
				$state['planApproved'] = false;
				$state['planErrors'] = [];
				$state['planAttempts'] = 0;
				$state['revisionRequest'] = '';
				$state['engineerSessionId'] = '';
				$state['engineerRound'] = 0;
				$state['engineerTokenUsage'] = $this->emptyTokenUsage();
				$state['round'] = 0;
				$state['phaseRounds'] = ['plan' => 0, 'build' => 0, 'refine' => 0, 'verify' => 0];
				$state['tokenUsage'] = $this->emptyTokenUsage();
				$state['phaseTokenUsage'] = [
					'plan' => $this->emptyTokenUsage(),
					'build' => $this->emptyTokenUsage(),
					'refine' => $this->emptyTokenUsage(),
					'verify' => $this->emptyTokenUsage(),
				];
				$state['consecutiveToolFailures'] = 0;
				$state['pageIds'] = [];
			} else {
				$state['status'] = 'error';
				$state['error'] = $this->_('Some Site Builder resources could not be restored or removed.');
			}
			$state['updated'] = time();
			$session->save($state);
			return array_merge($this->result($state, [$this->_('Site Builder changes were rolled back.')]), ['rollback' => $rollback]);
		} finally {
			$session->unlock();
		}
	}

	/** @return array<string,mixed> */
	public function getState(string $id): array {
		$state = $this->newSession($id)->load();
		return $state && $this->canAccess($state) ? $state : [];
	}

	/** @return array<string,mixed> */
	public function getPlan(string $id): array {
		$state = $this->getState($id);
		return is_array($state['plan'] ?? null) ? $state['plan'] : [];
	}

	/**
	 * Does an updating plan need explicit existing-site confirmation?
	 *
	 * Profile scaffolding is expected to be updated during a site's first build. Once a
	 * front-end page or editable site file has changed after installation, preserve the
	 * existing confirmation and backup reminder.
	 *
	 * @param array<string,mixed> $plan
	 */
	public function planNeedsUpdateConfirmation(array $plan): bool {
		$hasUpdates = false;
		foreach(['fields', 'templates', 'pages', 'files', 'modules'] as $type) {
			foreach((array) ($plan[$type] ?? []) as $item) {
				if(is_array($item) && ($item['disposition'] ?? '') === 'update') {
					$hasUpdates = true;
					break 2;
				}
			}
		}
		return $hasUpdates && !$this->isFreshSite();
	}

	/**
	 * Is this an installation whose front-end content and editable files remain fresh?
	 */
	protected function isFreshSite(): bool {
		$installed = (int) $this->wire()->config->installed;
		if($installed < 1) return false;
		$cutoff = $installed + 300;
		if($this->wire()->pages->count("modified>$cutoff, has_parent!=2, id!=2, include=all")) return false;
		return !$this->siteFilesChangedSince($cutoff);
	}

	/**
	 * Have editable front-end site files changed after installation?
	 */
	protected function siteFilesChangedSince(int $timestamp): bool {
		$sitePath = $this->wire()->config->paths->site;
		foreach([$sitePath . 'templates/', $sitePath . 'classes/'] as $path) {
			if(!is_dir($path)) continue;
			try {
				$files = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
				);
				foreach($files as $file) {
					if(strpos($file->getFilename(), '.') === 0) continue;
					if($file->isFile() && $file->getMTime() > $timestamp) return true;
				}
			} catch(\Throwable $e) {
				return true;
			}
		}
		foreach([$sitePath . 'ready.php', $sitePath . 'init.php'] as $file) {
			if(!is_file($file)) continue;
			$modified = @filemtime($file);
			if($modified === false || $modified > $timestamp) return true;
		}
		return false;
	}

	/** @return array<string,mixed> */
	public function getManifest(string $id): array {
		$state = $this->getState($id);
		return $state ? $this->newSession($id)->loadManifest() : [];
	}

	/** @return array<int,array<string,mixed>> */
	public function getLog(string $id, int $afterTimestamp = 0): array {
		$state = $this->getState($id);
		return $state ? $this->newSession($id)->loadLog($afterTimestamp) : [];
	}

	/** @return string[] */
	public function validatePlan(array $plan): array {
		return $this->plans()->validate($plan);
	}

	/**
	 * Execute one build tool directly. Primarily useful for tests and CLI clients.
	 *
	 * @return array|string|null
	 * @throws WireException
	 *
	 */
	public function executeBuildTool(string $id, string $name, array $input = []) {
		$session = $this->newSession($id);
		$state = $session->load();
		if(!$state || !$this->canAccess($state)) throw new WireException($this->_('Site Builder session not found or inaccessible.'));
		if(!$session->lock($this->getLockMaxAge())) throw new WireException($this->_('Site Builder session is busy.'));
		try {
			$tools = new AgentToolsSiteBuilderTools($this->at, $session, $this->plans());
			$this->wire($tools);
			$result = $tools->execute($name, $input);
			$this->accountToolResult($session, $state, $name, $result);
			$session->save($state);
			return $result;
		} finally {
			$session->unlock();
		}
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $runtimeOptions */
	protected function stepPlan(AgentToolsSiteBuilderSession $session, array &$state, array $runtimeOptions): void {
		if(($state['status'] ?? '') === 'awaiting-approval') return;
		if($this->isBudgetReached($state, self::phasePlan)) return;
		if(empty($state['engineerSessionId'])) {
			$request = $this->getPlanRequest($state);
			$started = $this->at->engineer()->startAskSession($request, $this->getAskOptions($state, self::phasePlan));
			if(($started['status'] ?? '') === 'error') throw new WireException((string) $started['error']);
			$state['engineerSessionId'] = (string) $started['sessionId'];
			$state['engineerRound'] = 0;
			$session->save($state);
		}
		$result = $this->at->engineer()->askStep((string) $state['engineerSessionId'], $runtimeOptions);
		if(($result['status'] ?? '') === 'busy') {
			$state['status'] = 'busy';
			return;
		}
		$this->accountAskResult($state, $result);
		if(!empty($result['error'])) throw new WireException((string) $result['error']);
		if(empty($result['done'])) {
			$state['status'] = 'continue';
			$this->isBudgetReached($state, self::phasePlan);
			return;
		}
		$warnings = [];
		try {
			$plan = $this->plans()->decode((string) $result['response']);
			$plan = $this->plans()->normalize($plan, $warnings);
			$errors = $this->plans()->validate($plan);
		} catch(\Throwable $e) {
			$plan = [];
			$errors = [$e->getMessage()];
		}
		$state['planWarnings'] = $warnings;
		foreach($warnings as $warning) $this->addMessage($session, $state, $warning, 'warning');
		if($errors) {
			$this->recordPlanFailure($session, $state, $plan, $errors, (string) $result['response']);
			$state['revisionRequest'] = "Correct the previous plan. Validation errors:\n- " . implode("\n- ", $errors);
			if($plan) $state['plan'] = $plan;
			$state['engineerSessionId'] = '';
			$state['engineerRound'] = 0;
			$state['engineerTokenUsage'] = $this->emptyTokenUsage();
			if($state['planAttempts'] >= 3) {
				$state['status'] = 'paused';
				$state['error'] = $this->_('Planning paused after three invalid plans. The user may revise or resume it.');
				$this->addMessage($session, $state, $state['error'], 'warning');
				return;
			}
			$state['status'] = 'continue';
			if($this->isBudgetReached($state, self::phasePlan)) return;
			$this->addMessage($session, $state, $this->_('The plan needs correction; another planning round is ready.'), 'warning');
			return;
		}
		$state['plan'] = $plan;
		$state['planErrors'] = [];
		$state['revisionRequest'] = '';
		$state['status'] = 'awaiting-approval';
		$this->addMessage($session, $state, $this->_('The Site Builder plan is ready for review.'));
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $runtimeOptions */
	protected function stepBuild(AgentToolsSiteBuilderSession $session, array &$state, array $runtimeOptions): void {
		if(empty($state['planApproved'])) throw new WireException($this->_('The Site Builder plan has not been approved.'));
		if($this->isBuildComplete($state, $session->loadManifest()) && empty($state['engineerSessionId'])) {
			$this->enterVerifyPhase($session, $state);
			return;
		}
		if($this->isBudgetReached($state, self::phaseBuild)) return;
		if(empty($state['engineerSessionId'])) {
			$started = $this->at->engineer()->startAskSession($this->getBuildRequest($state, $session->loadManifest()), $this->getAskOptions($state, self::phaseBuild));
			if(($started['status'] ?? '') === 'error') throw new WireException((string) $started['error']);
			$state['engineerSessionId'] = (string) $started['sessionId'];
			$state['engineerRound'] = 0;
			$state['engineerTokenUsage'] = $this->emptyTokenUsage();
			$session->save($state);
		}
		$tools = new AgentToolsSiteBuilderTools($this->at, $session, $this->plans());
		$this->wire($tools);
		$planFailure = '';
		$runtimeOptions['toolHandler'] = function(string $name, array $input) use($tools, $session, &$state, &$planFailure) {
			if(($state['status'] ?? '') === 'paused') return ['ok' => false, 'error' => (string) $state['error']];
			$result = $tools->execute($name, $input);
			$this->accountToolResult($session, $state, $name, $result);
			if(is_array($result) && !empty($result['planError'])) {
				$planFailure = (string) ($result['error'] ?? 'The approved plan could not be carried out.');
				$state['status'] = 'paused';
				$state['error'] = $planFailure;
			}
			return $result;
		};
		$runtimeOptions['onInterrupt'] = 'resume';
		$result = $this->at->engineer()->askStep((string) $state['engineerSessionId'], $runtimeOptions);
		if(($result['status'] ?? '') === 'busy') {
			$state['status'] = 'busy';
			return;
		}
		$this->accountAskResult($state, $result);
		if($planFailure !== '') {
			$this->returnBuildFailureToPlanning($session, $state, $planFailure);
			return;
		}
		if(($state['status'] ?? '') === 'paused') return;
		if(!empty($result['error'])) throw new WireException((string) $result['error']);
		if($this->isBuildComplete($state, $session->loadManifest())) {
			if(!empty($result['done'])) {
				$state['buildResponse'] = $this->normalizeAgentResponse((string) ($result['response'] ?? ''));
				$this->enterVerifyPhase($session, $state);
			} else {
				$state['status'] = 'continue';
				$this->isBudgetReached($state, self::phaseBuild);
			}
			return;
		}
		if(empty($result['done'])) {
			$state['status'] = 'continue';
			$this->isBudgetReached($state, self::phaseBuild);
			return;
		}
		$state['engineerSessionId'] = '';
		$state['engineerRound'] = 0;
		$state['engineerTokenUsage'] = $this->emptyTokenUsage();
		$state['status'] = 'continue';
		$this->addMessage($session, $state, $this->_('The agent stopped before the approved plan was complete; a continuation round is ready.'), 'warning');
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $runtimeOptions */
	protected function stepRefine(AgentToolsSiteBuilderSession $session, array &$state, array $runtimeOptions): void {
		if(empty($state['planApproved'])) throw new WireException($this->_('The Site Builder plan has not been approved.'));
		if($this->isBudgetReached($state, self::phaseRefine)) return;
		if(empty($state['engineerSessionId'])) {
			$started = $this->at->engineer()->startAskSession(
				$this->getRefineRequest($state, $session->loadManifest()),
				$this->getAskOptions($state, self::phaseRefine)
			);
			if(($started['status'] ?? '') === 'error') throw new WireException((string) $started['error']);
			$state['engineerSessionId'] = (string) $started['sessionId'];
			$state['engineerRound'] = 0;
			$state['engineerTokenUsage'] = $this->emptyTokenUsage();
			$session->save($state);
		}
		$tools = new AgentToolsSiteBuilderTools($this->at, $session, $this->plans());
		$this->wire($tools);
		$runtimeOptions['toolHandler'] = function(string $name, array $input) use($tools, $session, &$state) {
			if(($state['status'] ?? '') === 'paused') return ['ok' => false, 'error' => (string) $state['error']];
			$result = $tools->execute($name, $input);
			$this->accountToolResult($session, $state, $name, $result);
			return $result;
		};
		$runtimeOptions['onInterrupt'] = 'resume';
		$result = $this->at->engineer()->askStep((string) $state['engineerSessionId'], $runtimeOptions);
		if(($result['status'] ?? '') === 'busy') {
			$state['status'] = 'busy';
			return;
		}
		$this->accountAskResult($state, $result);
		if(($state['status'] ?? '') === 'paused') return;
		if(!empty($result['error'])) throw new WireException((string) $result['error']);
		if(empty($result['done'])) {
			$state['status'] = 'continue';
			$this->isBudgetReached($state, self::phaseRefine);
			return;
		}
		$this->storeRefinementResponse($state, (string) ($result['response'] ?? ''));
		if(!empty($state['refinementChanged'])) {
			$this->enterVerifyPhase($session, $state);
		} else {
			$this->completeRefinementWithoutChanges($session, $state);
		}
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $runtimeOptions */
	protected function stepVerify(AgentToolsSiteBuilderSession $session, array &$state, array $runtimeOptions): void {
		if($this->isVerificationComplete($state, $session->loadManifest())) {
			$this->completeBuild($session, $state);
			return;
		}
		if($this->isBudgetReached($state, self::phaseVerify)) return;
		if(empty($state['engineerSessionId'])) {
			$started = $this->at->engineer()->startAskSession($this->getVerifyRequest($state, $session->loadManifest()), $this->getAskOptions($state, self::phaseVerify));
			if(($started['status'] ?? '') === 'error') throw new WireException((string) $started['error']);
			$state['engineerSessionId'] = (string) $started['sessionId'];
			$state['engineerRound'] = 0;
			$state['engineerTokenUsage'] = $this->emptyTokenUsage();
			$session->save($state);
		}
		$tools = new AgentToolsSiteBuilderTools($this->at, $session, $this->plans());
		$this->wire($tools);
		$runtimeOptions['toolHandler'] = function(string $name, array $input) use($tools, $session, &$state) {
			if(($state['status'] ?? '') === 'paused') return ['ok' => false, 'error' => (string) $state['error']];
			$result = $tools->execute($name, $input);
			$this->accountToolResult($session, $state, $name, $result);
			return $result;
		};
		$runtimeOptions['onInterrupt'] = 'resume';
		$result = $this->at->engineer()->askStep((string) $state['engineerSessionId'], $runtimeOptions);
		if(($result['status'] ?? '') === 'busy') {
			$state['status'] = 'busy';
			return;
		}
		$this->accountAskResult($state, $result);
		if($this->isVerificationComplete($state, $session->loadManifest())) {
			$this->completeBuild($session, $state);
			return;
		}
		if(($state['status'] ?? '') === 'paused') return;
		if(!empty($result['error'])) throw new WireException((string) $result['error']);
		if(empty($result['done'])) {
			$state['status'] = 'continue';
			$this->isBudgetReached($state, self::phaseVerify);
			return;
		}
		if($this->isVerificationComplete($state, $session->loadManifest())) {
			$this->completeBuild($session, $state);
		} else {
			$state['engineerSessionId'] = '';
			$state['engineerRound'] = 0;
			$state['engineerTokenUsage'] = $this->emptyTokenUsage();
			$state['status'] = 'continue';
			$this->addMessage($session, $state, $this->_('Verification is incomplete; a continuation round is ready.'), 'warning');
		}
	}

	/** @return array<string,mixed> */
	protected function getAskOptions(array $state, string $phase): array {
		$options = (array) $state['options'];
		$agent = $this->at->getAgents()->getById((string) $options['agentId']);
		if(!$agent) $agent = $this->at->getPrimaryAgent();
		if(!$agent) throw new WireException($this->_('The configured Site Builder agent is no longer available.'));
		$provider = $this->getProvider($state);
		$tools = [];
		$systemPrompt = $this->plans()->getSystemPrompt();
		if($phase === self::phaseBuild) {
			$tools = $this->getToolDefinitions($provider, self::phaseBuild);
			$systemPrompt = $this->getBuildSystemPrompt($state);
		} else if($phase === self::phaseRefine) {
			$tools = $this->getToolDefinitions($provider, self::phaseRefine);
			$systemPrompt = $this->getRefineSystemPrompt($state);
		} else if($phase === self::phaseVerify) {
			$tools = $this->getToolDefinitions($provider, self::phaseVerify);
			$systemPrompt = $this->getVerifySystemPrompt($state);
		}
		$result = [
			'agentId' => (string) $options['agentId'],
			'provider' => (string) $agent->provider,
			'model' => (string) $agent->model,
			'endpoint' => (string) $agent->endpointUrl,
			'apiKey' => (string) $agent->apiKey,
			'context' => 'none',
			'readOnlyEval' => $phase !== self::phasePlan,
			'systemPrompt' => $systemPrompt,
			'tools' => $tools,
			'maxIterations' => (int) $options[$phase . 'RoundLimit'],
			'timeout' => (int) $this->at->get('engineer_request_timeout'),
			'traceType' => 'site-builder-' . $phase,
			'cacheInitialMessage' => $provider === AgentToolsEngineer::providerAnthropic,
		];
		if($phase === self::phasePlan && $provider === AgentToolsEngineer::providerAnthropic) {
			$result['anthropic'] = ['max_tokens' => self::planMaxTokens];
			if(strpos(strtolower((string) $agent->model), 'claude-sonnet-5') === 0) {
				$result['anthropic']['thinking'] = ['type' => 'disabled'];
			}
		}
		return $result;
	}

	/** @return array<int,array<string,mixed>> */
	protected function getToolDefinitions(string $provider, string $phase): array {
		$base = $this->at->engineer()->getToolDefinitions($provider, 'site', false, false, true);
		$allowedBase = $phase === self::phaseVerify ? ['eval_php', 'read_file', 'api_docs'] : ['eval_php', 'site_info', 'read_file', 'api_docs'];
		$tools = [];
		foreach($base as $tool) {
			$name = $provider === AgentToolsEngineer::providerAnthropic ? ($tool['name'] ?? '') : ($tool['function']['name'] ?? '');
			if(in_array($name, $allowedBase, true)) $tools[] = $tool;
		}
		if($phase === self::phaseVerify) {
			$definitions = $this->getVerifyToolSchemas();
		} else if($phase === self::phaseRefine) {
			$definitions = $this->getRefineToolSchemas();
		} else {
			$definitions = $this->getBuildToolSchemas();
		}
		foreach($definitions as $name => $definition) $tools[] = $this->formatTool($provider, $name, $definition['description'], $definition['parameters']);
		return $tools;
	}

	/** @return array<string,array<string,mixed>> */
	protected function getBuildToolSchemas(): array {
		$nameList = ['type' => 'array', 'items' => ['type' => 'string']];
		return [
			'create_fields' => [
				'description' => 'Create, update, or register one or more fields exactly as defined in the approved plan. Call before create_templates.',
				'parameters' => ['type' => 'object', 'properties' => ['names' => $nameList], 'required' => ['names']],
			],
			'create_templates' => [
				'description' => 'Create or update one or more templates and fieldgroups exactly as defined in the approved plan. Include related templates together when they have family references.',
				'parameters' => ['type' => 'object', 'properties' => ['names' => $nameList], 'required' => ['names']],
			],
			'create_pages' => [
				'description' => "Create or update approved plan pages. content is only for fields listed in each page's contentBrief. Fields in the plan's values are applied automatically; do not include them. Include parents before children when practical.",
				'parameters' => [
					'type' => 'object',
					'properties' => ['pages' => [
						'type' => 'array',
						'items' => [
							'type' => 'object',
							'properties' => [
								'key' => ['type' => 'string'],
								'content' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
							],
							'required' => ['key'],
						],
					]],
					'required' => ['pages'],
				],
			],
			'write_file' => [
				'description' => 'Write exactly one file listed in the approved plan. PHP is parsed before an atomic write and the previous file is backed up for Start over.',
				'parameters' => ['type' => 'object', 'properties' => [
					'path' => ['type' => 'string'], 'content' => ['type' => 'string'],
				], 'required' => ['path', 'content']],
			],
			'install_modules' => [
				'description' => 'Install one or more modules listed in the approved plan. Generated module files must be written first.',
				'parameters' => ['type' => 'object', 'properties' => ['names' => $nameList], 'required' => ['names']],
			],
			'report_progress' => [
				'description' => 'Append a short user-facing progress line to the Site Builder log.',
				'parameters' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']], 'required' => ['message']],
			],
		];
	}

	/** @return array<string,array<string,mixed>> */
	protected function getVerifyToolSchemas(): array {
		$schemas = $this->getBuildToolSchemas();
		$schemas = array_intersect_key($schemas, array_flip(['write_file', 'report_progress']));
		$schemas['fetch_page'] = [
			'description' => "Verify a planned page. kind='front' renders its front-end template; kind='admin' builds its page-edit Inputfields and returns the admin URL.",
			'parameters' => ['type' => 'object', 'properties' => [
				'page' => ['type' => 'string'],
				'kind' => ['type' => 'string', 'enum' => ['front', 'admin']],
			], 'required' => ['page', 'kind']],
		];
		return $schemas;
	}

	/** @return array<string,array<string,mixed>> */
	protected function getRefineToolSchemas(): array {
		$schemas = $this->getBuildToolSchemas();
		$schemas = array_intersect_key($schemas, array_flip(['write_file', 'report_progress']));
		$schemas['refine_pages'] = [
			'description' => 'Make small content corrections to planned pages or add sample pages using templates already approved by the plan. Existing page structure cannot be changed.',
			'parameters' => [
				'type' => 'object',
				'properties' => ['pages' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'key' => ['type' => 'string'],
							'parent' => ['type' => 'string'],
							'name' => ['type' => 'string'],
							'template' => ['type' => 'string'],
							'status' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'hidden']],
							'values' => ['type' => 'object'],
						],
						'required' => ['key', 'values'],
					],
				]],
				'required' => ['pages'],
			],
		];
		return $schemas;
	}

	/** @return array<string,mixed> */
	protected function formatTool(string $provider, string $name, string $description, array $parameters): array {
		if($provider === AgentToolsEngineer::providerAnthropic) return ['name' => $name, 'description' => $description, 'input_schema' => $parameters];
		return ['type' => 'function', 'function' => ['name' => $name, 'description' => $description, 'parameters' => $parameters]];
	}

	protected function getPlanRequest(array $state): string {
		$options = $this->normalizeOptions((array) ($state['options'] ?? []));
		$request = "Create the Site Builder JSON plan for this request:\n\n" . $state['description'];
		$request .= "\n\nSelected design direction: {$options['designDirection']}. Color scheme: {$options['colorScheme']}. CSS approach: {$options['cssApproach']}. JavaScript: {$options['javascript']}.";
		if($options['brandColor'] !== '') $request .= " Brand color: {$options['brandColor']}; build the palette around it unless the selected scheme is monochrome.";
		if($options['siteName'] !== '') {
			$request .= "\nSite or business name: " . json_encode($options['siteName'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '. Use this exact name as the home page title.';
		} else {
			$request .= "\nNo site or business name was supplied. Use an obvious placeholder suited to the site type, such as Your Name for a portfolio, Company Name for a business, or Event Series Name for events. Do not use the generic site type as the site name. Use the placeholder as the home page title.";
		}
		if($options['preset'] !== '') $request .= "\nPreset: {$options['preset']}.";
		if(!empty($state['revisionRequest'])) {
			$request .= "\n\nCurrent plan:\n" . json_encode($state['plan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$request .= "\n\nRequested revision or validation corrections:\n" . $state['revisionRequest'];
		}
		$request .= "\n\nCURRENT SITE SNAPSHOT:\n" . json_encode($this->plans()->getSiteSnapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		return $request;
	}

	/** @param string[] $errors */
	protected function recordPlanFailure(AgentToolsSiteBuilderSession $session, array &$state, array $plan, array $errors, string $response): void {
		$state['planAttempts'] = (int) ($state['planAttempts'] ?? 0) + 1;
		$state['planErrors'] = array_values($errors);
		$file = $session->savePlanAttempt((int) $state['planAttempts'], $plan, $errors, $response);
		$this->log($session, $state, sprintf($this->_('Plan attempt %d failed validation.'), $state['planAttempts']), 'plan-error', [
			'attempt' => (int) $state['planAttempts'],
			'errors' => array_values($errors),
			'planFile' => $file,
		]);
	}

	protected function getBuildRequest(array $state, array $manifest): string {
		return "Carry out the approved Site Builder plan incrementally. Inspect the manifest summary, perform only missing work, report useful progress, and do not stop until every approved resource is complete. Every manifest item with status complete is already done; do not repeat its tool call.\n\nPLAN:\n" .
			json_encode($state['plan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
			"\n\nCURRENT MANIFEST:\n" . json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
			$this->getCompletedFileInstructions($manifest);
	}

	protected function getVerifyRequest(array $state, array $manifest): string {
		$context = ($state['verificationPurpose'] ?? 'build') === 'refine' ? "\n\nREFINEMENT REQUEST:\n" . (string) ($state['refinementRequest'] ?? '') : '';
		return "Verify every route and representative admin page in the approved plan with fetch_page. Fix generated files with write_file when needed, then repeat failed checks. Do not claim completion until all required checks return ok.\n\nPLAN:\n" .
			json_encode($state['plan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
			"\n\nCURRENT MANIFEST:\n" . json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . $context;
	}

	protected function getRefineRequest(array $state, array $manifest): string {
		return "Refine the completed site according to the request below. Work within the approved plan and current schema. Make only the small corrections requested, then stop so Site Builder can verify the result.\n\nREFINEMENT REQUEST:\n" .
			(string) ($state['refinementRequest'] ?? '') .
			"\n\nAPPROVED PLAN:\n" . json_encode($state['plan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
			"\n\nCURRENT MANIFEST:\n" . json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
			"\n\nFRESH SITE SNAPSHOT:\n" . json_encode($this->plans()->getSiteSnapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	protected function getBuildSystemPrompt(array $state): string {
		return <<<'PROMPT'
	You are the build phase of ProcessWire AgentTools Site Builder. The user has approved the supplied plan. Execute it exactly and incrementally with the dedicated tools. Install approved modules before creating fields that use them. Use create_fields before create_templates, create_templates before create_pages, and write one file per write_file call. The tools consume canonical plan definitions, so pass only approved names, page keys/content, or file content.

Never use eval_php to create or modify planned fields, templates, pages, files, or modules because those changes would bypass the rollback manifest. Use eval_php only for inspection or exceptional non-resource work, and explicitly report any mutation it performs. Do not create migrations. Stop and report a plan conflict rather than improvising a different resource.

	Generated templates use ProcessWire markup regions with _init.php prepended and _main.php appended. When the supplied site profile documents named regions and their default content, template files must output only content those regions do not already render; do not repeat the page title, summary, or other region content. Rich text uses TinyMCE. Template updates are additive: preserve all existing fields and data, adding or reconfiguring only fields named in the approved plan. Hooks shared by front end and admin go in site/ready.php or site/init.php; front-end-only hooks go in site/templates/_init.php; admin-only hooks go in site/templates/admin.php. Do not create a module just to hold hooks. Follow Page-class naming conventions. When the supplied site profile documents an image helper, render every image through that helper and design around the image area it returns, including placeholders; the layout must still work when placeholders are disabled and the helper returns nothing. Without a documented image helper, avoid warnings and broken image markup and make layouts look complete without an image. Omit empty optional values rather than casting them into visible placeholders such as 0. Prefer the approved CSS approach and vanilla JavaScript unless the plan says otherwise.

A manifest item with status complete is already done. Never repeat create_fields, create_templates, create_pages, install_modules, or write_file for a complete item during the build phase. An unchanged write_file result confirms the file is already correct; do not submit it again. Completed files may be rewritten only during verification when a verification result identifies a problem.

Before a final response, compare your work with the complete plan. If anything remains, call tools rather than merely describing what should happen. Keep prose and progress reports concise.
PROMPT;
	}

	/** @param array<string,mixed> $manifest */
	protected function getCompletedFileInstructions(array $manifest): string {
		$lines = [];
		foreach((array) ($manifest['files'] ?? []) as $item) {
			if(!is_array($item) || ($item['status'] ?? '') !== 'complete') continue;
			$path = (string) ($item['key'] ?? '');
			if($path === '') continue;
			$bytes = (int) ($item['bytes'] ?? 0);
			$lines[] = "- $path: written, $bytes bytes; do not rewrite unless verification reports a problem.";
		}
		return $lines ? "\n\nCOMPLETED FILES:\n" . implode("\n", $lines) : '';
	}

	protected function getVerifySystemPrompt(array $state): string {
		return <<<'PROMPT'
You are the verification phase of ProcessWire AgentTools Site Builder. Verify all front-end routes and every representative admin page specified by the approved plan. Use fetch_page for each required check. Inspect and fix only approved generated files with write_file, then fetch failed pages again. Use eval_php only for read-only diagnosis; do not create schema or content during verification. Report concise progress and do not claim success while any required verification is missing or failed.
PROMPT;
	}

	protected function getRefineSystemPrompt(array $state): string {
		return <<<'PROMPT'
You are the refinement phase of ProcessWire AgentTools Site Builder. Apply one small, focused correction to an already completed site. Use write_file only for files already listed in the approved plan. Use refine_pages to correct content on planned pages or add a small number of sample pages with templates and fields already approved by the plan. Use eval_php only for read-only inspection. When inspecting ProcessWire objects, echo selected scalar properties such as IDs, names, titles, or counts; never pass Wire, Page, or PageArray objects to var_dump(), var_export(), or print_r(). Do not create or change fields, templates, modules, migrations, or unapproved files. Do not turn a refinement into a major new feature; tell the user that a new build is needed when the request requires new schema or broad architecture.

If the request is ambiguous, ask one short clarifying question and stop without making changes. The user can answer with a new refinement.

Keep repeatable content in ProcessWire pages and fields rather than hardcoding repeated items in template files. Report concise progress. Before finishing, confirm that the requested correction was actually made. Site Builder will run a separate verification phase after you stop when changes were made.
PROMPT;
	}

	protected function isBuildComplete(array $state, array $manifest): bool {
		$required = ['fields' => 'name', 'templates' => 'name', 'pages' => 'key', 'files' => 'path', 'modules' => 'name'];
		foreach($required as $type => $keyName) {
			$done = [];
			foreach((array) ($manifest[$type] ?? []) as $entry) {
				if(($entry['status'] ?? '') === 'complete') $done[(string) ($entry['key'] ?? '')] = true;
			}
			foreach((array) ($state['plan'][$type] ?? []) as $item) {
				if(!is_array($item) || ($item['disposition'] ?? '') === 'reuse') continue;
				if($type === 'modules' && ($item['disposition'] ?? '') === 'update') continue;
				if(!isset($done[(string) ($item[$keyName] ?? '')])) return false;
			}
		}
		return true;
	}

	protected function isVerificationComplete(array $state, array $manifest): bool {
		$results = (array) ($manifest['verification'] ?? []);
		foreach((array) ($state['plan']['verification']['routes'] ?? []) as $route) {
			$key = 'front:' . (string) ($route['page'] ?? '');
			if(empty($results[$key]['ok'])) return false;
		}
		foreach((array) ($state['plan']['verification']['adminPages'] ?? []) as $item) {
			$key = 'admin:' . (string) ($item['page'] ?? '');
			if(empty($results[$key]['ok'])) return false;
		}
		return true;
	}

	protected function enterVerifyPhase(AgentToolsSiteBuilderSession $session, array &$state): void {
		if(($state['phase'] ?? '') === self::phaseRefine) {
			$state['phaseRounds']['verify'] = 0;
			$state['phaseTokenUsage']['verify'] = $this->emptyTokenUsage();
		}
		$state['phase'] = self::phaseVerify;
		$state['status'] = 'ready';
		$state['engineerSessionId'] = '';
		$state['engineerRound'] = 0;
		$state['engineerTokenUsage'] = $this->emptyTokenUsage();
		$state['consecutiveToolFailures'] = 0;
		$message = ($state['verificationPurpose'] ?? 'build') === 'refine' ?
			$this->_('Refinement complete. Verification is ready.') :
			$this->_('Build complete. Verification is ready.');
		$this->addMessage($session, $state, $message);
	}

	protected function completeBuild(AgentToolsSiteBuilderSession $session, array &$state): void {
		$refined = ($state['verificationPurpose'] ?? 'build') === 'refine';
		$state['phase'] = self::phaseDone;
		$state['status'] = 'done';
		$state['finished'] = time();
		$state['engineerSessionId'] = '';
		if($refined) $this->finishRefinementRecord($session, $state);
		$this->addMessage($session, $state, $refined ? $this->_('Site refinement finished successfully.') : $this->_('Site Builder finished successfully.'));
		$this->at->sitemap()->generate();
		$this->at->sitemap()->generateSchema();
	}

	/** Complete a refinement that made no site changes, without running verification. */
	protected function completeRefinementWithoutChanges(AgentToolsSiteBuilderSession $session, array &$state): void {
		$state['phase'] = self::phaseDone;
		$state['status'] = 'done';
		$state['finished'] = time();
		$state['engineerSessionId'] = '';
		$state['engineerRound'] = 0;
		$state['engineerTokenUsage'] = $this->emptyTokenUsage();
		$state['consecutiveToolFailures'] = 0;
		$this->finishRefinementRecord($session, $state);
		$this->addMessage($session, $state, $this->_('Refinement finished without site changes; verification was not needed.'));
	}

	/** Mark the active refinement complete in state and manifest. */
	protected function finishRefinementRecord(AgentToolsSiteBuilderSession $session, array &$state): void {
		$number = (int) ($state['refinementNumber'] ?? 0);
		$finished = time();
		foreach((array) ($state['refinements'] ?? []) as $index => $item) {
			if((int) ($item['number'] ?? 0) === $number) $state['refinements'][$index]['finished'] = $finished;
		}
		$manifest = $session->loadManifest();
		foreach((array) ($manifest['refinements'] ?? []) as $index => $item) {
			if((int) ($item['number'] ?? 0) === $number) $manifest['refinements'][$index]['finished'] = $finished;
		}
		$session->saveManifest($manifest);
	}

	/** Store the active refinement's final agent reply. */
	protected function storeRefinementResponse(array &$state, string $response): void {
		$number = (int) ($state['refinementNumber'] ?? 0);
		$response = $this->normalizeAgentResponse($response);
		foreach((array) ($state['refinements'] ?? []) as $index => $item) {
			if((int) ($item['number'] ?? 0) === $number) $state['refinements'][$index]['response'] = $response;
		}
	}

	/** Normalize stored agent prose and put a conservative ceiling on session state growth. */
	protected function normalizeAgentResponse(string $response): string {
		$response = trim(str_replace(["\r\n", "\r"], "\n", $response));
		if(strlen($response) <= 50000) return $response;
		return function_exists('mb_strcut') ? mb_strcut($response, 0, 50000, 'UTF-8') : substr($response, 0, 50000);
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $result */
	protected function accountAskResult(array &$state, array $result): void {
		$phase = (string) $state['phase'];
		$currentRound = (int) ($result['round'] ?? 0);
		$deltaRounds = max(0, $currentRound - (int) ($state['engineerRound'] ?? 0));
		$state['engineerRound'] = $currentRound;
		$state['round'] = (int) $state['round'] + $deltaRounds;
		$state['phaseRounds'][$phase] = (int) ($state['phaseRounds'][$phase] ?? 0) + $deltaRounds;
		$usage = array_merge($this->emptyTokenUsage(), (array) ($result['tokenUsage'] ?? []));
		$previous = array_merge($this->emptyTokenUsage(), (array) ($state['engineerTokenUsage'] ?? []));
		foreach($usage as $key => $value) {
			$delta = max(0, (int) $value - (int) $previous[$key]);
			$state['tokenUsage'][$key] = (int) ($state['tokenUsage'][$key] ?? 0) + $delta;
			$state['phaseTokenUsage'][$phase][$key] = (int) ($state['phaseTokenUsage'][$phase][$key] ?? 0) + $delta;
		}
		$state['engineerTokenUsage'] = $usage;
	}

	/** @param array|string|null $result */
	protected function accountToolResult(AgentToolsSiteBuilderSession $session, array &$state, string $name, $result): void {
		if($result === null) return;
		$failed = is_array($result) && array_key_exists('ok', $result) && empty($result['ok']);
		if(!$failed) {
			$state['consecutiveToolFailures'] = 0;
			if(($state['phase'] ?? '') === self::phaseRefine && $this->toolResultChanged($name, $result)) {
				$state['refinementChanged'] = true;
				$number = (int) ($state['refinementNumber'] ?? 0);
				foreach((array) ($state['refinements'] ?? []) as $index => $item) {
					if((int) ($item['number'] ?? 0) === $number) $state['refinements'][$index]['changed'] = true;
				}
			}
			return;
		}
		$state['consecutiveToolFailures'] = (int) ($state['consecutiveToolFailures'] ?? 0) + 1;
		$this->log($session, $state, "Tool $name failed: " . (string) ($result['error'] ?? 'Unknown tool error.'), 'tool-error', [
			'tool' => $name,
			'error' => (string) ($result['error'] ?? ''),
			'consecutiveFailures' => $state['consecutiveToolFailures'],
		]);
		if($state['consecutiveToolFailures'] < self::maxConsecutiveToolFailures) return;
		$state['status'] = 'paused';
		$state['error'] = sprintf(
			$this->_('The Site Builder paused after %d consecutive tool failures. Review the latest tool errors, then resume to let the agent try again.'),
			self::maxConsecutiveToolFailures
		);
		$this->addMessage($session, $state, $state['error'], 'warning');
	}

	/** Did a successful refinement tool result actually mutate site data or files? */
	protected function toolResultChanged(string $name, $result): bool {
		if(!is_array($result) || empty($result['ok'])) return false;
		if($name === 'write_file') return in_array((string) ($result['result'] ?? ''), ['written', 'rewritten'], true);
		if($name !== 'refine_pages') return false;
		foreach((array) ($result['pages'] ?? []) as $status) {
			if(in_array((string) $status, ['created', 'refined'], true)) return true;
		}
		return false;
	}

	/**
	 * Roll back an impossible approved plan and ask the planner to correct it.
	 */
	protected function returnBuildFailureToPlanning(AgentToolsSiteBuilderSession $session, array &$state, string $error): void {
		$tools = new AgentToolsSiteBuilderTools($this->at, $session, $this->plans());
		$this->wire($tools);
		$rollback = $tools->startOver();
		if(empty($rollback['ok'])) {
			$state['status'] = 'error';
			$state['error'] = $this->_('The approved plan failed and some Site Builder resources could not be restored or removed.');
			$this->addMessage($session, $state, $state['error'], 'error');
			return;
		}
		$this->resetManifestAfterRollback($session, (string) $state['id']);
		$state['phase'] = self::phasePlan;
		$state['status'] = 'continue';
		$state['error'] = '';
		$state['planApproved'] = false;
		$state['planErrors'] = [$error];
		$state['correctedBuildError'] = $error;
		$state['revisionRequest'] = "Correct the approved plan because its build failed with this non-retryable error:\n- $error\n\nPreserve the user's requested site and revise only what is necessary to make the plan buildable.";
		$state['engineerSessionId'] = '';
		$state['engineerRound'] = 0;
		$state['engineerTokenUsage'] = $this->emptyTokenUsage();
		$state['consecutiveToolFailures'] = 0;
		$state['pageIds'] = [];
		$this->addMessage($session, $state, $this->_('The approved plan could not be built. Its changes were rolled back and a corrected plan will be prepared.'), 'warning');
	}

	/** Archive the rolled-back manifest and prepare a clean manifest for another plan. */
	protected function resetManifestAfterRollback(AgentToolsSiteBuilderSession $session, string $id): void {
		$oldManifest = $session->loadManifest();
		$history = (array) ($oldManifest['rollbackHistory'] ?? []);
		unset($oldManifest['rollbackHistory']);
		$history[] = $oldManifest;
		$manifest = $this->newManifest($id);
		$manifest['rollbackHistory'] = $history;
		$session->saveManifest($manifest);
		$backupPath = $session->getPath() . 'backups/';
		if(is_dir($backupPath)) $this->wire()->files->rmdir($backupPath, true);
	}

	protected function resetTerminalEngineerSession(array &$state): bool {
		$id = (string) ($state['engineerSessionId'] ?? '');
		if($id === '') return false;
		$askState = $this->at->engineer()->getAskState($id);
		$status = (string) ($askState['status'] ?? '');
		if($askState && !in_array($status, ['error', 'interrupted'], true)) return false;
		$state['engineerSessionId'] = '';
		$state['engineerRound'] = 0;
		$state['engineerTokenUsage'] = $this->emptyTokenUsage();
		return true;
	}

	protected function isBudgetReached(array &$state, string $phase): bool {
		$roundLimit = (int) $state['options'][$phase . 'RoundLimit'];
		$tokenLimit = (int) $state['options'][$phase . 'TokenLimit'];
		$rounds = (int) ($state['phaseRounds'][$phase] ?? 0);
		$tokens = (int) ($state['phaseTokenUsage'][$phase]['total'] ?? 0);
		if($rounds < $roundLimit && ($tokenLimit < 1 || $tokens < $tokenLimit)) return false;
		$state['status'] = 'paused';
		$state['error'] = sprintf($this->_('The %s phase reached its configured work limit. Allow more work to continue.'), $phase);
		return true;
	}

	/** @return array<string,mixed> */
	protected function updateLocked(string $id, callable $callback): array {
		$session = $this->newSession($id);
		$state = $session->load();
		if(!$state || !$this->canAccess($state)) return $this->errorResult($id, $this->_('Site Builder session not found or inaccessible.'));
		if(!$session->lock($this->getLockMaxAge())) return $this->result($state, [], 'busy');
		try {
			$callback($session, $state);
			$state['updated'] = time();
			$session->save($state);
			return $this->result($state);
		} finally {
			$session->unlock();
		}
	}

	protected function addMessage(AgentToolsSiteBuilderSession $session, array $state, string $message, string $type = 'progress'): void {
		$this->stepMessages[] = $message;
		$this->log($session, $state, $message, $type);
	}

	protected function log(AgentToolsSiteBuilderSession $session, array $state, string $message, string $type = 'progress', array $data = []): void {
		$session->appendLog(array_merge([
			'phase' => (string) ($state['phase'] ?? ''),
			'type' => $type,
			'message' => $message,
		], $data));
	}

	/** @return array<string,mixed> */
	protected function result(array $state, array $messages = [], string $status = ''): array {
		if($status === '') $status = (string) ($state['status'] ?? 'error');
		return [
			'id' => (string) ($state['id'] ?? ''),
			'status' => $status,
			'phase' => (string) ($state['phase'] ?? ''),
			'round' => (int) ($state['round'] ?? 0),
			'messages' => array_values($messages),
			'done' => ($state['phase'] ?? '') === self::phaseDone || in_array($status, ['done', 'error', 'reset'], true),
			'error' => (string) ($state['error'] ?? ''),
			'tokenUsage' => (array) ($state['tokenUsage'] ?? []),
		];
	}

	/** @return array<string,mixed> */
	protected function errorResult(string $id, string $error): array {
		return $this->result(['id' => $id, 'status' => 'error', 'error' => $error]);
	}

	/** @return AgentToolsSiteBuilderSession */
	protected function newSession(string $id = ''): AgentToolsSiteBuilderSession {
		$session = new AgentToolsSiteBuilderSession($this->at, $id);
		$this->wire($session);
		return $session;
	}

	/** @return AgentToolsSiteBuilderPlan */
	protected function plans(): AgentToolsSiteBuilderPlan {
		if($this->plans === null) {
			$this->plans = new AgentToolsSiteBuilderPlan($this->at);
			$this->wire($this->plans);
		}
		return $this->plans;
	}

	/** @return array<string,mixed> */
	protected function normalizeOptions(array $options): array {
		$defaults = [
			'agentId' => '',
			'preset' => '',
			'siteName' => '',
			'designDirection' => 'editorial',
			'colorScheme' => 'auto',
			'brandColor' => '',
			'cssApproach' => 'agenttools-base',
			'javascript' => 'vanilla',
			'planRoundLimit' => 5,
			'buildRoundLimit' => 50,
			'verifyRoundLimit' => 15,
			'refineRoundLimit' => 15,
			'planTokenLimit' => self::defaultPlanTokenLimit,
			'buildTokenLimit' => self::defaultBuildTokenLimit,
			'refineTokenLimit' => self::defaultRefineTokenLimit,
			'verifyTokenLimit' => self::defaultVerifyTokenLimit,
		];
		$options = array_merge($defaults, $options);
		$options['agentId'] = (string) $options['agentId'];
		$options['preset'] = $this->wire()->sanitizer->pageName((string) $options['preset']);
		$options['siteName'] = $this->wire()->sanitizer->text((string) $options['siteName'], ['maxLength' => 100]);
		if($options['designDirection'] === 'warm-organic') $options['designDirection'] = 'friendly';
		if(!in_array($options['designDirection'], ['editorial', 'minimal', 'bold-modern', 'friendly', 'classic'], true)) $options['designDirection'] = 'editorial';
		if(!in_array($options['colorScheme'], ['auto', 'warm', 'cool', 'earthy', 'vibrant', 'soft', 'monochrome'], true)) $options['colorScheme'] = 'auto';
		$options['brandColor'] = strtolower(trim((string) $options['brandColor']));
		if(!preg_match('/^#[0-9a-f]{6}$/', $options['brandColor'])) $options['brandColor'] = '';
		if(!in_array($options['cssApproach'], ['agenttools-base', 'uikit', 'bootstrap'], true)) $options['cssApproach'] = 'agenttools-base';
		if(!in_array($options['javascript'], ['vanilla', 'htmx'], true)) $options['javascript'] = 'vanilla';
		foreach(['planRoundLimit', 'buildRoundLimit', 'refineRoundLimit', 'verifyRoundLimit'] as $key) $options[$key] = max(1, min(200, (int) $options[$key]));
		foreach(['planTokenLimit', 'buildTokenLimit', 'refineTokenLimit', 'verifyTokenLimit'] as $key) $options[$key] = max(1000, min(2000000, (int) $options[$key]));
		return array_intersect_key($options, $defaults);
	}

	protected function getDefaultTokenLimit(string $phase): int {
		if($phase === self::phasePlan) return self::defaultPlanTokenLimit;
		if($phase === self::phaseBuild) return self::defaultBuildTokenLimit;
		if($phase === self::phaseRefine) return self::defaultRefineTokenLimit;
		if($phase === self::phaseVerify) return self::defaultVerifyTokenLimit;
		return 0;
	}

	/** @return array<string,mixed> */
	protected function newManifest(string $id): array {
		return [
			'id' => $id,
			'created' => time(),
			'fields' => [],
			'templates' => [],
			'pages' => [],
			'files' => [],
			'modules' => [],
			'directories' => [],
			'verification' => [],
			'refinements' => [],
			'rollbackHistory' => [],
		];
	}

	/** @return array<string,int> */
	protected function emptyTokenUsage(): array {
		return ['requests' => 0, 'input' => 0, 'output' => 0, 'total' => 0, 'cacheRead' => 0, 'cacheWrite' => 0];
	}

	protected function getProvider(array $state): string {
		$agent = $this->at->getAgents()->getById((string) ($state['options']['agentId'] ?? ''));
		if(!$agent) $agent = $this->at->getPrimaryAgent();
		return $agent ? (string) $agent->provider : AgentToolsEngineer::providerAnthropic;
	}

	protected function getLockMaxAge(array $options = []): int {
		$timeout = (int) ($options['timeout'] ?? $this->at->get('engineer_request_timeout'));
		if($timeout < 30) $timeout = 120;
		return $timeout + 90;
	}

	protected function canAccess(array $state): bool {
		if($this->isCli()) return true;
		$user = $this->wire()->user;
		$owner = (int) ($state['ownerUserId'] ?? 0);
		return $owner > (int) $this->wire()->config->guestUserPageID && $user->isSuperuser() && (int) $user->id === $owner;
	}

	protected function requireStartAccess(): void {
		if($this->isCli()) return;
		if(!$this->wire()->user->isSuperuser()) throw new WireException($this->_('Site Builder requires a superuser.'));
	}

	protected function isCli(): bool {
		return PHP_SAPI === 'cli';
	}
}
