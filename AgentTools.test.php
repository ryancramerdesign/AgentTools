<?php namespace ProcessWire;

/**
 * Fast, deterministic tests for AgentTools helper behavior.
 *
 */
class WireTest_AgentTools extends WireTest {

	/**
	 * Temporary files created by tests.
	 *
	 * @var array
	 *
	 */
	protected $tmpFiles = [];

	/**
	 * Temporary directories created by tests.
	 *
	 * @var array
	 *
	 */
	protected $tmpDirs = [];

	/**
	 * Run tests.
	 *
	 */
	public function execute() {
		$at = $this->wire()->modules->get('AgentTools');
		$this->check('AgentTools module is installed', true, $at instanceof AgentTools);

		$this->testEvalValidation($at);
		$this->testCliEvalParsing($at);
		$this->testCliEvalJson($at);
		$this->testReadFileRanges($at);
		$this->testReadFileSymlinks($at);
		$this->testMigrationLint($at);
		$this->testSaveMigrationReportedPath($at);
		$this->testSchemaTemplateFields($at);
		$this->testMcpMessageShapes($at);
		$this->testOpenAIResponsesToolShapes($at);
		$this->testOpenCodeSessionHeaders($at);
		$this->testEngineerStepMode($at);
		$this->testSiteBuilder($at);
		$this->testSiteBuilderAdditiveTemplateUpdate($at);
		$this->testStatusData($at);
		$this->testScheduledTaskIntervals($at);
		$this->testTraceJsonEncoding($at);
	}

	/**
	 * Test OpenCode provider session headers without making network requests.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testOpenCodeSessionHeaders(AgentTools $at) {
		$transport = new class($at) extends AgentToolsEngineer {
			public $lastUrl = '';
			public $lastHeaders = [];

			protected function curlPost(string $url, array $payload, array $headers, int $timeout = 120): array {
				$this->lastUrl = $url;
				$this->lastHeaders = $headers;
				return [];
			}
		};
		$this->wire($transport);

		$send = function(string $provider, string $endpoint, string $sessionId = '') use($transport): AgentToolsRequest {
			$request = new AgentToolsRequest();
			$this->wire($request);
			$request->setArray([
				'provider' => $provider,
				'apiKey' => 'test-only-key',
				'model' => 'test-model',
				'endpoint' => $endpoint,
				'sessionId' => $sessionId,
				'messages' => [['role' => 'user', 'content' => 'Test']],
			]);
			$transport->sendProviderRequest($request);
			return $request;
		};
		$hasOpenCodeHeader = function(array $headers): bool {
			foreach($headers as $header) {
				if(stripos($header, 'x-opencode-session:') === 0) return true;
			}
			return false;
		};

		$send(AgentToolsEngineer::providerOpenAI, 'https://api.opencode.ai/v1/chat/completions', 'chat.round/1');
		$this->check('OpenCode Chat request sends session header', true, in_array('x-opencode-session: ses_chatround1', $transport->lastHeaders, true));
		$send(AgentToolsEngineer::providerAnthropic, 'https://gateway.opencode.ai/v1/messages', 'anthropic session');
		$this->check('OpenCode Anthropic request sends session header', true, in_array('x-opencode-session: ses_anthropicsession', $transport->lastHeaders, true));

		$nonOpenCodeEndpoints = [
			[AgentToolsEngineer::providerAnthropic, ''],
			[AgentToolsEngineer::providerAnthropic, 'https://api.anthropic.com/v1/messages'],
			[AgentToolsEngineer::providerOpenAI, 'https://api.openai.com/v1/chat/completions'],
			[AgentToolsEngineer::providerOpenAI, 'https://notopencode.ai/v1/chat/completions'],
			[AgentToolsEngineer::providerOpenAI, 'https://opencode.ai.example.com/v1/chat/completions'],
			[AgentToolsEngineer::providerOpenAI, 'https://api.openai.com/v1/chat/completions?next=opencode.ai'],
		];
		foreach($nonOpenCodeEndpoints as $n => $case) {
			$send($case[0], $case[1], 'outside');
			$this->check("Non-OpenCode endpoint $n omits session header", false, $hasOpenCodeHeader($transport->lastHeaders));
		}

		$request = $send(AgentToolsEngineer::providerOpenAI, 'https://opencode.ai/v1/chat/completions');
		$firstHeader = end($transport->lastHeaders);
		$this->check('OpenCode one-off request generates session ID', true, (bool) preg_match('/^[a-f0-9]{32}$/', (string) $request->sessionId));
		$this->check('OpenCode generated session header has expected format', true, (bool) preg_match('/^x-opencode-session: ses_[a-f0-9]{32}$/', (string) $firstHeader));
		$transport->sendProviderRequest($request);
		$this->check('OpenCode one-off request reuses generated session ID', $firstHeader, end($transport->lastHeaders));
	}

	/**
	 * Test Site Builder plan rules, deterministic tools, verification, and rollback.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testSiteBuilder(AgentTools $at) {
		$builder = $at->siteBuilder();
		$plans = $this->invokeProtected($builder, 'plans');
		$profileFile = $this->wire()->config->paths->site . 'AGENTS.md';
		$createdProfileFile = false;
		$profileNotes = is_file($profileFile) ? (string) file_get_contents($profileFile) : 'AgentTools Site Builder profile-note test.';
		if(!is_file($profileFile)) {
			$this->wire()->files->filePutContents($profileFile, $profileNotes);
			$createdProfileFile = true;
		}
		try {
			$snapshot = $plans->getSiteSnapshot();
			$fieldNames = array_column($snapshot['fields'], 'name');
			$coreFields = array_column($snapshot['coreFields'], null, 'name');
			$templateNames = array_column($snapshot['templates'], 'name');
			$pageNames = array_column($snapshot['pages'], 'name');
			$systemFieldFound = false;
			$systemFieldNames = [];
			$coreSystemFieldsMarked = true;
			foreach($this->wire()->fields as $field) {
				if($field->flags & Field::flagSystem) $systemFieldNames[] = (string) $field->name;
			}
			foreach($coreFields as $field) {
				if(empty($field['system'])) $coreSystemFieldsMarked = false;
			}
			sort($systemFieldNames, SORT_STRING);
			$coreFieldNames = array_keys($coreFields);
			sort($coreFieldNames, SORT_STRING);
			foreach($fieldNames as $name) {
				$field = $this->wire()->fields->get($name);
				if($field && $field->flags & Field::flagSystem) $systemFieldFound = true;
			}
			$systemTemplateFound = false;
			foreach($templateNames as $name) {
				$template = $this->wire()->templates->get($name);
				if($template && $template->flags & Template::flagSystem) $systemTemplateFound = true;
			}
			$this->check('Site Builder snapshot excludes system fields', false, $systemFieldFound);
			$this->check('Site Builder snapshot exposes all system fields separately', $systemFieldNames, $coreFieldNames);
			$this->check('Site Builder snapshot marks all core fields as system fields', true, $coreSystemFieldsMarked);
			$this->check('Site Builder snapshot exposes reusable title core field', true, isset($coreFields['title']));
			$this->check('Site Builder snapshot excludes system templates', false, $systemTemplateFound);
			$this->check('Site Builder snapshot excludes admin page', false, in_array((string) $this->wire()->pages->get((int) $this->wire()->config->adminRootPageID)->name, $pageNames, true));
			$this->check('Site Builder snapshot includes template file paths', true, in_array('site/templates/home.php', $snapshot['files'], true));
			$this->check('Site Builder snapshot includes profile notes', $profileNotes, $snapshot['profileNotes']);
			$templateErrors = [];
			$templateValidationArgs = [[
				'home' => [
					'name' => 'home', 'disposition' => 'reuse', 'dataOnly' => false, 'singleton' => false,
					'fields' => [], 'allowedParents' => null, 'allowedChildren' => null, 'settings' => [],
				],
			], [], [], &$templateErrors];
			$this->invokeProtected($plans, 'validateTemplates', $templateValidationArgs);
			$this->check('Site Builder allows unchanged existing template file to be omitted', [], $templateErrors);
			$templateErrors = [];
			$templateValidationArgs = [[
				'at-new-template-without-file' => [
					'name' => 'at-new-template-without-file', 'disposition' => 'create', 'dataOnly' => false, 'singleton' => false,
					'fields' => [], 'allowedParents' => null, 'allowedChildren' => null, 'settings' => [],
				],
			], [], [], &$templateErrors];
			$this->invokeProtected($plans, 'validateTemplates', $templateValidationArgs);
			$this->check('Site Builder still requires file for new front-end template', true, in_array('Front-end template at-new-template-without-file must have one role=template file in the plan or an existing template file.', $templateErrors, true));
			$planRequest = $this->invokeProtected($builder, 'getPlanRequest', [[
				'description' => 'Build a small test site.',
				'options' => ['designDirection' => 'editorial', 'cssApproach' => 'agenttools-base', 'javascript' => 'vanilla', 'preset' => ''],
				'revisionRequest' => '',
			]]);
			$this->check('Site Builder planning request includes current-site snapshot', true, strpos($planRequest, 'CURRENT SITE SNAPSHOT:') !== false);
			$this->check('Site Builder planning request includes AGENTS profile guidance', true, strpos($planRequest, $profileNotes) !== false);
			$this->check('Site Builder planning prompt explains system fields', true, strpos($plans->getSystemPrompt(), 'must never use create or update') !== false);
			$this->check('Site Builder planning prompt preserves homepage family settings', true, strpos($plans->getSystemPrompt(), 'For the home template use singleton=false and allowedParents=null') !== false);
			$this->check('Site Builder planning prompt requires planned stylesheet work', true, strpos($plans->getSystemPrompt(), 'must include a role=stylesheet file') !== false);
			$email = $this->wire()->fields->get('email');
			if($email && $email->id && ($email->flags & Field::flagSystem)) {
				$emailType = $email->type ? $email->type->className() : '';
				$systemErrors = [];
				$systemArgs = [['email' => [
					'name' => 'email', 'disposition' => 'update', 'type' => $emailType, 'settings' => [],
				]], &$systemErrors, []];
				$this->invokeProtected($plans, 'validateFields', $systemArgs);
				$this->check('Site Builder rejects system field updates', true, in_array('System field email must use disposition reuse.', $systemErrors, true));
				$systemErrors = [];
				$systemArgs = [['email' => [
					'name' => 'email', 'disposition' => 'reuse', 'type' => $emailType, 'settings' => [],
				]], &$systemErrors, []];
				$this->invokeProtected($plans, 'validateFields', $systemArgs);
				$this->check('Site Builder accepts system field reuse', [], $systemErrors);
			}
		} finally {
			if($createdProfileFile) $this->wire()->files->unlink($profileFile);
		}
		$suffix = substr(sha1((string) microtime(true) . random_int(1, PHP_INT_MAX)), 0, 8);
		$fieldName = 'at_builder_' . $suffix;
		$templateName = 'at-builder-' . $suffix;
		$pageName = $templateName;
		$filePath = 'site/templates/' . $templateName . '.php';
		$file = $this->wire()->config->paths->root . $filePath;
		$sampleName = '';
		$sessionId = '';
		$engineerSessionId = '';
		$buildPromptSessionId = '';
		$buildRunSessionId = '';
		$restartedBuildSessionId = '';
		$uninstalledFieldtypeSessionId = '';
		$plannerHookId = null;
		$buildToolHookId = null;
		$restartHookId = null;
		$plan = [
			'schemaVersion' => 1,
			'title' => 'AgentTools Site Builder test',
			'summary' => 'Disposable deterministic builder fixture.',
			'assumptions' => [],
			'features' => [],
			'design' => [],
			'fields' => [
				[
					'name' => 'title', 'disposition' => 'reuse', 'type' => 'FieldtypePageTitle',
					'label' => 'Title', 'summary' => 'Existing title.', 'settings' => [],
				],
				[
					'name' => $fieldName, 'disposition' => 'create', 'type' => 'FieldtypeText',
					'label' => 'Builder test', 'summary' => 'Disposable test field.', 'settings' => [],
				],
			],
			'templates' => [[
				'name' => $templateName,
				'disposition' => 'create',
				'label' => 'Builder test',
				'summary' => 'Disposable test template.',
				'dataOnly' => false,
				'singleton' => false,
				'fields' => [
					['name' => 'title', 'required' => true, 'columnWidth' => 100, 'settings' => []],
					['name' => $fieldName, 'required' => false, 'columnWidth' => 100, 'settings' => []],
				],
				'allowedParents' => ['home'],
				'allowedChildren' => [],
				'settings' => [],
			]],
			'pages' => [
				[
					'key' => 'home', 'disposition' => 'reuse', 'parent' => null,
					'name' => 'home', 'template' => 'home', 'status' => 'published', 'values' => [],
				],
				[
					'key' => 'fixture',
					'disposition' => 'create',
					'parent' => 'home',
					'name' => $pageName,
					'template' => $templateName,
					'status' => 'unpublished',
					'values' => ['title' => 'Builder fixture', $fieldName => 'Builder value'],
				],
			],
			'files' => [[
				'path' => $filePath,
				'role' => 'template',
				'disposition' => 'create',
				'template' => $templateName,
				'summary' => 'Disposable template file.',
			]],
			'modules' => [],
			'verification' => [
				'routes' => [['page' => 'fixture', 'expectedStatus' => 200]],
				'adminPages' => [['template' => $templateName, 'page' => 'fixture']],
				'checks' => [],
			],
			'openQuestions' => [],
		];

		try {
			$normalizationFixture = [
				'fields' => [
					['name' => 'title'],
					[
						'name' => 'project_year', 'type' => 'FieldtypeInteger',
						'settings' => ['inputfieldClass' => 'InputfieldInteger'],
					],
				],
				'templates' => [[
					'name' => 'basic-page',
					'disposition' => 'reuse',
					'fields' => [['name' => 'title'], ['name' => 'wire_test_fixture']],
				]],
				'pages' => [],
				'files' => [
					['path' => 'site/templates/_main.php', 'role' => 'markup-region'],
					['path' => 'site/classes/BasicPagePage.php', 'role' => 'pageClass'],
				],
			];
			$normalizationWarnings = [];
			$normalizedFixture = $plans->normalize($normalizationFixture, $normalizationWarnings);
			$this->check('Site Builder normalization removes unrelated reused-template fields', ['title'], array_column($normalizedFixture['templates'][0]['fields'], 'name'));
			$this->check('Site Builder normalization assigns known ProcessWire file roles', 'main', $normalizedFixture['files'][0]['role']);
			$this->check('Site Builder normalization derives Page-class template', 'basic-page', $normalizedFixture['files'][1]['template'] ?? '');
			$this->check('Site Builder reports derived Page-class template', true, in_array('Set template basic-page on file site/classes/BasicPagePage.php.', $normalizationWarnings, true));
			$this->check('Site Builder normalization drops undocumented field settings', false, isset($normalizedFixture['fields'][1]['settings']['inputfieldClass']));
			$this->check('Site Builder normalization reports dropped field settings', true, in_array('Dropped unknown setting inputfieldClass from field project_year (FieldtypeInteger).', $normalizationWarnings, true));
			$repairWarnings = [];
			$repairFixture = $plans->normalize(['fields' => [
				['name' => 'author', 'type' => 'FieldtypeText', 'settings' => ['maxLength' => 120]],
				['name' => 'published_date', 'type' => 'FieldtypeDatetime', 'settings' => ['outputFormat' => 'Y-m-d']],
				['name' => 'related_page', 'type' => 'FieldtypePage', 'settings' => ['inputfieldClass' => 'InputfieldAsmSelect']],
			]], $repairWarnings);
			$this->check('Site Builder repairs case-only field setting names', 120, $repairFixture['fields'][0]['settings']['maxlength'] ?? 0);
			$this->check('Site Builder repairs known Fieldtype setting aliases', 'Y-m-d', $repairFixture['fields'][1]['settings']['dateOutputFormat'] ?? '');
			$this->check('Site Builder repairs Page inputfieldClass alias', 'InputfieldAsmSelect', $repairFixture['fields'][2]['settings']['inputfield'] ?? '');
			$this->check('Site Builder reports repaired case-only setting names', true, in_array('Renamed setting maxLength to maxlength on field author (FieldtypeText).', $repairWarnings, true));
			$this->check('Site Builder reports repaired Fieldtype setting aliases', true, in_array('Renamed setting outputFormat to dateOutputFormat on field published_date (FieldtypeDatetime).', $repairWarnings, true));
			$datetimeWarnings = [];
			$datetimeFixture = $plans->normalize(['fields' => [
				['name' => 'new_date', 'disposition' => 'create', 'type' => 'FieldtypeDatetime', 'settings' => []],
				['name' => 'new_datetime', 'disposition' => 'create', 'type' => 'FieldtypeDatetime', 'settings' => ['timeInputFormat' => 'H:i']],
				['name' => 'picker_date', 'disposition' => 'create', 'type' => 'FieldtypeDatetime', 'settings' => ['datepicker' => 3, 'dateInputFormat' => 'Y-m-d']],
				['name' => 'reused_date', 'disposition' => 'reuse', 'type' => 'FieldtypeDatetime', 'settings' => []],
				['name' => 'updated_date', 'disposition' => 'update', 'type' => 'FieldtypeDatetime', 'settings' => []],
			]], $datetimeWarnings);
			$this->check('Site Builder defaults untouched created Datetime fields to HTML date', ['inputType' => 'html', 'htmlType' => 'date'], $datetimeFixture['fields'][0]['settings']);
			$this->check('Site Builder defaults lone time formats to HTML datetime', ['timeInputFormat' => 'H:i', 'inputType' => 'html', 'htmlType' => 'datetime'], $datetimeFixture['fields'][1]['settings']);
			$this->check('Site Builder preserves explicit Datetime picker settings', ['datepicker' => 3, 'dateInputFormat' => 'Y-m-d'], $datetimeFixture['fields'][2]['settings']);
			$this->check('Site Builder does not default reused Datetime fields', [], $datetimeFixture['fields'][3]['settings']);
			$this->check('Site Builder does not default updated Datetime fields', [], $datetimeFixture['fields'][4]['settings']);
			$this->check('Site Builder reports created Datetime input defaults', true, in_array('Added HTML date input defaults to created Datetime field new_date.', $datetimeWarnings, true));
			$builderDefaults = $this->invokeProtected($builder, 'normalizeOptions', [[]]);
			$this->check('Site Builder default planning token limit is generous', AgentToolsSiteBuilder::defaultPlanTokenLimit, $builderDefaults['planTokenLimit']);
			$this->check('Site Builder default build token limit is generous', AgentToolsSiteBuilder::defaultBuildTokenLimit, $builderDefaults['buildTokenLimit']);
			$this->check('Site Builder refinement has a separate token limit', AgentToolsSiteBuilder::defaultRefineTokenLimit, $builderDefaults['refineTokenLimit']);
			$this->check('Site Builder default verification token limit is generous', AgentToolsSiteBuilder::defaultVerifyTokenLimit, $builderDefaults['verifyTokenLimit']);
			$planningPrompt = $plans->getSystemPrompt();
			$this->check('Site Builder planner warns about native field names', true, strpos($planningPrompt, 'New field names must not use native Page properties') !== false);
			$this->check('Site Builder planner uses ProcessWire Datetime HTML types', true, strpos($planningPrompt, 'date, time, or datetime (not datetime-local)') !== false);
			$this->check('Site Builder planner requires values or briefs for useful page fields', true, strpos($planningPrompt, 'must have either a value or a contentBrief entry') !== false);
			$this->check('Site Builder planner requires template on Page-class files', true, strpos($planningPrompt, 'File roles template and pageClass both require template') !== false);
			$this->check('Engineer retries provider rate limits', true, $this->invokeProtected($at->engineer(), 'isRetryableHttpCode', [429]));
			$this->check('Engineer retries provider server errors', true, $this->invokeProtected($at->engineer(), 'isRetryableHttpCode', [500]));
			$this->check('Engineer does not retry ordinary client errors', false, $this->invokeProtected($at->engineer(), 'isRetryableHttpCode', [400]));
			$budgetState = [
				'options' => ['planRoundLimit' => 1, 'planTokenLimit' => 1000],
				'phaseRounds' => ['plan' => 1],
				'phaseTokenUsage' => ['plan' => ['total' => 0]],
			];
			$budgetArgs = [&$budgetState, AgentToolsSiteBuilder::phasePlan];
			$this->check('Site Builder enforces planning budget', true, $this->invokeProtected($builder, 'isBudgetReached', $budgetArgs));
			$this->check('Site Builder planning budget pauses the session', 'paused', $budgetState['status']);
			$this->check('Site Builder accepts deterministic fixture plan', [], $builder->validatePlan($plan));
			$missingFileTemplatePlan = $plan;
			$missingFileTemplatePlan['files'][] = [
				'path' => 'site/classes/UnmatchedPage.php', 'role' => 'pageClass',
				'disposition' => 'create', 'summary' => 'Missing template test.',
			];
			$missingFileTemplateErrors = $builder->validatePlan($missingFileTemplatePlan);
			$this->check('Site Builder distinguishes missing Page-class template', true, strpos(implode("\n", $missingFileTemplateErrors), 'File site/classes/UnmatchedPage.php role pageClass requires template.') !== false);
			$unknownFileTemplatePlan = $plan;
			$unknownFileTemplatePlan['files'][] = [
				'path' => 'site/classes/UnknownPage.php', 'role' => 'pageClass',
				'disposition' => 'create', 'template' => 'not-a-template', 'summary' => 'Unknown template test.',
			];
			$unknownFileTemplateErrors = $builder->validatePlan($unknownFileTemplatePlan);
			$this->check('Site Builder distinguishes unknown Page-class template', true, in_array('File site/classes/UnknownPage.php role pageClass references unknown template not-a-template.', $unknownFileTemplateErrors, true));
			$reservedPlan = $plan;
			$reservedPlan['fields'][] = [
				'name' => 'published', 'disposition' => 'create', 'type' => 'FieldtypeDatetime',
				'label' => 'Published', 'summary' => 'Reserved field test.', 'settings' => [],
			];
			$reservedErrors = $builder->validatePlan($reservedPlan);
			$this->check('Site Builder rejects native field names during planning', true, in_array('Field name published is reserved by ProcessWire. Choose a different name (e.g. published_date).', $reservedErrors, true));
			$invalidDatetimePlan = $plan;
			$invalidDatetimePlan['fields'][] = [
				'name' => $fieldName . '_date', 'disposition' => 'create', 'type' => 'FieldtypeDatetime',
				'label' => 'Date', 'summary' => 'Invalid Datetime HTML type test.',
				'settings' => ['inputType' => 'html', 'htmlType' => 'datetime-local'],
			];
			$invalidDatetimeErrors = $builder->validatePlan($invalidDatetimePlan);
			$this->check('Site Builder rejects unsupported Datetime HTML types', true, in_array("Datetime field {$fieldName}_date htmlType must be date, time, or datetime.", $invalidDatetimeErrors, true));

			$planFailureSession = new AgentToolsSiteBuilderSession($at);
			$this->wire($planFailureSession);
			try {
				$this->check('Site Builder plan-failure session obtains lock', true, $planFailureSession->lock(360));
				$planFailureId = $planFailureSession->getId();
				$planFailureState = [
					'id' => $planFailureId,
					'phase' => AgentToolsSiteBuilder::phaseBuild,
					'status' => 'running',
					'plan' => $reservedPlan,
					'planApproved' => true,
					'planErrors' => [],
					'revisionRequest' => '',
					'engineerSessionId' => 'test-build-session',
					'engineerRound' => 1,
					'engineerTokenUsage' => [],
					'consecutiveToolFailures' => 0,
					'pageIds' => [],
				];
				$planFailureSession->save($planFailureState);
				$planFailureManifest = $this->invokeProtected($builder, 'newManifest', [$planFailureId]);
				$planFailureSession->saveManifest($planFailureManifest);
				$planFailureTools = new AgentToolsSiteBuilderTools($at, $planFailureSession, $plans);
				$this->wire($planFailureTools);
				$planFailureResult = $planFailureTools->execute('create_fields', ['names' => ['published']]);
				$this->check('Site Builder marks non-retryable field build errors as plan errors', true, !empty($planFailureResult['planError']));
				$planFailureArgs = [$planFailureSession, &$planFailureState, (string) $planFailureResult['error']];
				$this->invokeProtected($builder, 'returnBuildFailureToPlanning', $planFailureArgs);
				$this->check('Site Builder returns non-retryable build failures to planning', AgentToolsSiteBuilder::phasePlan, $planFailureState['phase']);
				$this->check('Site Builder unapproves a failed build plan', false, $planFailureState['planApproved']);
				$this->check('Site Builder carries build failure into plan revision', true, strpos($planFailureState['revisionRequest'], (string) $planFailureResult['error']) !== false);
				$this->check('Site Builder archives rolled-back failed build manifest', 1, count($planFailureSession->loadManifest()['rollbackHistory']));
			} finally {
				$planFailureSession->unlock();
				$this->wire()->files->rmdir($planFailureSession->getPath(), true);
			}
			$uninstalledType = '';
			$uninstalledFieldtype = null;
			foreach(array_keys($this->wire()->modules->getInstallable()) as $moduleName) {
				if(strpos($moduleName, 'Fieldtype') !== 0 || $this->wire()->modules->isInstalled($moduleName)) continue;
				$candidate = $this->wire()->modules->getModule($moduleName, ['noInstall' => true, 'noThrow' => true]);
				if($candidate instanceof Fieldtype) {
					$uninstalledType = $moduleName;
					$uninstalledFieldtype = $candidate;
					break;
				}
			}
			if($uninstalledType !== '' && $uninstalledFieldtype instanceof Fieldtype) {
				$uninstalledFieldName = $fieldName . '_uninstalled';
				$uninstalledPlan = $plan;
				$uninstalledPlan['fields'][] = [
					'name' => $uninstalledFieldName,
					'disposition' => 'create',
					'type' => $uninstalledType,
					'label' => 'Uninstalled Fieldtype test',
					'summary' => 'Verify validation never installs Fieldtypes.',
					'settings' => [],
				];
				$unlistedErrors = $builder->validatePlan($uninstalledPlan);
				$expectedError = "Field $uninstalledFieldName uses uninstalled type $uninstalledType; add it to modules[] with disposition create.";
				$this->check('Site Builder requires uninstalled Fieldtype in modules plan', true, in_array($expectedError, $unlistedErrors, true));
				$this->check('Site Builder validation does not install unlisted Fieldtype', false, $this->wire()->modules->isInstalled($uninstalledType));

				$moduleFile = (new \ReflectionClass($uninstalledFieldtype))->getFileName();
				$source = $moduleFile && strpos($moduleFile, $this->wire()->config->paths->wire) === 0 ? 'core' : 'site';
				$uninstalledPlan['modules'][] = ['name' => $uninstalledType, 'source' => $source, 'disposition' => 'create'];
				$this->check('Site Builder accepts approved uninstalled Fieldtype', [], $builder->validatePlan($uninstalledPlan));
				$this->check('Site Builder validation does not install approved Fieldtype', false, $this->wire()->modules->isInstalled($uninstalledType));

				$uninstalledStore = new AgentToolsSiteBuilderSession($at);
				$this->wire($uninstalledStore);
				$uninstalledFieldtypeSessionId = $uninstalledStore->getId();
				if(!$uninstalledStore->lock(360)) throw new WireException('Unable to lock uninstalled Fieldtype test session.');
				$uninstalledStore->save(['plan' => $uninstalledPlan]);
				$uninstalledTools = new AgentToolsSiteBuilderTools($at, $uninstalledStore, $plans);
				$this->wire($uninstalledTools);
				$uninstalledResult = $uninstalledTools->execute('create_fields', ['names' => [$uninstalledFieldName]]);
				$uninstalledStore->unlock();
				$this->check('Site Builder field tool requires module installation first', false, $uninstalledResult['ok']);
				$this->check('Site Builder field tool identifies install_modules prerequisite', true, strpos((string) $uninstalledResult['error'], 'Run install_modules first') !== false);
				$this->check('Site Builder field tool does not install Fieldtype', false, $this->wire()->modules->isInstalled($uninstalledType));
			}
			$planWithoutVerification = $plan;
			unset($planWithoutVerification['verification']);
			$normalizedPlan = $plans->normalize($planWithoutVerification);
			$routeKeys = array_column($normalizedPlan['verification']['routes'], 'page');
			$adminTemplates = array_column($normalizedPlan['verification']['adminPages'], 'template');
			$this->check('Site Builder accepts plan with derived verification', [], $builder->validatePlan($planWithoutVerification));
			$this->check('Site Builder derives routes for every front-end page', ['home', 'fixture'], $routeKeys);
			$this->check('Site Builder derives representative admin template checks', [$templateName], $adminTemplates);
			$derivedOnly = $plans->normalize([
				'templates' => [
					['name' => 'front', 'dataOnly' => false],
					['name' => 'data', 'dataOnly' => true],
				],
				'pages' => [
					['key' => 'front-page', 'template' => 'front'],
					['key' => 'data-page', 'template' => 'data'],
				],
			]);
			$this->check('Site Builder omits data-only pages from derived routes', ['front-page'], array_column($derivedOnly['verification']['routes'], 'page'));
			$this->check('Site Builder derives admin checks for data-only templates', ['front', 'data'], array_column($derivedOnly['verification']['adminPages'], 'template'));

			$failureSession = new AgentToolsSiteBuilderSession($at);
			$this->wire($failureSession);
			try {
				$this->check('Site Builder failure-log session obtains lock', true, $failureSession->lock(360));
				$failureState = ['phase' => AgentToolsSiteBuilder::phasePlan, 'planAttempts' => 0, 'planErrors' => []];
				$failureArgs = [$failureSession, &$failureState, ['schemaVersion' => 1], ['Expected plan error'], '{"schemaVersion":1}'];
				$this->invokeProtected($builder, 'recordPlanFailure', $failureArgs);
				$attemptFile = $failureSession->getPath() . 'plan-attempt-1.json';
				$attemptData = json_decode((string) file_get_contents($attemptFile), true);
				$failureLog = $failureSession->loadLog();
				$failureEntry = end($failureLog);
				$this->check('Site Builder records failed plan attempt number', 1, $failureState['planAttempts']);
				$this->check('Site Builder preserves invalid plan attempt', ['schemaVersion' => 1], $attemptData['plan']);
				$this->check('Site Builder logs failed plan validation errors', ['Expected plan error'], $failureEntry['errors']);
				$this->check('Site Builder failure log references preserved plan', 'plan-attempt-1.json', $failureEntry['planFile']);
			} finally {
				$failureSession->unlock();
				$this->wire()->files->rmdir($failureSession->getPath(), true);
			}

			$badPlan = $plan;
			$badPlan['templates'][0]['singleton'] = true;
			$badPlan['templates'][0]['allowedParents'] = [];
			$badErrors = $builder->validatePlan($badPlan);
			$this->check('Site Builder rejects singleton with no allowed parents', true, in_array("Template $templateName cannot combine singleton=true with allowedParents=[].", $badErrors, true));
			$badPlan = $plan;
			$badPlan['templates'][0]['fields'][1]['settings']['notAFieldSetting'] = true;
			$contextWarnings = [];
			$normalizedContextPlan = $plans->normalize($badPlan, $contextWarnings);
			$this->check('Site Builder normalization drops unknown field context setting', false, isset($normalizedContextPlan['templates'][0]['fields'][1]['settings']['notAFieldSetting']));
			$this->check('Site Builder normalization reports dropped field context setting', true, in_array("Dropped unknown context setting notAFieldSetting from template field $fieldName (FieldtypeText).", $contextWarnings, true));
			$titleProperties = $this->invokeProtected($plans, 'getFieldProperties', [$this->wire()->modules->get('FieldtypePageTitle')]);
			$textareaProperties = $this->invokeProtected($plans, 'getFieldProperties', [$this->wire()->modules->get('FieldtypeTextarea')]);
			$this->check('Site Builder accepts inherited PageTitle textformatters setting', true, isset($titleProperties['textformatters']));
			$this->check('Site Builder accepts inherited Textarea textformatters setting', true, isset($textareaProperties['textformatters']));
			$textformatterPlan = $plan;
			$textformatterPlan['fields'][] = [
				'name' => $fieldName . '_title', 'disposition' => 'create', 'type' => 'FieldtypePageTitle',
				'label' => 'Secondary title', 'summary' => 'Planned title field.',
				'settings' => ['textformatters' => []],
			];
			$textformatterPlan['fields'][] = [
				'name' => $fieldName . '_textarea', 'disposition' => 'create', 'type' => 'FieldtypeTextarea',
				'label' => 'Body', 'summary' => 'Planned textarea.',
				'settings' => ['textformatters' => []],
			];
			$textformatterErrors = $builder->validatePlan($textformatterPlan);
			$this->check('Site Builder validator allows PageTitle and Textarea textformatters', false, strpos(implode("\n", $textformatterErrors), 'Unknown setting textformatters') !== false);
			$badPlan = $plan;
			$badPlan['pages'][1]['parent'] = null;
			$badErrors = $builder->validatePlan($badPlan);
			$this->check('Site Builder rejects new root page', true, in_array('Root page fixture cannot use disposition create.', $badErrors, true));
			$badPlan = $plan;
			$badPlan['templates'][0]['removeFields'] = [$fieldName];
			$badErrors = $builder->validatePlan($badPlan);
			$this->check('Site Builder rejects template field removal', true, in_array("Template $templateName cannot remove fields in Site Builder version 1.", $badErrors, true));
			$this->check('Site Builder allows exact site ready hook file', true, $this->invokeProtected($plans, 'isAllowedFilePath', ['site/ready.php']));
			$this->check('Site Builder allows exact site init hook file', true, $this->invokeProtected($plans, 'isAllowedFilePath', ['site/init.php']));
			$this->check('Site Builder still denies site config file', false, $this->invokeProtected($plans, 'isAllowedFilePath', ['site/config.php']));

			$engineer = $at->engineer();
			$plannerHookId = $engineer->addHookBefore('sendProviderRequest', function(HookEvent $event) use($plan) {
				$request = $event->arguments(0);
				$json = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				if($request instanceof AgentToolsRequest && $request->provider === AgentToolsEngineer::providerAnthropic) {
					$response = [
						'stop_reason' => 'end_turn',
						'content' => [[ 'type' => 'text', 'text' => $json ]],
						'usage' => [ 'input_tokens' => 10, 'output_tokens' => 20 ],
					];
				} else if($request instanceof AgentToolsRequest && str_ends_with((string) parse_url($request->endpoint, PHP_URL_PATH), '/responses')) {
					$response = [
						'output' => [[ 'type' => 'message', 'content' => [[ 'type' => 'output_text', 'text' => $json ]] ]],
						'usage' => [ 'input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30 ],
					];
				} else {
					$response = [
						'choices' => [[ 'message' => [ 'role' => 'assistant', 'content' => $json ] ]],
						'usage' => [ 'prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30 ],
					];
				}
				$event->return = $response;
				$event->replace = true;
			});

			$started = $builder->start('Create a disposable Site Builder test fixture.');
			$sessionId = (string) $started['id'];
			$this->check('Site Builder starts in plan phase without provider call', 'plan', $started['phase']);
			$this->check('Site Builder start reports zero rounds', 0, $started['round']);
			$planningState = $builder->getState($sessionId);
			$planningOptions = $this->invokeProtected($builder, 'getAskOptions', [$planningState, AgentToolsSiteBuilder::phasePlan]);
			if($planningOptions['provider'] === AgentToolsEngineer::providerAnthropic) {
				$this->check('Site Builder raises Anthropic planning output limit', AgentToolsSiteBuilder::planMaxTokens, $planningOptions['anthropic']['max_tokens'] ?? 0);
				if(strpos(strtolower((string) $planningOptions['model']), 'claude-sonnet-5') === 0) {
					$this->check('Site Builder disables Sonnet 5 thinking for structured plans', 'disabled', $planningOptions['anthropic']['thinking']['type'] ?? '');
				}
			}
			$planned = $builder->step($sessionId);
			$state = $builder->getState($sessionId);
			$engineerSessionId = (string) ($state['engineerSessionId'] ?? '');
			$engineer->removeHook($plannerHookId);
			$plannerHookId = null;
			$this->check('Site Builder planning performs one provider round', 1, $planned['round']);
			$this->check('Site Builder validated plan awaits approval', 'awaiting-approval', $planned['status']);
			$this->check('Site Builder stores provider plan', $templateName, $builder->getPlan($sessionId)['templates'][0]['name']);
			$approved = $builder->approvePlan($sessionId);
			$this->check('Site Builder approval enters build phase', AgentToolsSiteBuilder::phaseBuild, $approved['phase']);
			$buildState = $builder->getState($sessionId);
			$buildOptions = $this->invokeProtected($builder, 'getAskOptions', [$buildState, AgentToolsSiteBuilder::phaseBuild]);
			$this->check('Site Builder makes only eval_php read-only during build', true, $buildOptions['readOnlyEval']);
			$this->check('Site Builder does not enable full preview mode during build', false, !empty($buildOptions['dryRun']));
			$buildAgent = $at->getAgents()->getById((string) $buildState['options']['agentId']);
			$this->check('Site Builder resolves configured agent provider', (string) $buildAgent->provider, $buildOptions['provider']);
			$this->check('Site Builder resolves configured agent model', (string) $buildAgent->model, $buildOptions['model']);
			$this->check('Site Builder resolves configured agent endpoint', (string) $buildAgent->endpointUrl, $buildOptions['endpoint']);
			$this->check('Site Builder enables stable initial-message caching for Anthropic', $buildOptions['provider'] === AgentToolsEngineer::providerAnthropic, !empty($buildOptions['cacheInitialMessage']));
			$buildSystemPrompt = $this->invokeProtected($builder, 'getBuildSystemPrompt', [$buildState]);
			$this->check('Site Builder tells agents not to repeat complete resources', true, strpos($buildSystemPrompt, 'A manifest item with status complete is already done') !== false);
			$this->check('Site Builder tells agents not to repeat profile region content', true, strpos($buildSystemPrompt, 'do not repeat the page title, summary, or other region content') !== false);
			$completedFileInstructions = $this->invokeProtected($builder, 'getCompletedFileInstructions', [[
				'files' => [['key' => 'site/templates/home.php', 'status' => 'complete', 'bytes' => 321]],
			]]);
			$this->check('Site Builder describes completed files explicitly', true, strpos($completedFileInstructions, 'site/templates/home.php: written, 321 bytes; do not rewrite') !== false);
			$cache = ['type' => 'ephemeral', 'ttl' => '1h'];
			$cachedMessages = $this->invokeProtected($engineer, 'cacheAnthropicInitialMessage', [[
				['role' => 'user', 'content' => 'Stable Site Builder plan and manifest.'],
				['role' => 'assistant', 'content' => 'Working.'],
			], $cache]);
			$this->check('Engineer can cache the stable initial Anthropic message', $cache, $cachedMessages[0]['content'][0]['cache_control'] ?? []);
			$buildPrompt = $engineer->startAskSession('Inspect the approved Site Builder build request.', $buildOptions);
			$buildPromptSessionId = (string) $buildPrompt['sessionId'];
			$buildAskState = $engineer->getAskState($buildPromptSessionId);
			$this->check('Site Builder final build prompt omits preview-only instructions', false, strpos((string) $buildAskState['systemPrompt'], 'Preview-only mode is enabled') !== false);

			$buildToolCalls = 0;
			$firstFailedRound = [];
			$buildToolHookId = $engineer->addHookBefore('sendProviderRequest', function(HookEvent $event) use(&$buildToolCalls) {
				$request = $event->arguments(0);
				$buildToolCalls++;
				$id = 'site_builder_bad_' . $buildToolCalls;
				$input = ['pages' => [['key' => 'fixture', 'content' => ['title' => 'Generated title']]]];
				if($request instanceof AgentToolsRequest && $request->provider === AgentToolsEngineer::providerAnthropic) {
					$response = [
						'stop_reason' => 'tool_use',
						'content' => [[ 'type' => 'tool_use', 'id' => $id, 'name' => 'create_pages', 'input' => $input ]],
						'usage' => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
					];
				} else if($request instanceof AgentToolsRequest && str_ends_with((string) parse_url($request->endpoint, PHP_URL_PATH), '/responses')) {
					$response = [
						'output' => [[ 'type' => 'function_call', 'id' => $id, 'call_id' => $id, 'name' => 'create_pages', 'arguments' => json_encode($input) ]],
						'usage' => [ 'input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15 ],
					];
				} else {
					$response = [
						'choices' => [[
							'finish_reason' => 'tool_calls',
							'message' => [ 'role' => 'assistant', 'content' => '', 'tool_calls' => [[
								'id' => $id,
								'type' => 'function',
								'function' => [ 'name' => 'create_pages', 'arguments' => json_encode($input) ],
							]] ],
						]],
						'usage' => [ 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ],
					];
				}
				$event->return = $response;
				$event->replace = true;
			});
			for($n = 1; $n <= AgentToolsSiteBuilder::maxConsecutiveToolFailures; $n++) {
				$failedRound = $builder->step($sessionId);
				if($n === 1) $firstFailedRound = $failedRound;
			}
			$engineer->removeHook($buildToolHookId);
			$buildToolHookId = null;
			$failedState = $builder->getState($sessionId);
			$buildRunSessionId = (string) ($failedState['engineerSessionId'] ?? '');
			$this->check('Site Builder correctable tool error keeps round resumable', 'continue', $firstFailedRound['status']);
			$this->check('Site Builder failed page call makes no page changes', 0, (int) $this->wire()->pages->get("parent=1, name=$pageName, include=all")->id);
			$this->check('Site Builder pauses after consecutive tool failures', 'paused', $failedState['status']);
			$this->check('Site Builder records consecutive tool failure limit', AgentToolsSiteBuilder::maxConsecutiveToolFailures, $failedState['consecutiveToolFailures']);
			$resumed = $builder->resume($sessionId);
			$this->check('Site Builder resume resets consecutive tool failures', 0, $builder->getState($sessionId)['consecutiveToolFailures']);

			$terminalStore = new AgentToolsEngineerSession($at, $buildRunSessionId);
			$this->wire($terminalStore);
			$this->check('Site Builder terminal Engineer test obtains lock', true, $terminalStore->lock(360));
			$terminalState = $terminalStore->load();
			$terminalState['status'] = 'error';
			$terminalState['error'] = 'Simulated terminal build error.';
			$terminalStore->save($terminalState);
			$terminalStore->unlock();
			$builder->resume($sessionId);
			$this->check('Site Builder resume clears terminal Engineer session', '', $builder->getState($sessionId)['engineerSessionId']);
			$restartCalls = 0;
			$restartHookId = $engineer->addHookBefore('sendProviderRequest', function(HookEvent $event) use(&$restartCalls, &$restartedBuildSessionId) {
				$request = $event->arguments(0);
				$restartCalls++;
				if($request instanceof AgentToolsRequest) $restartedBuildSessionId = (string) $request->sessionId;
				if($request instanceof AgentToolsRequest && $request->provider === AgentToolsEngineer::providerAnthropic) {
					$response = ['stop_reason' => 'end_turn', 'content' => [[ 'type' => 'text', 'text' => 'Ready to continue.' ]]];
				} else if($request instanceof AgentToolsRequest && str_ends_with((string) parse_url($request->endpoint, PHP_URL_PATH), '/responses')) {
					$response = ['output' => [[ 'type' => 'message', 'content' => [[ 'type' => 'output_text', 'text' => 'Ready to continue.' ]] ]]];
				} else {
					$response = ['choices' => [[ 'message' => [ 'role' => 'assistant', 'content' => 'Ready to continue.' ] ]]];
				}
				$event->return = $response;
				$event->replace = true;
			});
			$restarted = $builder->step($sessionId);
			$engineer->removeHook($restartHookId);
			$restartHookId = null;
			$this->check('Site Builder step after terminal error calls provider with new session', 1, $restartCalls);
			$this->check('Site Builder step after terminal error remains resumable', 'continue', $restarted['status']);

			$buildStore = new AgentToolsSiteBuilderSession($at, $sessionId);
			$this->wire($buildStore);
			$this->check('Site Builder session test obtains lock', true, $buildStore->lock(360));
			$buildLockFile = $this->invokeProtected($buildStore, 'getLockFile');
			touch($buildLockFile, time() - 600);
			$buildStore->saveManifest($buildStore->loadManifest());
			clearstatcache(true, $buildLockFile);
			$this->check('Site Builder manifest save refreshes lock', true, filemtime($buildLockFile) >= time() - 2);
			touch($buildLockFile, time() - 600);
			$buildStore->appendLog(['type' => 'test', 'message' => 'Lock refresh test.']);
			clearstatcache(true, $buildLockFile);
			$this->check('Site Builder log append refreshes lock', true, filemtime($buildLockFile) >= time() - 2);
			$buildStore->unlock();

			$badFieldsResult = $builder->executeBuildTool($sessionId, 'create_fields', ['names' => [$fieldName, 'not-in-approved-plan']]);
			$this->check('Site Builder rejects invalid field batch without throwing', false, $badFieldsResult['ok']);
			$uncreatedField = $this->wire()->fields->get($fieldName);
			$this->check('Site Builder validates full field call before mutation', 0, $uncreatedField ? (int) $uncreatedField->id : 0);
			$fieldsResult = $builder->executeBuildTool($sessionId, 'create_fields', ['names' => [$fieldName]]);
			$this->check('Site Builder creates approved field', 'created', $fieldsResult['fields'][$fieldName]);
			$templatesResult = $builder->executeBuildTool($sessionId, 'create_templates', ['names' => [$templateName]]);
			$this->check('Site Builder creates approved template', 'created', $templatesResult['templates'][$templateName]);
			$pageBatchResult = $builder->executeBuildTool($sessionId, 'create_pages', ['pages' => [
				['key' => 'fixture', 'content' => ['title' => 'Generated title']],
				['key' => 'not-in-approved-plan'],
			]]);
			$this->check('Site Builder reports partial page batch errors', false, $pageBatchResult['ok']);
			$this->check('Site Builder processes valid pages in a partial batch', 'created', $pageBatchResult['pages']['fixture']);
			$this->check('Site Builder ignores generated content already in plan values', 'set from approved plan values', $pageBatchResult['ignored']['fixture']['title'] ?? '');
			$this->check('Site Builder reports only the invalid page in a partial batch', true, isset($pageBatchResult['errors']['not-in-approved-plan']));
			$createdFixture = $this->wire()->pages->get("parent=1, name=$pageName, include=all");
			$this->check('Site Builder approved page value wins over ignored content', 'Builder fixture', (string) $createdFixture->title);
			$badPagesResult = $builder->executeBuildTool($sessionId, 'create_pages', ['pages' => [[
				'key' => 'fixture', 'content' => ['published_date' => '2026-09-17'],
			]]]);
			$this->check('Site Builder returns genuine unplanned page content as an error', false, $badPagesResult['ok']);
			$this->check('Site Builder identifies content absent from values and contentBrief', true, strpos((string) ($badPagesResult['errors']['fixture'] ?? ''), 'neither page fixture values nor contentBrief') !== false);
			$pagesResult = $builder->executeBuildTool($sessionId, 'create_pages', ['pages' => [['key' => 'fixture']]]);
			$this->check('Site Builder recognizes the already completed approved page', 'already complete', $pagesResult['pages']['fixture']);
			$fileResult = $builder->executeBuildTool($sessionId, 'write_file', [
				'path' => $filePath,
				'content' => '<?php namespace ProcessWire; ?><h1><?= $page->title ?></h1>',
			]);
			$this->check('Site Builder writes approved PHP file', true, $fileResult['ok']);
			$this->check('Site Builder PHP lint succeeds', 'ok', $fileResult['lint']);

			$front = $builder->executeBuildTool($sessionId, 'fetch_page', ['page' => 'fixture', 'kind' => 'front']);
			$this->check('Site Builder verifies front-end page render' . (!empty($front['error']) ? ': ' . $front['error'] : ''), true, $front['ok']);
			$admin = $builder->executeBuildTool($sessionId, 'fetch_page', ['page' => 'fixture', 'kind' => 'admin']);
			$this->check('Site Builder verifies admin page Inputfields', true, $admin['ok']);

			$manifest = $builder->getManifest($sessionId);
			$this->check('Site Builder manifest records created field', $fieldName, $manifest['fields'][0]['key']);
			$this->check('Site Builder manifest records both verification checks', 2, count($manifest['verification']));

			$warningResult = $builder->executeBuildTool($sessionId, 'write_file', [
				'path' => $filePath,
				'content' => "<?php namespace ProcessWire; trigger_error('AgentTools Site Builder warning test', E_USER_WARNING); ?><h1><?= \$page->title ?></h1>",
			]);
			$this->check('Site Builder rewrites a completed file when content changes', 'rewritten', $warningResult['result']);
			$this->check('Site Builder file rewrite clears stale verification', 0, count($builder->getManifest($sessionId)['verification']));
			$frontWarning = $builder->executeBuildTool($sessionId, 'fetch_page', ['page' => 'fixture', 'kind' => 'front']);
			$this->check('Site Builder front verification catches site warnings', false, $frontWarning['ok']);

			$fixedResult = $builder->executeBuildTool($sessionId, 'write_file', [
				'path' => $filePath,
				'content' => '<?php namespace ProcessWire; ?><h1><?= $page->title ?></h1><p>Corrected</p>',
			]);
			$this->check('Site Builder verification can correct a completed file', 'rewritten', $fixedResult['result']);
			$front = $builder->executeBuildTool($sessionId, 'fetch_page', ['page' => 'fixture', 'kind' => 'front']);
			$admin = $builder->executeBuildTool($sessionId, 'fetch_page', ['page' => 'fixture', 'kind' => 'admin']);
			$this->check('Site Builder verifies corrected front-end page', true, $front['ok']);
			$this->check('Site Builder admin verification renders Inputfields', true, $admin['ok'] && $admin['bytes'] > 0);
			$unchangedResult = $builder->executeBuildTool($sessionId, 'write_file', [
				'path' => $filePath,
				'content' => '<?php namespace ProcessWire; ?><h1><?= $page->title ?></h1><p>Corrected</p>',
			]);
			$this->check('Site Builder skips a completed file with identical content', 'unchanged', $unchangedResult['result']);
			$this->check('Site Builder no-op tells agent not to rewrite the file', true, strpos((string) ($unchangedResult['message'] ?? ''), 'do not rewrite it unless verification reports a problem') !== false);
			$this->check('Site Builder identical file leaves verification current', 2, count($builder->getManifest($sessionId)['verification']));

			$refineStore = new AgentToolsSiteBuilderSession($at, $sessionId);
			$this->wire($refineStore);
			$this->check('Site Builder refinement test obtains lock', true, $refineStore->lock(360));
			$refineState = $refineStore->load();
			$refineState['phase'] = AgentToolsSiteBuilder::phaseDone;
			$refineState['status'] = 'done';
			$refineState['finished'] = time();
			$refineStore->save($refineState);
			$refineStore->unlock();
			$refining = $builder->refine($sessionId, 'Improve the fixture and add one sample page.');
			$this->check('Site Builder starts fresh refinement phase', AgentToolsSiteBuilder::phaseRefine, $refining['phase']);
			$this->check('Site Builder refinement clears stale verification', 0, count($builder->getManifest($sessionId)['verification']));
			$refinementState = $builder->getState($sessionId);
			$refinementOptions = $this->invokeProtected($builder, 'getAskOptions', [$refinementState, AgentToolsSiteBuilder::phaseRefine]);
			$refinementToolNames = [];
			foreach($refinementOptions['tools'] as $tool) {
				$refinementToolNames[] = $refinementOptions['provider'] === AgentToolsEngineer::providerAnthropic ? ($tool['name'] ?? '') : ($tool['function']['name'] ?? '');
			}
			$this->check('Site Builder refinement exposes dedicated page tool', true, in_array('refine_pages', $refinementToolNames, true));
			$this->check('Site Builder refinement excludes schema creation tools', false, in_array('create_fields', $refinementToolNames, true));
			$this->check('Site Builder refinement warns against dumping ProcessWire objects', true, strpos((string) $refinementOptions['systemPrompt'], 'never pass Wire, Page, or PageArray objects') !== false);
			$sampleKey = 'sample-' . $suffix;
			$sampleName = 'sample-' . $suffix;
			$refinedPages = $builder->executeBuildTool($sessionId, 'refine_pages', ['pages' => [
				['key' => 'fixture', 'values' => [$fieldName => 'Refined builder value']],
				[
					'key' => $sampleKey,
					'parent' => 'home',
					'name' => $sampleName,
					'template' => $templateName,
					'status' => 'unpublished',
					'values' => ['title' => 'Refinement sample', $fieldName => 'Sample value'],
				],
			]]);
			$this->check('Site Builder refinement updates approved page content', 'refined', $refinedPages['pages']['fixture'] ?? '');
			$this->check('Site Builder refinement adds sample page', 'created', $refinedPages['pages'][$sampleKey] ?? '');
			$createdFixture = $this->wire()->pages->get((int) $createdFixture->id);
			$this->check('Site Builder refined value is saved', 'Refined builder value', (string) $createdFixture->get($fieldName));
			$samplePage = $this->wire()->pages->get("parent=1, name=$sampleName, include=all");
			$this->check('Site Builder sample page exists', true, $samplePage && $samplePage->id > 0);
			$refinedFile = $builder->executeBuildTool($sessionId, 'write_file', [
				'path' => $filePath,
				'content' => '<?php namespace ProcessWire; ?><h1><?= $page->title ?></h1><p>Refined</p>',
			]);
			$this->check('Site Builder refinement rewrites approved file', 'rewritten', $refinedFile['result'] ?? '');
			$refinedManifest = $builder->getManifest($sessionId);
			$refinedFileEntry = array_values(array_filter($refinedManifest['files'], function($entry) use($filePath) { return ($entry['key'] ?? '') === $filePath; }))[0] ?? [];
			$refinedPageEntry = array_values(array_filter($refinedManifest['pages'], function($entry) use($sampleKey) { return ($entry['key'] ?? '') === $sampleKey; }))[0] ?? [];
			$this->check('Site Builder attributes refined file in manifest', [1], $refinedFileEntry['refinements'] ?? []);
			$this->check('Site Builder attributes sample page in manifest', [1], $refinedPageEntry['refinements'] ?? []);
			$refinementResources = $refinedManifest['refinements'][0]['resources'] ?? [];
			$this->check('Site Builder refinement record lists refined file', true, in_array($filePath, $refinementResources['files'] ?? [], true));
			$this->check('Site Builder refinement record lists sample page', true, in_array($sampleKey, $refinementResources['pages'] ?? [], true));
			$finishingRefinement = $builder->finishRefinement($sessionId);
			$this->check('Site Builder can finish refinement without more agent work', AgentToolsSiteBuilder::phaseVerify, $finishingRefinement['phase']);
			$verificationState = $builder->getState($sessionId);
			$this->check('Site Builder finishing refinement starts fresh verification', 0, $verificationState['phaseRounds']['verify']);

			$rollback = $builder->startOver($sessionId);
			$this->check('Site Builder Start over succeeds', true, $rollback['rollback']['ok']);
			$this->check('Site Builder Start over removes page', 0, (int) $this->wire()->pages->get("parent=1, name=$pageName, include=all")->id);
			$this->check('Site Builder Start over removes refinement page', 0, (int) $this->wire()->pages->get("parent=1, name=$sampleName, include=all")->id);
			$removedTemplate = $this->wire()->templates->get($templateName);
			$removedField = $this->wire()->fields->get($fieldName);
			$this->check('Site Builder Start over removes template', 0, $removedTemplate ? (int) $removedTemplate->id : 0);
			$this->check('Site Builder Start over removes field', 0, $removedField ? (int) $removedField->id : 0);
			$this->check('Site Builder Start over removes file', false, is_file($file));
			$resetManifest = $builder->getManifest($sessionId);
			$this->check('Site Builder Start over resets manifest resources', 0, count($resetManifest['fields']) + count($resetManifest['templates']) + count($resetManifest['pages']) + count($resetManifest['files']));
			$this->check('Site Builder Start over keeps rollback history', 1, count($resetManifest['rollbackHistory']));
			$this->check('Site Builder Start over clears page IDs', [], $builder->getState($sessionId)['pageIds']);
			$describeStep = $builder->step($sessionId);
			$this->check('Site Builder describe phase step returns normally', '', $describeStep['error']);
			$this->check('Site Builder describe phase remains reset', 'reset', $describeStep['status']);
		} finally {
			if($plannerHookId !== null) $at->engineer()->removeHook($plannerHookId);
			if($buildToolHookId !== null) $at->engineer()->removeHook($buildToolHookId);
			if($restartHookId !== null) $at->engineer()->removeHook($restartHookId);
			if($engineerSessionId !== '') $at->engineer()->removeAskSession($engineerSessionId);
			if($buildPromptSessionId !== '') $at->engineer()->removeAskSession($buildPromptSessionId);
			if($buildRunSessionId !== '') $at->engineer()->removeAskSession($buildRunSessionId);
			if($restartedBuildSessionId !== '') $at->engineer()->removeAskSession($restartedBuildSessionId);
			if($uninstalledFieldtypeSessionId !== '') $this->wire()->files->rmdir($at->getFilesPath('builds') . $uninstalledFieldtypeSessionId, true);
			if($sampleName !== '') {
				$sample = $this->wire()->pages->get("parent=1, name=$sampleName, include=all");
				if($sample && $sample->id) $this->wire()->pages->delete($sample, true);
			}
			$page = $this->wire()->pages->get("parent=1, name=$pageName, include=all");
			if($page && $page->id) $this->wire()->pages->delete($page, true);
			$template = $this->wire()->templates->get($templateName);
			if($template && $template->id && !$template->getNumPages()) $this->wire()->templates->delete($template);
			$field = $this->wire()->fields->get($fieldName);
			if($field && $field->id && !$field->numFieldgroups()) $this->wire()->fields->delete($field);
			if(is_file($file)) unlink($file);
			if($sessionId !== '') $this->wire()->files->rmdir($at->getFilesPath('builds') . $sessionId, true);
		}
	}

	/**
	 * Test that template updates never remove fields omitted by the plan.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testSiteBuilderAdditiveTemplateUpdate(AgentTools $at) {
		$builder = $at->siteBuilder();
		$suffix = substr(sha1((string) microtime(true) . random_int(1, PHP_INT_MAX)), 0, 8);
		$templateName = 'at-builder-update-' . $suffix;
		$pageName = $templateName;
		$sessionId = '';
		$template = null;
		$page = null;
		try {
			$template = $this->wire()->templates->add($templateName);
			$template->fieldgroup->add($this->wire()->fields->get('title'));
			$template->fieldgroup->add($this->wire()->fields->get('body'));
			$template->fieldgroup->save();
			$template->save();
			$page = $this->wire(new Page());
			$page->template = $template;
			$page->parent = 1;
			$page->name = $pageName;
			$page->title = 'Additive template fixture';
			$page->body = '<p>Preserve this value.</p>';
			$page->addStatus(Page::statusUnpublished);
			$page->save();

			$plan = [
				'schemaVersion' => 1,
				'title' => 'Additive template update test',
				'summary' => 'Preserve an omitted existing field.',
				'assumptions' => [], 'features' => [], 'design' => [],
				'fields' => [[
					'name' => 'title', 'disposition' => 'reuse', 'type' => 'FieldtypePageTitle',
					'label' => 'Title', 'summary' => 'Existing title.', 'settings' => [],
				]],
				'templates' => [[
					'name' => $templateName, 'disposition' => 'update', 'label' => 'Additive fixture',
					'summary' => 'Keep body when only title is planned.', 'dataOnly' => true,
					'singleton' => false,
					'fields' => [['name' => 'title', 'required' => true, 'columnWidth' => 100, 'settings' => []]],
					'allowedParents' => ['home'], 'allowedChildren' => [], 'settings' => [],
				]],
				'pages' => [
					['key' => 'home', 'disposition' => 'reuse', 'parent' => null, 'name' => 'home', 'template' => 'home', 'status' => 'published', 'values' => []],
					['key' => 'fixture', 'disposition' => 'reuse', 'parent' => 'home', 'name' => $pageName, 'template' => $templateName, 'status' => 'unpublished', 'values' => []],
				],
				'files' => [], 'modules' => [],
				'verification' => ['routes' => [], 'adminPages' => [['template' => $templateName, 'page' => 'fixture']], 'checks' => []],
				'openQuestions' => [],
			];
			$this->check('Site Builder accepts additive update fixture plan', [], $builder->validatePlan($plan));
			$started = $builder->start('Test additive template updates.');
			$sessionId = (string) $started['id'];
			$store = new AgentToolsSiteBuilderSession($at, $sessionId);
			$this->wire($store);
			if(!$store->lock(360)) throw new WireException('Unable to lock additive Site Builder test session.');
			$state = $store->load();
			$state['plan'] = $plan;
			$state['status'] = 'awaiting-approval';
			$store->save($state);
			$store->unlock();
			$builder->approvePlan($sessionId);
			$result = $builder->executeBuildTool($sessionId, 'create_templates', ['names' => [$templateName]]);
			$this->check('Site Builder updates approved existing template', 'updated', $result['templates'][$templateName]);
			$template = $this->wire()->templates->get($templateName);
			$this->check('Site Builder additive update preserves omitted body field', true, $template->fieldgroup->hasField('body'));
			$page = $this->wire()->pages->get((int) $page->id);
			$this->check('Site Builder additive update preserves omitted field data', '<p>Preserve this value.</p>', (string) $page->body);
			$builder->startOver($sessionId);
		} finally {
			if($page && $page->id) $this->wire()->pages->delete($page, true);
			$template = $this->wire()->templates->get($templateName);
			if($template && $template->id && !$template->getNumPages()) $this->wire()->templates->delete($template);
			if($sessionId !== '') $this->wire()->files->rmdir($at->getFilesPath('builds') . $sessionId, true);
		}
	}

	/**
	 * Clean up temporary files.
	 *
	 */
	public function finish() {
		foreach(array_reverse($this->tmpFiles) as $file) {
			if(is_file($file)) unlink($file);
		}
		foreach(array_reverse($this->tmpDirs) as $dir) {
			if(is_dir($dir)) rmdir($dir);
		}
	}

	/**
	 * Test eval PHP validation, including read-only mutation blocking.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testEvalValidation(AgentTools $at) {
		$engineer = $at->engineer();

		$this->check('Read-only validation allows read snippets', '', $engineer->validateEvalPhp('echo $pages->count();', true, 'Read-only mode'));
		$this->check('Read-only validation blocks ProcessWire save()', 'Read-only mode blocked mutating eval_php method: save().', $engineer->validateEvalPhp('$page->save();', true, 'Read-only mode'));
		$this->check('Read-only validation blocks filesystem writes', 'Read-only mode blocked mutating eval_php function: file_put_contents().', $engineer->validateEvalPhp('file_put_contents("/tmp/at-test", "x");', true, 'Read-only mode'));
		$this->check('Read-only validation blocks WireFileTools writes', 'Read-only mode blocked mutating eval_php method: fileputcontents().', $engineer->validateEvalPhp('$files->filePutContents("/tmp/at-test", "x");', true, 'Read-only mode'));
		$this->check('Read-only validation blocks module config writes', 'Read-only mode blocked mutating eval_php method: saveconfig().', $engineer->validateEvalPhp('$modules->saveConfig("AgentTools", []);', true, 'Read-only mode'));
		$this->check('Read-only validation blocks array callback writes', 'Read-only mode blocked mutating eval_php callback: save().', $engineer->validateEvalPhp('call_user_func([$page, "save"]);', true, 'Read-only mode'));
		$this->check('Read-only validation blocks dynamic method calls', 'Read-only mode blocked dynamic eval_php method call.', $engineer->validateEvalPhp('$method = "save"; $page->$method();', true, 'Read-only mode'));
		$this->check('Normal eval validation allows ProcessWire save() syntax', '', $engineer->validateEvalPhp('$page->save();'));
		$unsafeInspection = $engineer->executeLocalTool('eval_php', ['code' => 'echo var_export($page, true);']);
		$this->check('Engineer eval blocks recursive object inspection helpers', true, strpos($unsafeInspection, 'Inspection function var_export() is not allowed') !== false);
		$safeInspection = $engineer->executeLocalTool('eval_php', ['code' => 'echo json_encode(["id" => $page->id]);']);
		$this->check('Engineer eval allows bounded scalar JSON inspection', json_encode(['id' => (int) $this->wire()->page->id]), $safeInspection);
	}

	/**
	 * Test CLI eval argument parsing and source normalization.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testCliEvalParsing(AgentTools $at) {
		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ '--readonly', 'echo 1;' ]]);
		$this->check('Eval parser detects --readonly', true, $options['readOnly']);
		$this->check('Eval parser keeps code after --readonly', 'echo 1;', $options['code']);
		$this->check('Eval parser reports no error for valid readonly code', '', $options['error']);

		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ 'echo', '$pages->count();', '--readonly=false' ]]);
		$this->check('Eval parser supports --readonly=false', false, $options['readOnly']);
		$this->check('Eval parser joins code arguments', 'echo $pages->count();', $options['code']);

		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ '--json', 'return 123;' ]]);
		$this->check('Eval parser detects --json', true, $options['json']);
		$this->check('Eval parser keeps code after --json', 'return 123;', $options['code']);

		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ '--json=false', 'return 123;' ]]);
		$this->check('Eval parser supports --json=false', false, $options['json']);

		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ '--unknown', 'echo 1;' ]]);
		$this->check('Eval parser rejects unknown options', 'Unknown eval option: --unknown', $options['error']);

		$normalized = $this->invokeProtected($at, 'normalizeCliEvalCode', [ "\xEF\xBB\xBF<?php echo \"ok\";\n" ]);
		$this->check('Eval normalizer removes BOM and opening PHP tag', "echo \"ok\";\n", $normalized);

		$normalized = $this->invokeProtected($at, 'normalizeCliEvalCode', [ "  echo \"ok\";\n" ]);
		$this->check('Eval normalizer trims leading whitespace', "echo \"ok\";\n", $normalized);
	}

	/**
	 * Test JSON output from CLI eval.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testCliEvalJson(AgentTools $at) {
		$fuel = $this->wire()->fuel->getArray();

		ob_start();
		$success = $this->invokeProtected($at, 'cliEval', [ 'return ["count" => 123, "page" => $pages->get(1)];', $fuel, [ 'json' => true ] ]);
		$data = $this->decodeJson((string) ob_get_clean());
		$this->check('CLI eval JSON succeeds', true, $success);
		$this->check('CLI eval JSON reports ok', true, $data['ok']);
		$this->check('CLI eval JSON returns array values', 123, $data['return']['count']);
		$this->check('CLI eval JSON summarizes Page objects', 1, $data['return']['page']['id']);

		ob_start();
		$success = $this->invokeProtected($at, 'cliEval', [ 'throw new WireException("Nope");', $fuel, [ 'json' => true ] ]);
		$data = $this->decodeJson((string) ob_get_clean());
		$this->check('CLI eval JSON error returns false', false, $success);
		$this->check('CLI eval JSON error reports not ok', false, $data['ok']);
		$this->check('CLI eval JSON error includes message', 'Nope', $data['error']['message']);
	}

	/**
	 * Test read_file offset/limit ranges.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testReadFileRanges(AgentTools $at) {
		$file = $this->wire()->config->paths->templates . 'at-read-file-test.txt';
		$this->writeTempFile($file, '0123456789abcdefghijklmnopqrstuvwxyz');

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $file ]);
		$this->check('read_file returns full file', '0123456789abcdefghijklmnopqrstuvwxyz', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $file, 'offset' => 10, 'limit' => 5 ]);
		$this->check('read_file returns byte range', 'abcde', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $file, 'limit' => 5 ]);
		$this->check('read_file honors limit without offset', '01234', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $file, 'offset' => 40, 'limit' => 5 ]);
		$this->check('read_file range beyond EOF returns blank', '', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => 'wire/core/Functions.php', 'offset' => 0, 'limit' => 5 ]);
		$this->check('read_file allows configured wire path', '<?php', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => 'site/config.php' ]);
		$this->check('read_file denies site configuration', 'Access denied: sensitive files are not available to AI tools.', $result);

		$envFile = $this->wire()->config->paths->templates . '.env.agenttools-test';
		$this->writeTempFile($envFile, 'SECRET=test');
		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $envFile ]);
		$this->check('read_file denies environment files', 'Access denied: sensitive files are not available to AI tools.', $result);

		$assetFile = $this->wire()->config->paths->assets . 'at-read-file-test.txt';
		$this->writeTempFile($assetFile, 'private asset');
		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $assetFile ]);
		$this->check('read_file denies site assets', 'Access denied: sensitive files are not available to AI tools.', $result);
	}

	/**
	 * Test read_file symlink handling.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testReadFileSymlinks(AgentTools $at) {
		if(!function_exists('symlink')) return;

		$outsideDir = $this->makeTempDir();
		$outsideFile = $outsideDir . 'outside.txt';
		$this->writeTempFile($outsideFile, 'outside-module');

		$moduleLink = $this->wire()->config->paths->siteModules . 'at-read-file-test.txt';
		if(!@symlink($outsideFile, $moduleLink)) return;
		$this->tmpFiles[] = $moduleLink;

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $moduleLink ]);
		$this->check('read_file allows site/modules symlinks', 'outside-module', $result);

		$targetFile = $this->wire()->config->paths->classes . 'at-read-file-target.txt';
		$this->writeTempFile($targetFile, 'target');
		$assetLink = $this->wire()->config->paths->templates . 'at-read-file-link.txt';
		if(!@symlink($targetFile, $assetLink)) return;
		$this->tmpFiles[] = $assetLink;

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $assetLink ]);
		$this->check('read_file denies non-module symlinks', 'Access denied: symlinks are not allowed for this file.', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => 'site/modules/../config.php' ]);
		$this->check('read_file denies traversal paths', 'Access denied: invalid file path.', $result);
	}

	/**
	 * Test migration option parsing and static lint checks.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testMigrationLint(AgentTools $at) {
		$migrations = $at->migrations();

		$options = $this->invokeProtected($migrations, 'parseSelectionOptions', [[ '--file', '20260101000000_test.php' ]]);
		$this->check('Migration parser accepts --file value', '20260101000000_test.php', $options['file']);
		$this->check('Migration parser leaves --file parse error blank', '', $options['error']);

		$options = $this->invokeProtected($migrations, 'parseSelectionOptions', [[ '--file=a.php', '--name=a' ]]);
		$this->check('Migration parser rejects --file with --name', 'Use either --file or --name, not both.', $options['error']);

		$dir = $this->makeTempDir();
		$good = $dir . '20260101000000_add_subtitle.php';
		$this->writeTempFile($good, "<?php namespace ProcessWire;\n\n\$name = wire('at')->migrations->getName(__FILE__);\necho \"# \$name\\n\\n\";\nif(\$fields->get('subtitle')) {\n\techo \"- Skipped existing field: subtitle\\n\";\n\treturn;\n}\necho \"- \$name has been applied\\n\";\n");

		$result = $this->invokeProtected($migrations, 'lintFile', [ $good ]);
		$this->check('Migration lint accepts standard migration fixture errors', [], $result['errors']);
		$this->check('Migration lint accepts standard migration fixture warnings', [], $result['warnings']);

		$bad = $dir . 'bad.php';
		$this->writeTempFile($bad, "<?php echo \"not namespaced\";\n");
		$result = $this->invokeProtected($migrations, 'lintFile', [ $bad ]);
		$this->check('Migration lint catches bad filename', true, in_array('Filename should match YYYYMMDDhhmmss_description.php.', $result['errors'], true));
		$this->check('Migration lint catches missing namespace', true, in_array('File should begin with "<?php namespace ProcessWire;".', $result['errors'], true));
		$this->check('Migration lint warns about missing getName()', true, in_array('Missing standard $name assignment with wire(\'at\')->migrations->getName(__FILE__).', $result['warnings'], true));
	}

	/**
	 * Test save_migration reports the canonical AgentTools migrations path.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testSaveMigrationReportedPath(AgentTools $at) {
		$engineer = $at->engineer();
		$result = $this->invokeProtected($engineer, 'executeSaveMigration', [
			"<?php namespace ProcessWire;\n\necho \"test migration\\n\";\n",
			'agenttools_selftest_path',
			'Self-test migration path report.',
		]);
		$this->check('save_migration reports AgentTools migrations path', true, strpos($result, 'Migration saved: site/assets/at/migrations/') === 0);
		$filename = basename($result);
		$file = $at->getFilesPath('migrations') . $filename;
		$this->check('save_migration wrote reported file', true, is_file($file));
		$this->tmpFiles[] = $file;
	}

	/**
	 * Test schema template data includes ordered template fields.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testSchemaTemplateFields(AgentTools $at) {
		$sitemap = $at->sitemap();
		$templates = $this->invokeProtected($sitemap, 'getSchemaTemplatesData');
		$this->check('Schema template data is an array', true, is_array($templates));
		$this->check('Schema contains home template', true, isset($templates['home']));
		$this->check('Home template includes fields array', true, isset($templates['home']['fields']) && is_array($templates['home']['fields']));
		$this->check('Home template fields include title', true, in_array('title', $templates['home']['fields'], true));
	}

	/**
	 * Test MCP JSON-RPC message shapes without starting a stdio server.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testMcpMessageShapes(AgentTools $at) {
		$mcp = $at->mcp();

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 1,
			'method' => 'initialize',
			'params' => ['protocolVersion' => '2025-06-18'],
		])));
		$this->check('MCP initialize returns protocol version', '2025-06-18', $response['result']['protocolVersion']);
		$this->check('MCP initialize exposes tools capability', true, isset($response['result']['capabilities']['tools']));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 2,
			'method' => 'tools/list',
		])));
		$tools = $response['result']['tools'];
		$names = array_column($tools, 'name');
		$this->check('MCP tools/list includes at_status', true, in_array('at_status', $names, true));
		$this->check('MCP tools/list includes at_site_info', true, in_array('at_site_info', $names, true));
		$this->check('MCP tools/list includes at_eval_readonly', true, in_array('at_eval_readonly', $names, true));
		$this->check('MCP tool definitions use inputSchema', true, isset($tools[0]['inputSchema']));

		$readFileTool = null;
		foreach($tools as $tool) {
			if($tool['name'] === 'at_read_file') {
				$readFileTool = $tool;
				break;
			}
		}
		$this->check('MCP read_file tool supports offset', true, isset($readFileTool['inputSchema']['properties']['offset']));
		$this->check('MCP read_file tool supports limit', true, isset($readFileTool['inputSchema']['properties']['limit']));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 3,
			'method' => 'tools/call',
			'params' => [
				'name' => 'at_eval_readonly',
				'arguments' => ['code' => 'echo $pages->count();'],
			],
		])));
		$this->check('MCP tool call returns text content', 'text', $response['result']['content'][0]['type']);
		$this->check('MCP readonly eval returns numeric output', true, ctype_digit(trim($response['result']['content'][0]['text'])));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 4,
			'method' => 'tools/call',
			'params' => [
				'name' => 'at_eval_readonly',
				'arguments' => ['code' => '$page->save();'],
			],
		])));
		$this->check('MCP readonly eval blocks save()', 'ERROR: Preview-only mode blocked mutating eval_php method: save().', $response['result']['content'][0]['text']);

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 5,
			'method' => 'tools/call',
			'params' => [
				'name' => 'at_migrations_list',
				'arguments' => [],
			],
		])));
		$list = $this->decodeJson($response['result']['content'][0]['text']);
		$this->check('MCP migrations list returns count', true, isset($list['count']));
		$this->check('MCP migrations list returns migrations array', true, isset($list['migrations']) && is_array($list['migrations']));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 6,
			'method' => 'tools/call',
			'params' => [
				'name' => 'at_status',
				'arguments' => [],
			],
		])));
		$status = $this->decodeJson($response['result']['content'][0]['text']);
		$this->check('MCP status returns AgentTools version', true, isset($status['agentTools']['version']));
		$this->check('MCP status includes tool names', true, in_array('at_eval_readonly', $status['tools'], true));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 7,
			'method' => 'tools/call',
			'params' => [
				'name' => 'not_a_tool',
				'arguments' => [],
			],
		])));
		$this->check('MCP unknown tool returns tool error', true, !empty($response['result']['isError']));

		$response = $this->decodeJson($mcp->handleJson('{'));
		$this->check('MCP invalid JSON returns parse error', -32700, $response['error']['code']);

		$this->check('MCP initialized notification returns no response', '', $mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'method' => 'notifications/initialized',
		])));

		$this->check('MCP JSON-RPC responses return no response', '', $mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 'heartbeat-1',
			'result' => new \stdClass(),
		])));
	}

	/**
	 * Test OpenAI Responses API tool definition and tool-call message shapes.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testOpenAIResponsesToolShapes(AgentTools $at) {
		$engineer = $at->engineer();
		$chatTools = $engineer->getToolDefinitions(AgentToolsEngineer::providerOpenAI);
		$this->check('Engineer eval tool warns against dumping ProcessWire objects', true, strpos((string) ($chatTools[0]['function']['description'] ?? ''), 'never pass Wire, Page, or PageArray objects') !== false);
		$responsesTools = $this->invokeProtected($engineer, 'buildOpenAIResponsesTools', [ $chatTools ]);
		$this->check('Responses tools use top-level name', 'eval_php', $responsesTools[0]['name'] ?? '');
		$this->check('Responses tools omit chat function wrapper', false, isset($responsesTools[0]['function']));

		$response = [
			'output' => [[
				'type' => 'function_call',
				'id' => 'fc_123',
				'call_id' => 'call_123',
				'name' => 'site_info',
				'arguments' => '{"type":"pages"}',
			]],
		];
		$calls = $this->invokeProtected($engineer, 'extractToolCalls', [ AgentToolsEngineer::providerOpenAI, $response ]);
		$this->check('Responses function calls are extracted', 'site_info', $calls[0]['name'] ?? '');
		$this->check('Responses function call id is retained', 'call_123', $calls[0]['call_id'] ?? '');
		$this->check('Responses function call arguments decode', 'pages', $calls[0]['input']['type'] ?? '');

		$messages = [];
		$args = [ AgentToolsEngineer::providerOpenAI, &$messages, $response ];
		$this->invokeProtected($engineer, 'appendAssistantMessage', $args);
		$args = [ AgentToolsEngineer::providerOpenAI, &$messages, $calls[0], 'ok' ];
		$this->invokeProtected($engineer, 'appendToolResult', $args);
		$input = $this->invokeProtected($engineer, 'buildOpenAIResponsesInput', [ $messages ]);
		$this->check('Responses input retains function call item', 'function_call', $input[0]['type'] ?? '');
		$this->check('Responses input appends function_call_output item', 'function_call_output', $input[1]['type'] ?? '');
		$this->check('Responses input output references call id', 'call_123', $input[1]['call_id'] ?? '');

		$text = $engineer->extractText(AgentToolsEngineer::providerOpenAI, [
			'output' => [[
				'type' => 'message',
				'content' => [[ 'type' => 'output_text', 'text' => 'done' ]],
			]],
		]);
		$this->check('Responses output text is extracted', 'done', $text);
	}

	/**
	 * Test resumable Engineer rounds, state checkpoints, usage, and locking.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testEngineerStepMode(AgentTools $at) {
		$engineer = $at->engineer();
		$responses = [
			[
				'stop_reason' => 'tool_use',
				'content' => [[
					'type' => 'tool_use',
					'id' => 'tool_test_1',
					'name' => 'test_checkpoint',
					'input' => [ 'value' => 123 ],
				]],
				'usage' => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
			],
			[
				'stop_reason' => 'end_turn',
				'content' => [[ 'type' => 'text', 'text' => 'step mode done' ]],
				'usage' => [ 'input_tokens' => 8, 'output_tokens' => 3 ],
			],
		];
		$lastProviderRequest = null;
		$providerSessionIds = [];
		$hookId = $engineer->addHookBefore('sendProviderRequest', function(HookEvent $event) use(&$responses, &$lastProviderRequest, &$providerSessionIds) {
			$lastProviderRequest = $event->arguments(0);
			$providerSessionIds[] = $lastProviderRequest instanceof AgentToolsRequest ? (string) $lastProviderRequest->sessionId : '';
			$response = array_shift($responses);
			if($response instanceof \Throwable) throw $response;
			$event->return = $response;
			$event->replace = true;
		});

		$sessionId = '';
		$otherSessionId = '';
		$interruptedId = '';
		$resumedId = '';
		$openAIResumedId = '';
		$staleDeleteId = '';
		$responsesSessionId = '';
		try {
			$started = $engineer->startAskSession('Test resumable rounds', [
				'provider' => AgentToolsEngineer::providerAnthropic,
				'apiKey' => 'test-only-key',
				'model' => 'test-model',
				'tools' => [],
				'maxIterations' => 4,
			]);
			$sessionId = $started['sessionId'];
			$this->check('Engineer step session starts ready', 'ready', $started['status']);
			$this->check('Engineer step session starts before provider call', 0, $started['round']);
			$this->check('Engineer step session exposes owner user ID', (int) $this->wire()->user->id, $started['ownerUserId']);

			$state = $engineer->getAskState($sessionId);
			$this->check('Engineer step state excludes API key', false, isset($state['options']['apiKey']));

			$first = $engineer->askStep($sessionId, [ 'apiKey' => 'test-only-key' ]);
			$this->check('Engineer first step requests another round', 'continue', $first['status']);
			$this->check('Engineer first step runs one provider round', 1, $first['round']);
			$this->check('Engineer first step records token usage', 15, $first['tokenUsage']['total']);
			$this->check('Engineer step request uses Engineer session ID', $sessionId, $providerSessionIds[0]);

			$state = $engineer->getAskState($sessionId);
			$this->check('Engineer step checkpoints tool result', true, count($state['messages']) >= 3);
			$this->check('Engineer step clears completed active tool', null, $state['activeTool']);

			$store = new AgentToolsEngineerSession($at, $sessionId);
			$this->wire($store);
			$locked = $store->lock(360);
			$this->check('Engineer session test obtains lock', true, $locked);
			$lockFile = $this->invokeProtected($store, 'getLockFile');
			touch($lockFile, time() - 600);
			$store->save($store->load());
			clearstatcache(true, $lockFile);
			$this->check('Engineer checkpoint refreshes owned lock', true, filemtime($lockFile) >= time() - 2);
			$competitor = new AgentToolsEngineerSession($at, $sessionId);
			$this->wire($competitor);
			$this->check('Engineer refreshed lock cannot be reclaimed', false, $competitor->lock(60));
			$busy = $engineer->askStep($sessionId, [ 'apiKey' => 'test-only-key' ]);
			$this->check('Engineer concurrent step reports busy', 'busy', $busy['status']);
			$store->unlock();

			$second = $engineer->askStep($sessionId, [ 'apiKey' => 'test-only-key' ]);
			$this->check('Engineer second step completes', 'done', $second['status']);
			$this->check('Engineer second step returns response', 'step mode done', $second['response']);
			$this->check('Engineer step accumulates token usage', 26, $second['tokenUsage']['total']);
			$this->check('Engineer completed step returns history', 2, count($second['history']));
			$this->check('Engineer step request reuses session ID', $providerSessionIds[0], $providerSessionIds[1]);

			$responses = [[
				'stop_reason' => 'end_turn',
				'content' => [[ 'type' => 'text', 'text' => 'other step mode done' ]],
			]];
			$otherStarted = $engineer->startAskSession('Test distinct resumable session', [
				'provider' => AgentToolsEngineer::providerAnthropic,
				'apiKey' => 'test-only-key',
				'model' => 'test-model',
				'tools' => [],
			]);
			$otherSessionId = $otherStarted['sessionId'];
			$otherRequestStart = count($providerSessionIds);
			$otherStep = $engineer->askStep($otherSessionId, [ 'apiKey' => 'test-only-key' ]);
			$this->check('Other Engineer step session completes', 'done', $otherStep['status']);
			$this->check('Other Engineer step request uses its session ID', $otherSessionId, $providerSessionIds[$otherRequestStart]);
			$this->check('Different Engineer step sessions use different IDs', false, $providerSessionIds[0] === $providerSessionIds[$otherRequestStart]);

			$responses = [
				[
					'stop_reason' => 'tool_use',
					'content' => [[
						'type' => 'tool_use',
						'id' => 'tool_test_2',
						'name' => 'test_checkpoint',
						'input' => [],
					]],
				],
				[
					'stop_reason' => 'end_turn',
					'content' => [[ 'type' => 'text', 'text' => 'blocking mode done' ]],
				],
			];
			$blockingRequestStart = count($providerSessionIds);
			$blocking = $engineer->ask('Test blocking rounds', [
				'provider' => AgentToolsEngineer::providerAnthropic,
				'apiKey' => 'test-only-key',
				'model' => 'test-model',
				'tools' => [],
				'maxIterations' => 4,
			]);
			$this->check('Engineer blocking ask still completes all rounds', 'blocking mode done', $blocking['response']);
			$this->check('Engineer blocking ask still returns no error', null, $blocking['error']);
			$this->check('Engineer blocking ask reuses session ID', $providerSessionIds[$blockingRequestStart], $providerSessionIds[$blockingRequestStart + 1]);
			$this->check('Blocking and step conversations use different session IDs', false, $providerSessionIds[0] === $providerSessionIds[$blockingRequestStart]);

			$handledTool = [];
			$responses = [
				[
					'stop_reason' => 'tool_use',
					'content' => [[
						'type' => 'tool_use',
						'id' => 'tool_custom_handler',
						'name' => 'site_builder_test',
						'input' => [ 'value' => 456 ],
					]],
				],
				[
					'stop_reason' => 'end_turn',
					'content' => [[ 'type' => 'text', 'text' => 'custom tool done' ]],
				],
			];
			$secret = 'agenttools-test-secret-value';
			$previousDbPass = $this->wire()->config->dbPass;
			$this->wire()->config->dbPass = $secret;
			try {
				$customTool = $engineer->ask('Test runtime custom tool handler', [
					'provider' => AgentToolsEngineer::providerAnthropic,
					'apiKey' => 'test-only-key',
					'model' => 'test-model',
					'tools' => [],
					'maxIterations' => 4,
					'toolHandler' => function(string $name, array $input) use(&$handledTool, $secret): array {
						$handledTool = [ 'name' => $name, 'input' => $input ];
						return [ 'ok' => true, 'value' => $secret ];
					},
				]);
			} finally {
				$this->wire()->config->dbPass = $previousDbPass;
			}
			$this->check('Engineer runtime custom tool handler is called', 'site_builder_test', $handledTool['name'] ?? '');
			$this->check('Engineer runtime custom tool handler receives input', 456, $handledTool['input']['value'] ?? 0);
			$this->check('Engineer runtime custom tool handler completes request', 'custom tool done', $customTool['response']);
			$providerMessages = $lastProviderRequest instanceof AgentToolsRequest ? json_encode($lastProviderRequest->messages) : '';
			$this->check('Engineer redacts known secrets before provider history', true, strpos((string) $providerMessages, '[redacted]') !== false);
			$this->check('Engineer provider history excludes known secret values', false, strpos((string) $providerMessages, $secret) !== false);

			$interrupted = $engineer->startAskSession('Test interrupted tool guard', [
				'provider' => AgentToolsEngineer::providerAnthropic,
				'apiKey' => 'test-only-key',
				'model' => 'test-model',
				'tools' => [],
			]);
			$interruptedId = $interrupted['sessionId'];
			$store = new AgentToolsEngineerSession($at, $interruptedId);
			$this->wire($store);
			$store->lock(360);
			$state = $store->load();
			$state['status'] = 'running';
			$state['activeTool'] = [ 'id' => 'tool_interrupted', 'name' => 'save_migration', 'index' => 0 ];
			$store->save($state);
			$store->unlock();
			$interrupted = $engineer->askStep($interruptedId, [ 'apiKey' => 'test-only-key' ]);
			$this->check('Engineer interrupted tool is not repeated', 'interrupted', $interrupted['status']);
			$this->check('Engineer interrupted tool ends resumable request', true, $interrupted['done']);

			$staleDelete = $engineer->startAskSession('Test stale lock deletion', [
				'provider' => AgentToolsEngineer::providerAnthropic,
				'apiKey' => 'test-only-key',
				'model' => 'test-model',
				'tools' => [],
			]);
			$staleDeleteId = $staleDelete['sessionId'];
			$store = new AgentToolsEngineerSession($at, $staleDeleteId);
			$this->wire($store);
			$store->lock(60);
			$lockFile = $this->invokeProtected($store, 'getLockFile');
			touch($lockFile, time() - 120);
			$cleanupStore = new AgentToolsEngineerSession($at, $staleDeleteId);
			$this->wire($cleanupStore);
			$this->check('Engineer abandoned session allows stale lock deletion', true, $cleanupStore->delete(60));
			$store->unlock();
			$staleDeleteId = '';

			$responses = [[
				'stop_reason' => 'end_turn',
				'content' => [[ 'type' => 'text', 'text' => 'resumed after interruption' ]],
			]];
			$resumed = $engineer->startAskSession('Test interrupted tool resume', [
				'provider' => AgentToolsEngineer::providerAnthropic,
				'apiKey' => 'test-only-key',
				'model' => 'test-model',
				'tools' => [],
				'onInterrupt' => 'resume',
			]);
			$resumedId = $resumed['sessionId'];
			$store = new AgentToolsEngineerSession($at, $resumedId);
			$this->wire($store);
			$store->lock(360);
			$state = $store->load();
			$calls = [];
			foreach([1, 2, 3] as $n) {
				$calls[] = [ 'id' => "tool_resume_$n", 'name' => 'test_checkpoint', 'input' => [] ];
			}
			$state['messages'][] = [ 'role' => 'assistant', 'content' => array_map(function(array $call) {
				return [
					'type' => 'tool_use',
					'id' => $call['id'],
					'name' => $call['name'],
					'input' => $call['input'],
				];
			}, $calls) ];
			$state['status'] = 'running';
			$state['pendingToolCalls'] = $calls;
			$state['nextToolCall'] = 0;
			$state['activeTool'] = [ 'id' => 'tool_resume_1', 'name' => 'test_checkpoint', 'index' => 0 ];
			$store->save($state);
			$store->unlock();
			$capturedRequest = null;
			$captureHook = $engineer->addHookBefore('sendProviderRequest', function(HookEvent $event) use(&$capturedRequest) {
				$capturedRequest = $event->arguments(0);
			});
			$resumed = $engineer->askStep($resumedId, [ 'apiKey' => 'test-only-key' ]);
			$engineer->removeHook($captureHook);
			$this->check('Engineer resume mode continues after interrupted tool', 'done', $resumed['status']);
			$this->check('Engineer resume mode returns provider response', 'resumed after interruption', $resumed['response']);
			$capturedMessages = $capturedRequest instanceof AgentToolsRequest ? $capturedRequest->messages : [];
			$lastMessage = end($capturedMessages);
			$blocks = is_array($lastMessage['content'] ?? null) ? $lastMessage['content'] : [];
			$this->check('Engineer resume mode accounts for every pending tool call', 3, count($blocks));
			$this->check('Engineer resume mode marks active tool outcome unknown', true, strpos($blocks[0]['content'] ?? '', 'outcome is unknown') !== false);
			$this->check('Engineer resume mode marks later tool calls skipped', true, strpos($blocks[1]['content'] ?? '', 'was not executed') !== false);

			$responses = [[
				'choices' => [[ 'message' => [ 'role' => 'assistant', 'content' => 'OpenAI resumed after interruption' ] ]],
			]];
			$openAIResumed = $engineer->startAskSession('Test OpenAI interrupted tool resume', [
				'provider' => AgentToolsEngineer::providerOpenAI,
				'apiKey' => 'test-only-key',
				'model' => 'test-model',
				'endpoint' => 'https://example.test/v1/chat/completions',
				'tools' => [],
				'onInterrupt' => 'resume',
			]);
			$openAIResumedId = $openAIResumed['sessionId'];
			$store = new AgentToolsEngineerSession($at, $openAIResumedId);
			$this->wire($store);
			$store->lock(360);
			$state = $store->load();
			$calls = [];
			$toolCalls = [];
			foreach([1, 2, 3] as $n) {
				$id = "tool_openai_resume_$n";
				$calls[] = [ 'id' => $id, 'name' => 'test_checkpoint', 'input' => [] ];
				$toolCalls[] = [
					'id' => $id,
					'type' => 'function',
					'function' => [ 'name' => 'test_checkpoint', 'arguments' => '{}' ],
				];
			}
			$state['messages'][] = [ 'role' => 'assistant', 'content' => '', 'tool_calls' => $toolCalls ];
			$state['status'] = 'running';
			$state['pendingToolCalls'] = $calls;
			$state['nextToolCall'] = 0;
			$state['activeTool'] = [ 'id' => 'tool_openai_resume_1', 'name' => 'test_checkpoint', 'index' => 0 ];
			$store->save($state);
			$store->unlock();
			$capturedRequest = null;
			$captureHook = $engineer->addHookBefore('sendProviderRequest', function(HookEvent $event) use(&$capturedRequest) {
				$capturedRequest = $event->arguments(0);
			});
			$openAIResumed = $engineer->askStep($openAIResumedId, [ 'apiKey' => 'test-only-key' ]);
			$engineer->removeHook($captureHook);
			$this->check('OpenAI Chat Engineer resume mode continues', 'done', $openAIResumed['status']);
			$this->check('OpenAI Chat Engineer resume returns provider response', 'OpenAI resumed after interruption', $openAIResumed['response']);
			$capturedMessages = $capturedRequest instanceof AgentToolsRequest ? $capturedRequest->messages : [];
			$toolResults = array_values(array_filter($capturedMessages, function(array $message): bool {
				return ($message['role'] ?? '') === 'tool';
			}));
			$this->check('OpenAI Chat Engineer resume accounts for every pending call', 3, count($toolResults));
			$this->check('OpenAI Chat Engineer resume marks active outcome unknown', true, strpos($toolResults[0]['content'] ?? '', 'outcome is unknown') !== false);
			$this->check('OpenAI Chat Engineer resume marks later calls skipped', true, strpos($toolResults[1]['content'] ?? '', 'was not executed') !== false);

			$responses = [
				[
					'output' => [[
						'type' => 'function_call',
						'id' => 'fc_step_1',
						'call_id' => 'call_step_1',
						'name' => 'test_checkpoint',
						'arguments' => '{}',
					]],
					'usage' => [ 'input_tokens' => 4, 'output_tokens' => 2, 'total_tokens' => 6 ],
				],
				[
					'output' => [[
						'type' => 'message',
						'content' => [[ 'type' => 'output_text', 'text' => 'responses step done' ]],
					]],
					'usage' => [ 'input_tokens' => 3, 'output_tokens' => 1, 'total_tokens' => 4 ],
				],
			];
			$responsesStarted = $engineer->startAskSession('Test Responses resumable rounds', [
				'provider' => AgentToolsEngineer::providerOpenAI,
				'apiKey' => 'test-only-key',
				'model' => 'test-model',
				'endpoint' => 'https://api.openai.com/v1/responses',
				'tools' => [],
			]);
			$responsesSessionId = $responsesStarted['sessionId'];
			$responsesFirst = $engineer->askStep($responsesSessionId, [ 'apiKey' => 'test-only-key' ]);
			$responsesSecond = $engineer->askStep($responsesSessionId, [ 'apiKey' => 'test-only-key' ]);
			$this->check('Responses Engineer first step continues', 'continue', $responsesFirst['status']);
			$this->check('Responses Engineer second step completes', 'responses step done', $responsesSecond['response']);
			$this->check('Responses Engineer step accumulates usage', 10, $responsesSecond['tokenUsage']['total']);

			$primary = $at->getPrimaryAgent();
			$capturedApiKey = '';
			$responses = [[
				'content' => [[ 'type' => 'text', 'text' => 'primary key done' ]],
				'output_text' => 'primary key done',
				'choices' => [[ 'message' => [ 'content' => 'primary key done' ] ]],
			]];
			$keyHook = $engineer->addHookBefore('sendProviderRequest', function(HookEvent $event) use(&$capturedApiKey) {
				$request = $event->arguments(0);
				$capturedApiKey = $request instanceof AgentToolsRequest ? (string) $request->apiKey : '';
			});
			$blocking = $engineer->ask('Test blocking primary API key', [ 'tools' => [], 'maxIterations' => 1 ]);
			$engineer->removeHook($keyHook);
			$this->check('Engineer blocking ask preserves primary API key resolution', true, $primary && hash_equals((string) $primary->apiKey, $capturedApiKey));
			$this->check('Engineer blocking primary key request completes', null, $blocking['error']);

			$responses = [ new WireException('Expected blocking request failure') ];
			$blockingError = $engineer->ask('Test blocking error history', [
				'apiKey' => 'test-only-key',
				'history' => [[ 'role' => 'user', 'content' => 'Earlier message' ]],
				'tools' => [],
			]);
			$this->check('Engineer blocking ask reports provider error', 'Expected blocking request failure', $blockingError['error']);
			$this->check('Engineer blocking ask clears history on error', [], $blockingError['history']);

			$guestUserId = (int) $this->wire()->config->guestUserPageID;
			$this->check('Engineer web session rejects missing owner', false, $this->invokeProtected($engineer, 'canAccessAskSession', [[ 'ownerUserId' => 0 ], 123, false]));
			$this->check('Engineer web session rejects guest owner', false, $this->invokeProtected($engineer, 'canAccessAskSession', [[ 'ownerUserId' => $guestUserId ], $guestUserId, false]));
			$this->check('Engineer web session accepts matching owner', true, $this->invokeProtected($engineer, 'canAccessAskSession', [[ 'ownerUserId' => 123 ], 123, false]));

			$sessionRoot = $at->getFilesPath('engineer-sessions');
			$freshOrphan = $sessionRoot . 'orphan-fresh-' . bin2hex(random_bytes(4));
			$staleOrphan = $sessionRoot . 'orphan-stale-' . bin2hex(random_bytes(4));
			mkdir($freshOrphan);
			mkdir($staleOrphan);
			$this->tmpDirs[] = $freshOrphan;
			$this->tmpDirs[] = $staleOrphan;
			touch($staleOrphan, 1);
			$this->invokeProtected($engineer, 'pruneAskSessions', [60, 1]);
			$this->check('Engineer cleanup removes oldest state-less session', false, is_dir($staleOrphan));
			$this->check('Engineer cleanup leaves fresh state-less session', true, is_dir($freshOrphan));
		} finally {
			$engineer->removeHook($hookId);
			if($sessionId !== '') $engineer->removeAskSession($sessionId);
			if($otherSessionId !== '') $engineer->removeAskSession($otherSessionId);
			if($interruptedId !== '') $engineer->removeAskSession($interruptedId);
			if($resumedId !== '') $engineer->removeAskSession($resumedId);
			if($openAIResumedId !== '') $engineer->removeAskSession($openAIResumedId);
			if($staleDeleteId !== '') $engineer->removeAskSession($staleDeleteId);
			if($responsesSessionId !== '') $engineer->removeAskSession($responsesSessionId);
		}
	}

	/**
	 * Test shared AgentTools status data.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testStatusData(AgentTools $at) {
		$status = $at->getStatusData();
		$this->check('Status data includes AgentTools version', true, isset($status['agentTools']['version']));
		$this->check('Status data includes AgentTools files path', true, isset($status['agentTools']['filesPath']));
		$this->check('Status data includes AgentTools htaccess file', true, isset($status['agentTools']['htaccessFile']['file']));
		$this->check('Status data includes ProcessWire version', true, isset($status['processWire']['version']));
		$this->check('Status data includes ProcessWire URLs', true, isset($status['processWire']['rootUrl'], $status['processWire']['httpRootUrl']));
		$this->check('Status data includes sitemap files', true, isset($status['sitemaps']['pages']['file'], $status['sitemaps']['schema']['file']));
		$this->check('Status data includes migration counts', true, isset($status['migrations']['count'], $status['migrations']['applied'], $status['migrations']['pending']));
		$this->check('Status data includes job counts', true, isset($status['jobs']['counts']['pending'], $status['jobs']['counts']['failed']));
		$this->check('Status data includes cron health', true, isset($status['jobs']['cron']['healthy']));
		$this->check('Status data includes status command', true, in_array('php index.php --at-status [--json]', $status['cliCommands'], true));
		$this->check('Status data includes self-test command', true, in_array('php index.php --at-test [--json]', $status['cliCommands'], true));
	}

	/**
	 * Test scheduled task interval next-run calculations.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testScheduledTaskIntervals(AgentTools $at) {
		$schedules = $at->getScheduledTasks();
		$task = $schedules->makeBlankItem();
		$after = strtotime('2026-07-03 12:00:00');
		$intervals = [
			'2-minutes' => 2 * 60,
			'5-minutes' => 5 * 60,
			'10-minutes' => 10 * 60,
			'15-minutes' => 15 * 60,
		];

		foreach($intervals as $frequency => $seconds) {
			$task->frequency = $frequency;
			$this->check("Scheduled task interval $frequency", $after + $seconds, $schedules->calculateNextRun($task, $after));
		}
	}

	/**
	 * Test trace JSON encoding tolerates malformed UTF-8 from provider/tool output.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testTraceJsonEncoding(AgentTools $at) {
		$traces = $at->getTraces();
		$trace = $traces->newTrace([
			'type' => 'task',
			'provider' => 'test',
			'model' => 'test-model',
		]);
		$trace->response = "bad byte: \xB1";
		$file = $traces->save($trace);
		$this->tmpFiles[] = $file;
		$json = file_get_contents($file);
		$data = json_decode((string) $json, true);
		$this->check('Trace JSON with malformed UTF-8 saves valid JSON', true, is_array($data));
		$this->check('Trace JSON substitutes malformed UTF-8', JSON_ERROR_NONE, json_last_error());
	}

	/**
	 * Invoke a protected method for focused helper testing.
	 *
	 * @param object $object
	 * @param string $method
	 * @param array $args
	 * @return mixed
	 *
	 */
	protected function invokeProtected($object, string $method, array $args = []) {
		$reflection = new \ReflectionMethod($object, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($object, $args);
	}

	/**
	 * Make a temporary directory.
	 *
	 * @return string
	 *
	 */
	protected function makeTempDir(): string {
		$base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$dir = $base . 'agenttools-test-' . uniqid('', true) . DIRECTORY_SEPARATOR;
		if(!mkdir($dir, 0700, true) && !is_dir($dir)) {
			$this->fail("Unable to create temp dir: $dir");
		}
		$this->tmpDirs[] = $dir;
		return $dir;
	}

	/**
	 * Write a temporary file and remember it for cleanup.
	 *
	 * @param string $file
	 * @param string $content
	 *
	 */
	protected function writeTempFile(string $file, string $content) {
		if(file_put_contents($file, $content) === false) {
			$this->fail("Unable to write temp file: $file");
		}
		$this->tmpFiles[] = $file;
	}

	/**
	 * Decode JSON or fail the test.
	 *
	 * @param string $json
	 * @return array
	 *
	 */
	protected function decodeJson(string $json): array {
		$data = json_decode($json, true);
		if(!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
			$this->fail('Invalid JSON in test response: ' . json_last_error_msg());
		}
		return $data;
	}
}
