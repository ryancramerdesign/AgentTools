<?php namespace ProcessWire;

/** Expected Site Builder tool input or approved-plan conflict. */
class AgentToolsSiteBuilderToolException extends WireException {}

/**
 * Deterministic, manifest-aware tools used by AgentTools Site Builder.
 *
 */
class AgentToolsSiteBuilderTools extends Wire {

	/** @var AgentTools */
	protected $at;

	/** @var AgentToolsSiteBuilderSession */
	protected $session;

	/** @var AgentToolsSiteBuilderPlan */
	protected $plans;

	/**
	 * @param AgentTools $at
	 * @param AgentToolsSiteBuilderSession $session Locked build session
	 * @param AgentToolsSiteBuilderPlan $plans
	 *
	 */
	public function __construct(AgentTools $at, AgentToolsSiteBuilderSession $session, AgentToolsSiteBuilderPlan $plans) {
		parent::__construct();
		$this->at = $at;
		$this->session = $session;
		$this->plans = $plans;
	}

	/**
	 * Execute a build-specific tool, or return null for an Engineer tool.
	 *
	 * @param string $name
	 * @param array<string,mixed> $input
	 * @return array|string|null
	 *
	 */
	public function execute(string $name, array $input) {
		try {
			switch($name) {
				case 'create_fields': return $this->createFields((array) ($input['names'] ?? []));
				case 'create_templates': return $this->createTemplates((array) ($input['names'] ?? []));
				case 'create_pages': return $this->createPages((array) ($input['pages'] ?? []));
				case 'install_modules': return $this->installModules((array) ($input['names'] ?? []));
				case 'write_file': return $this->writeFile((string) ($input['path'] ?? ''), (string) ($input['content'] ?? ''));
				case 'report_progress': return $this->reportProgress((string) ($input['message'] ?? ''));
				case 'fetch_page': return $this->fetchPage((string) ($input['page'] ?? ''), (string) ($input['kind'] ?? 'front'));
			}
		} catch(AgentToolsSiteBuilderToolException $e) {
			return ['ok' => false, 'error' => $e->getMessage()];
		}
		return null;
	}

	/**
	 * Install modules listed in the approved plan.
	 *
	 * @param string[] $names
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function installModules(array $names): array {
		$plan = $this->getPlan();
		$map = [];
		foreach((array) ($plan['modules'] ?? []) as $item) {
			if(is_array($item) && !empty($item['name'])) $map[(string) $item['name']] = $item;
		}
		$names = $this->cleanNames($names);
		foreach($names as $name) {
			if(!isset($map[$name])) $this->invalid("Module $name is not in the approved Site Builder plan.");
			$item = $map[$name];
			$disposition = (string) $item['disposition'];
			$installed = $this->wire()->modules->isInstalled($name);
			$existing = $this->findManifestItem('modules', $name);
			if($disposition !== 'create' && !$installed) $this->invalid("Module $name is marked $disposition but is not installed.");
			if($disposition === 'create' && $installed && !$existing) $this->invalid("Module $name is already installed but the plan says create.");
		}
		$results = [];
		foreach($names as $name) {
			$item = $map[$name];
			$disposition = (string) $item['disposition'];
			$installed = $this->wire()->modules->isInstalled($name);
			if($disposition !== 'create') {
				$results[$name] = 'reused';
				continue;
			}
			$existing = $this->findManifestItem('modules', $name);
			if($existing && ($existing['status'] ?? '') === 'complete') {
				$results[$name] = 'already complete';
				continue;
			}
			$this->beginManifestItem('modules', $name, [
				'disposition' => 'create',
				'source' => (string) ($item['source'] ?? ''),
			]);
			$this->wire()->modules->refresh();
			$module = $this->wire()->modules->install($name);
			if(!$module) throw new WireException("Unable to install module $name.");
			$this->completeManifestItem('modules', $name);
			$this->log("Installed module $name.", 'module', $name);
			$results[$name] = 'installed';
		}
		return ['ok' => true, 'modules' => $results];
	}

	/**
	 * Create, update, or register fields from the approved plan.
	 *
	 * @param string[] $names
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function createFields(array $names): array {
		$plan = $this->getPlan();
		$map = $this->plans->getFieldMap($plan);
		$names = $this->cleanNames($names);
		$fieldtypes = [];
		foreach($names as $name) {
			if(!isset($map[$name])) $this->invalid("Field $name is not in the approved Site Builder plan.");
			$item = $map[$name];
			$disposition = (string) $item['disposition'];
			$type = (string) ($item['type'] ?? '');
			if(!$this->wire()->modules->isInstalled($type)) {
				$this->invalid("Field $name requires installed type $type. Run install_modules first.");
			}
			$fieldtype = $this->wire()->modules->getModule($type, ['noInstall' => true, 'noThrow' => true]);
			if(!$fieldtype instanceof Fieldtype) $this->invalid("Field $name uses unavailable type $type.");
			$fieldtypes[$name] = $fieldtype;
			$field = $this->wire()->fields->get($name);
			$existing = $this->findManifestItem('fields', $name);
			if($field && $field->id && ($field->flags & Field::flagSystem) && $disposition !== 'reuse') {
				$this->invalid("System field $name must use disposition reuse.");
			}
			if($disposition === 'reuse' && (!$field || !$field->id)) $this->invalid("Reused field $name no longer exists.");
			if($disposition === 'create' && $field && $field->id && !$existing) $this->invalid("Field $name already exists but the plan says create.");
			if($disposition === 'update' && (!$field || !$field->id)) $this->invalid("Field $name no longer exists for update.");
		}
		$results = [];
		foreach($names as $name) {
			$item = $map[$name];
			$disposition = (string) $item['disposition'];
			$field = $this->wire()->fields->get($name);
			if($disposition === 'reuse') {
				$results[$name] = 'reused';
				continue;
			}
			$existing = $this->findManifestItem('fields', $name);
			if($existing && ($existing['status'] ?? '') === 'complete') {
				$results[$name] = 'already complete';
				continue;
			}
			if($disposition === 'create') {
				$this->beginManifestItem('fields', $name, ['disposition' => 'create']);
				if(!$field || !$field->id) {
					$fieldtype = $fieldtypes[$name];
					$class = $fieldtype->getFieldClass();
					$class = empty($class) || $class === 'Field' ? Field::class : wireClassName($class, true);
					$field = $this->wire(new $class());
					$field->name = $name;
					$field->type = $fieldtype;
				}
			} else {
				$this->beginManifestItem('fields', $name, [
					'disposition' => 'update',
					'before' => $field->getExportData(),
				]);
			}
			$field->label = (string) ($item['label'] ?? $name);
			foreach((array) ($item['settings'] ?? []) as $key => $value) $field->set((string) $key, $value);
			$this->wire()->fields->save($field);
			$this->completeManifestItem('fields', $name, ['id' => (int) $field->id]);
			$this->log("Field $name $disposition complete.", 'field', $name);
			$results[$name] = $disposition === 'create' ? 'created' : 'updated';
		}
		return ['ok' => true, 'fields' => $results];
	}

	/**
	 * Create or update templates and their fieldgroups from the approved plan.
	 *
	 * @param string[] $names
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function createTemplates(array $names): array {
		$plan = $this->getPlan();
		$map = $this->plans->getTemplateMap($plan);
		$names = $this->cleanNames($names);
		$results = [];
		foreach($names as $name) {
			if(!isset($map[$name])) $this->invalid("Template $name is not in the approved Site Builder plan.");
			$item = $map[$name];
			$disposition = (string) $item['disposition'];
			$template = $this->wire()->templates->get($name);
			$existing = $this->findManifestItem('templates', $name);
			if($disposition === 'reuse' && (!$template || !$template->id)) $this->invalid("Reused template $name no longer exists.");
			if($disposition === 'create' && $template && $template->id && !$existing) $this->invalid("Template $name already exists but the plan says create.");
			if($disposition === 'update' && (!$template || !$template->id)) $this->invalid("Template $name no longer exists for update.");
			if(!empty($item['singleton']) && is_array($item['allowedParents'] ?? null) && count($item['allowedParents']) === 0) {
				$this->invalid("Template $name cannot be singleton and disallow all parents.");
			}
			if($disposition === 'reuse' || ($existing && ($existing['status'] ?? '') === 'complete')) continue;
			foreach((array) ($item['fields'] ?? []) as $templateField) {
				$fieldName = (string) ($templateField['name'] ?? '');
				$field = $this->wire()->fields->get($fieldName);
				if(!$field || !$field->id) $this->invalid("Template $name requires field $fieldName before it can be built.");
			}
		}

		// Create shells first so family references can point to peers in this call.
		foreach($names as $name) {
			$item = $map[$name];
			$disposition = (string) $item['disposition'];
			$template = $this->wire()->templates->get($name);
			if($disposition === 'reuse') {
				$results[$name] = 'reused';
				continue;
			}
			$existing = $this->findManifestItem('templates', $name);
			if($existing && ($existing['status'] ?? '') === 'complete') {
				$results[$name] = 'already complete';
				continue;
			}
			if($disposition === 'create') {
				$this->beginManifestItem('templates', $name, ['disposition' => 'create']);
				if(!$template || !$template->id) $template = $this->wire()->templates->add($name);
			} else {
				$this->beginManifestItem('templates', $name, [
					'disposition' => 'update',
					'before' => $template->getExportData(),
				]);
			}
		}

		foreach($names as $name) {
			$item = $map[$name];
			$disposition = (string) $item['disposition'];
			if($disposition === 'reuse' || isset($results[$name])) continue;
			$template = $this->wire()->templates->get($name);
			if(!$template || !$template->id) throw new WireException("Unable to load template $name after creation.");
			$data = $template->getExportData();
			$data['label'] = (string) ($item['label'] ?? $name);
			foreach((array) ($item['settings'] ?? []) as $key => $value) $data[(string) $key] = $value;
			$fieldNames = [];
			$contexts = [];
			foreach((array) $item['fields'] as $templateField) {
				$fieldName = (string) $templateField['name'];
				$field = $this->wire()->fields->get($fieldName);
				$fieldNames[] = $fieldName;
				$context = (array) ($templateField['settings'] ?? []);
				if(array_key_exists('required', $templateField)) $context['required'] = (int) (bool) $templateField['required'];
				if(array_key_exists('columnWidth', $templateField)) $context['columnWidth'] = (int) $templateField['columnWidth'];
				$contexts[$fieldName] = $context;
			}
			if($disposition === 'update') {
				$existingFieldNames = (array) ($data['fieldgroupFields'] ?? []);
				foreach($existingFieldNames as $fieldName) {
					if(!in_array($fieldName, $fieldNames, true)) $fieldNames[] = $fieldName;
				}
				$contexts = array_replace((array) ($data['fieldgroupContexts'] ?? []), $contexts);
			}
			$data['fieldgroupFields'] = $fieldNames;
			$data['fieldgroupContexts'] = $contexts;
			$this->applyTemplateFamilyData($data, $item, $name);
			$template->setImportData($data);
			$template->fieldgroup->save();
			$template->fieldgroup->saveContext();
			$template->save();
			$this->completeManifestItem('templates', $name, ['id' => (int) $template->id]);
			$this->log("Template $name $disposition complete.", 'template', $name);
			$results[$name] = $disposition === 'create' ? 'created' : 'updated';
		}
		return ['ok' => true, 'templates' => $results];
	}

	/**
	 * Create or update pages from the approved plan.
	 *
	 * Input objects contain a plan page key and optional generated content for
	 * fields listed in that page's contentBrief.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function createPages(array $items): array {
		$plan = $this->getPlan();
		$map = $this->plans->getPageMap($plan);
		$requested = [];
		foreach($items as $item) {
			if(!is_array($item) || empty($item['key'])) $this->invalid('Each create_pages entry requires a page key.');
			$key = (string) $item['key'];
			if(isset($requested[$key])) $this->invalid("Page $key appears more than once in this create_pages call.");
			$requested[$key] = (array) ($item['content'] ?? []);
		}
		if(!$requested) $this->invalid('At least one approved plan page is required.');
		$state = $this->session->load();
		foreach($requested as $key => $content) {
			if(!isset($map[$key])) $this->invalid("Page $key is not in the approved Site Builder plan.");
			$item = $map[$key];
			$brief = (array) ($item['contentBrief'] ?? []);
			foreach($content as $fieldName => $value) {
				if(!array_key_exists($fieldName, $brief)) $this->invalid("Generated content field $fieldName is not in page $key contentBrief.");
				if(!is_string($value)) $this->invalid("Generated content field $fieldName for page $key must be a string.");
			}
			$status = (string) ($item['status'] ?? 'published');
			if(!in_array($status, ['published', 'unpublished', 'hidden'], true)) $this->invalid("Unsupported page status: $status");
			$disposition = (string) $item['disposition'];
			$page = $this->resolvePage($key, $map, $state);
			if($page && $page->id && (string) $page->template->name !== (string) $item['template']) {
				$this->invalid("Page $key template mismatch: plan has {$item['template']}, site has {$page->template->name}.");
			}
			if($disposition === 'reuse' && (!$page || !$page->id)) $this->invalid("Reused page $key no longer exists.");
			$existing = $this->findManifestItem('pages', $key);
			if($disposition === 'create' && $page && $page->id && !$existing) $this->invalid("Page $key already exists but the plan says create.");
			if($disposition === 'update' && (!$page || !$page->id)) $this->invalid("Page $key no longer exists for update.");
			if($disposition === 'create' && (!$existing || ($existing['status'] ?? '') !== 'complete')) {
				$template = $this->wire()->templates->get((string) $item['template']);
				if(!$template || !$template->id) $this->invalid("Page $key requires template {$item['template']}.");
				$parentKey = $item['parent'] ?? null;
				if($parentKey !== null && !isset($requested[(string) $parentKey])) {
					$parent = $this->resolvePage((string) $parentKey, $map, $state);
					if(!$parent || !$parent->id) $this->invalid("Parent page $parentKey has not been built yet.");
				}
			}
		}
		$keys = $this->orderPageKeys(array_keys($requested), $map);
		$results = [];
		foreach($keys as $key) {
			$item = $map[$key];
			$content = $requested[$key];
			$disposition = (string) $item['disposition'];
			$page = $this->resolvePage($key, $map, $state);
			if($disposition === 'reuse') {
				$state['pageIds'][$key] = (int) $page->id;
				$results[$key] = 'reused';
				continue;
			}
			$existing = $this->findManifestItem('pages', $key);
			if($existing && ($existing['status'] ?? '') === 'complete') {
				$state['pageIds'][$key] = (int) ($existing['id'] ?? 0);
				$results[$key] = 'already complete';
				continue;
			}
			$values = array_merge((array) ($item['values'] ?? []), $content);
			if($disposition === 'create') {
				$this->beginManifestItem('pages', $key, [
					'disposition' => 'create',
					'name' => (string) $item['name'],
				]);
				if(!$page || !$page->id) {
					$parent = $this->resolveParentPage($item, $map, $state);
					$template = $this->wire()->templates->get((string) $item['template']);
					$page = $this->wire(new Page());
					$page->template = $template;
					$page->parent = $parent;
					$page->name = (string) $item['name'];
				}
			} else {
				$beforeValues = [];
				foreach(array_keys($values) as $fieldName) $beforeValues[$fieldName] = $this->normalizeValue($page->get($fieldName));
				$this->beginManifestItem('pages', $key, [
					'disposition' => 'update',
					'id' => (int) $page->id,
					'before' => [
						'parent' => (int) $page->parent_id,
						'template' => (string) $page->template->name,
						'name' => (string) $page->name,
						'status' => (int) $page->status,
						'values' => $beforeValues,
					],
				]);
			}
			$page->of(false);
			foreach($values as $fieldName => $value) $page->set((string) $fieldName, $value);
			$this->applyPageStatus($page, (string) ($item['status'] ?? 'published'));
			$page->save();
			$state['pageIds'][$key] = (int) $page->id;
			$this->session->save($state);
			$this->completeManifestItem('pages', $key, ['id' => (int) $page->id]);
			$this->log("Page $key $disposition complete.", 'page', $key);
			$results[$key] = $disposition === 'create' ? 'created' : 'updated';
		}
		$this->session->save($state);
		return ['ok' => true, 'pages' => $results];
	}

	/**
	 * Write exactly one approved file and record its prior state.
	 *
	 * @param string $path Root-relative path
	 * @param string $content
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function writeFile(string $path, string $content): array {
		$path = str_replace('\\', '/', trim($path));
		$map = $this->plans->getFileMap($this->getPlan());
		if(!isset($map[$path])) $this->invalid("File $path is not in the approved Site Builder plan.");
		$lint = $this->lintPhp($path, $content);
		if($lint !== '') return ['ok' => false, 'path' => $path, 'lint' => $lint];
		$entry = $map[$path];
		$disposition = (string) $entry['disposition'];
		$file = $this->wire()->config->paths->root . $path;
		$existing = $this->findManifestItem('files', $path);
		if($existing && !empty($existing['disposition'])) $disposition = (string) $existing['disposition'];
		$contentHash = hash('sha256', $content);
		$currentHash = is_file($file) ? hash_file('sha256', $file) : '';
		if($existing && is_string($currentHash) && hash_equals($contentHash, $currentHash)) {
			if(($existing['status'] ?? '') !== 'complete' || ($existing['hash'] ?? '') !== $contentHash) {
				$this->completeManifestItem('files', $path, [
					'bytes' => strlen($content),
					'hash' => $contentHash,
				]);
			}
			return [
				'ok' => true,
				'path' => $path,
				'result' => 'unchanged',
				'message' => "File $path already has this exact content. It is complete; do not rewrite it unless verification reports a problem.",
				'bytes' => strlen($content),
				'hash' => $contentHash,
				'lint' => 'ok',
			];
		}
		if($disposition === 'create' && is_file($file) && !$existing) $this->invalid("File $path already exists but the plan says create.");
		if($disposition === 'update' && !is_file($file) && !$existing) $this->invalid("File $path no longer exists for update.");

		$manifestData = ['disposition' => $disposition];
		if($disposition === 'update') {
			$backupName = (string) ($existing['backup'] ?? ('backups/' . sha1($path) . '.bak'));
			$backupFile = $this->session->getPath() . $backupName;
			if(!is_dir(dirname($backupFile))) $this->wire()->files->mkdir(dirname($backupFile), true);
			if(!is_file($backupFile)) {
				if(!is_file($file)) throw new WireException("Unable to back up missing file $path.");
				$bytes = $this->wire()->files->filePutContents($backupFile, (string) file_get_contents($file), LOCK_EX);
				if($bytes === false) throw new WireException("Unable to back up $path.");
			}
			$manifestData['backup'] = $backupName;
		}
		$this->recordMissingDirectories(dirname($file));
		$this->beginManifestItem('files', $path, $manifestData);
		if(!is_dir(dirname($file)) && !$this->wire()->files->mkdir(dirname($file), true)) throw new WireException("Unable to create directory for $path.");
		$tmp = $file . '.at-build-' . getmypid() . '-' . bin2hex(random_bytes(3));
		$bytes = $this->wire()->files->filePutContents($tmp, $content, LOCK_EX);
		if($bytes === false || !@rename($tmp, $file)) {
			@unlink($tmp);
			throw new WireException("Unable to write $path.");
		}
		$this->completeManifestItem('files', $path, [
			'bytes' => strlen($content),
			'hash' => $contentHash,
			'writes' => (int) ($existing['writes'] ?? 0) + 1,
		]);
		$this->log("Wrote $path.", 'file', $path);
		return [
			'ok' => true,
			'path' => $path,
			'result' => $existing ? 'rewritten' : 'written',
			'bytes' => strlen($content),
			'hash' => $contentHash,
			'lint' => 'ok',
		];
	}

	/** @return array<string,mixed> */
	public function reportProgress(string $message): array {
		$message = trim(strip_tags($message));
		if($message === '') return ['ok' => false, 'error' => 'Progress message is blank.'];
		if(strlen($message) > 500) $message = substr($message, 0, 500);
		$this->log($message, 'progress');
		return ['ok' => true, 'message' => $message];
	}

	/**
	 * Render a planned page for front-end or admin-form verification.
	 *
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function fetchPage(string $key, string $kind = 'front'): array {
		$plan = $this->getPlan();
		$map = $this->plans->getPageMap($plan);
		$state = $this->session->load();
		$page = $this->resolvePage($key, $map, $state);
		if(!$page || !$page->id) $this->invalid("Unable to verify page $key because it does not exist.");
		if(!in_array($kind, ['front', 'admin'], true)) $this->invalid("Invalid fetch_page kind $kind.");
		$result = ['ok' => true, 'page' => $key, 'kind' => $kind, 'status' => 200];
		$warnings = [];
		try {
			if($kind === 'admin') {
				$inputfields = null;
				$output = $this->captureSiteErrors(function() use($page, &$inputfields): string {
					$inputfields = $page->template->fieldgroup->getPageInputfields($page, ['populate' => true, 'flat' => false]);
					return (string) $inputfields->render();
				}, $warnings);
				$result['inputfields'] = count($inputfields);
				$result['bytes'] = strlen($output);
				$result['url'] = $this->wire()->config->urls->admin . 'page/edit/?id=' . $page->id;
			} else {
				$renderPage = $page;
				if($page->hasStatus(Page::statusUnpublished)) {
					$renderPage = clone $page;
					$renderPage->removeStatus(Page::statusUnpublished);
				}
				$output = $this->captureSiteErrors(function() use($renderPage): string {
					return (string) $renderPage->render();
				}, $warnings);
				$result['url'] = $page->httpUrl();
				$result['bytes'] = strlen($output);
				$result['excerpt'] = substr(trim(preg_replace('/\s+/', ' ', strip_tags($output))), 0, 1000);
				if(preg_match('/(?:Fatal error|Warning:|Notice:|Uncaught (?:Error|Exception))/i', $output, $matches)) {
					$result['ok'] = false;
					$result['status'] = 500;
					$result['error'] = $matches[0] . ' found in rendered output.';
				}
			}
			if($warnings) {
				$result['ok'] = false;
				$result['status'] = 500;
				$result['error'] = implode("\n", array_values(array_unique($warnings)));
			}
		} catch(\Throwable $e) {
			$result['ok'] = false;
			$result['status'] = 500;
			$result['error'] = $e->getMessage();
		}
		$this->setVerificationResult($kind . ':' . $key, $result);
		$this->log(($result['ok'] ? 'Verified' : 'Verification failed for') . " $kind page $key.", 'verification', $key);
		return $result;
	}

	/**
	 * Restore updates and remove created resources in reverse dependency order.
	 *
	 * @return array<string,mixed>
	 *
	 */
	public function startOver(): array {
		$manifest = $this->getManifest();
		$results = [];
		foreach(array_reverse((array) $manifest['pages']) as $entry) $results[] = $this->rollbackPage($entry);
		foreach(array_reverse((array) $manifest['templates']) as $entry) $results[] = $this->rollbackTemplate($entry);
		foreach(array_reverse((array) $manifest['fields']) as $entry) $results[] = $this->rollbackField($entry);
		foreach(array_reverse((array) $manifest['modules']) as $entry) $results[] = $this->rollbackModule($entry);
		foreach(array_reverse((array) $manifest['files']) as $entry) $results[] = $this->rollbackFile($entry);
		foreach(array_reverse((array) $manifest['directories']) as $directory) {
			$path = $this->wire()->config->paths->root . $directory;
			if(is_dir($path)) @rmdir($path);
		}
		$manifest['rolledBack'] = time();
		$manifest['rollbackResults'] = $results;
		$this->session->saveManifest($manifest);
		$this->log('Site Builder changes were rolled back.', 'rollback');
		return ['ok' => !in_array(false, array_column($results, 'ok'), true), 'results' => $results];
	}

	/** @return array<string,mixed> */
	public function getManifest(): array {
		$manifest = $this->session->loadManifest();
		return array_merge([
			'fields' => [], 'templates' => [], 'pages' => [], 'files' => [],
			'modules' => [], 'directories' => [], 'verification' => [], 'rollbackHistory' => [],
		], $manifest);
	}

	/** @return array<string,mixed> */
	protected function getPlan(): array {
		$state = $this->session->load();
		$plan = $state['plan'] ?? [];
		if(!is_array($plan) || !$plan) throw new WireException('This Site Builder session has no approved plan.');
		return $plan;
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $item */
	protected function applyTemplateFamilyData(array &$data, array $item, string $name): void {
		$singleton = !empty($item['singleton']);
		$parents = $item['allowedParents'] ?? null;
		$children = $item['allowedChildren'] ?? null;
		if($singleton && is_array($parents) && count($parents) === 0) $this->invalid("Template $name cannot be singleton and disallow all parents.");
		if($singleton) $data['noParents'] = -1;
		if(is_array($parents)) {
			$data['parentTemplates'] = array_values($parents);
			if(!$singleton) $data['noParents'] = count($parents) ? 0 : 1;
		}
		if(is_array($children)) {
			$data['childTemplates'] = array_values($children);
			$data['noChildren'] = count($children) ? 0 : 1;
		}
	}

	/** @param array<string,mixed> $item @param array<string,array<string,mixed>> $map @param array<string,mixed> $state */
	protected function resolveParentPage(array $item, array $map, array &$state): Page {
		$parentKey = $item['parent'] ?? null;
		if($parentKey === null) return $this->wire()->pages->get(1);
		$parent = $this->resolvePage((string) $parentKey, $map, $state);
		if(!$parent || !$parent->id) $this->invalid("Parent page $parentKey has not been built yet.");
		return $parent;
	}

	/** @param array<string,array<string,mixed>> $map @param array<string,mixed> $state */
	protected function resolvePage(string $key, array $map, array &$state): ?Page {
		$id = (int) ($state['pageIds'][$key] ?? 0);
		if($id) {
			$page = $this->wire()->pages->get($id);
			if($page && $page->id) return $page;
		}
		if(!isset($map[$key])) return null;
		$item = $map[$key];
		if(($item['parent'] ?? null) === null) {
			$page = ((string) ($item['name'] ?? '') === 'home' || $key === 'home') ? $this->wire()->pages->get(1) : null;
		} else {
			$parent = $this->resolvePage((string) $item['parent'], $map, $state);
			$page = ($parent && $parent->id) ? $this->wire()->pages->get("parent_id=$parent->id, name=" . $this->wire()->sanitizer->selectorValue((string) $item['name']) . ', include=all') : null;
		}
		if($page && $page->id) {
			$state['pageIds'][$key] = (int) $page->id;
			return $page;
		}
		return null;
	}

	/** @param string[] $keys @param array<string,array<string,mixed>> $map @return string[] */
	protected function orderPageKeys(array $keys, array $map): array {
		$result = [];
		$remaining = array_fill_keys($keys, true);
		while($remaining) {
			$progress = false;
			foreach(array_keys($remaining) as $key) {
				$parent = $map[$key]['parent'] ?? null;
				if($parent !== null && isset($remaining[$parent])) continue;
				$result[] = $key;
				unset($remaining[$key]);
				$progress = true;
			}
			if(!$progress) $this->invalid('Page plan contains a circular parent relationship.');
		}
		return $result;
	}

	protected function applyPageStatus(Page $page, string $status): void {
		$page->removeStatus(Page::statusUnpublished | Page::statusHidden);
		if($status === 'unpublished') $page->addStatus(Page::statusUnpublished);
		else if($status === 'hidden') $page->addStatus(Page::statusHidden);
		else if($status !== 'published') $this->invalid("Unsupported page status: $status");
	}

	/** @return string[] */
	protected function cleanNames(array $names): array {
		$result = [];
		foreach($names as $name) {
			$name = trim((string) $name);
			if($name !== '' && !in_array($name, $result, true)) $result[] = $name;
		}
		if(!$result) $this->invalid('At least one approved plan name is required.');
		return $result;
	}

	/** @throws AgentToolsSiteBuilderToolException */
	protected function invalid(string $message): void {
		throw new AgentToolsSiteBuilderToolException($message);
	}

	/** @param array<string,mixed> $data */
	protected function beginManifestItem(string $type, string $key, array $data): void {
		$manifest = $this->getManifest();
		foreach($manifest[$type] as $entry) if(($entry['key'] ?? '') === $key) return;
		$manifest[$type][] = array_merge([
			'key' => $key,
			'status' => 'pending',
			'started' => time(),
		], $data);
		$this->session->saveManifest($manifest);
	}

	/** @param array<string,mixed> $data */
	protected function completeManifestItem(string $type, string $key, array $data = []): void {
		$manifest = $this->getManifest();
		foreach($manifest[$type] as $index => $entry) {
			if(($entry['key'] ?? '') !== $key) continue;
			$manifest[$type][$index] = array_merge($entry, $data, ['status' => 'complete', 'completed' => time()]);
			$manifest['verification'] = [];
			$this->session->saveManifest($manifest);
			return;
		}
		throw new WireException("Unable to complete missing manifest item $type:$key.");
	}

	/** @return array<string,mixed> */
	protected function findManifestItem(string $type, string $key): array {
		$manifest = $this->getManifest();
		foreach($manifest[$type] as $entry) if(($entry['key'] ?? '') === $key) return $entry;
		return [];
	}

	/** @param array<string,mixed> $result */
	protected function setVerificationResult(string $key, array $result): void {
		$manifest = $this->getManifest();
		$manifest['verification'][$key] = array_merge($result, ['checked' => time()]);
		$this->session->saveManifest($manifest);
	}

	protected function recordMissingDirectories(string $directory): void {
		$root = rtrim($this->wire()->config->paths->root, '/') . '/';
		$missing = [];
		while(strpos($directory . '/', $root) === 0 && $directory !== rtrim($root, '/') && !is_dir($directory)) {
			array_unshift($missing, substr($directory, strlen($root)));
			$directory = dirname($directory);
		}
		if(!$missing) return;
		$manifest = $this->getManifest();
		foreach($missing as $path) if(!in_array($path, $manifest['directories'], true)) $manifest['directories'][] = $path;
		$this->session->saveManifest($manifest);
	}

	protected function lintPhp(string $path, string $content): string {
		if(strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') return '';
		try {
			token_get_all($content, TOKEN_PARSE);
			return '';
		} catch(\ParseError $e) {
			return $e->getMessage();
		}
	}

	/**
	 * Execute a verification callback and collect site-code warnings.
	 *
	 * @param callable $callback
	 * @param string[] $warnings
	 * @return mixed
	 */
	protected function captureSiteErrors(callable $callback, array &$warnings) {
		$sitePath = str_replace('\\', '/', rtrim($this->wire()->config->paths->site, '/')) . '/';
		$levels = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED;
		set_error_handler(function(int $severity, string $message, string $file, int $line) use(&$warnings, $sitePath): bool {
			if(!(error_reporting() & $severity)) return false;
			$file = str_replace('\\', '/', $file);
			if(strpos($file, $sitePath) !== 0) return false;
			$relative = 'site/' . substr($file, strlen($sitePath));
			$warnings[] = "$message in $relative:$line";
			return true;
		}, $levels);
		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	protected function normalizeValue($value) {
		if($value instanceof Page) return ['pageId' => (int) $value->id];
		if($value instanceof PageArray) return ['pageIds' => array_map('intval', $value->explode('id'))];
		if($value instanceof WireArray) return $value->getArray();
		if(is_object($value) && method_exists($value, '__toString')) return (string) $value;
		if(is_scalar($value) || $value === null || is_array($value)) return $value;
		return null;
	}

	protected function restoreValue($value) {
		if(is_array($value) && array_key_exists('pageId', $value)) return (int) $value['pageId'];
		if(is_array($value) && array_key_exists('pageIds', $value)) return array_map('intval', (array) $value['pageIds']);
		return $value;
	}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	protected function rollbackPage(array $entry): array {
		$key = (string) ($entry['key'] ?? 'page');
		try {
			$page = $this->wire()->pages->get((int) ($entry['id'] ?? 0));
			if(($entry['disposition'] ?? '') === 'create') {
				if($page && $page->id) $this->wire()->pages->delete($page, true);
				return ['ok' => true, 'item' => "page:$key", 'action' => 'deleted'];
			}
			if($page && $page->id && !empty($entry['before'])) {
				$before = $entry['before'];
				$page->of(false);
				$page->parent = (int) $before['parent'];
				$page->template = (string) $before['template'];
				$page->name = (string) $before['name'];
				$page->status = (int) $before['status'];
				foreach((array) $before['values'] as $name => $value) $page->set($name, $this->restoreValue($value));
				$page->save();
			}
			return ['ok' => true, 'item' => "page:$key", 'action' => 'restored'];
		} catch(\Throwable $e) {
			return ['ok' => false, 'item' => "page:$key", 'error' => $e->getMessage()];
		}
	}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	protected function rollbackTemplate(array $entry): array {
		$name = (string) ($entry['key'] ?? 'template');
		try {
			$template = $this->wire()->templates->get($name);
			if(($entry['disposition'] ?? '') === 'create') {
				if($template && $template->id) $this->wire()->templates->delete($template);
				return ['ok' => true, 'item' => "template:$name", 'action' => 'deleted'];
			}
			if($template && $template->id && !empty($entry['before'])) {
				$template->setImportData((array) $entry['before']);
				$template->fieldgroup->save();
				$template->fieldgroup->saveContext();
				$template->save();
			}
			return ['ok' => true, 'item' => "template:$name", 'action' => 'restored'];
		} catch(\Throwable $e) {
			return ['ok' => false, 'item' => "template:$name", 'error' => $e->getMessage()];
		}
	}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	protected function rollbackField(array $entry): array {
		$name = (string) ($entry['key'] ?? 'field');
		try {
			$field = $this->wire()->fields->get($name);
			if(($entry['disposition'] ?? '') === 'create') {
				if($field && $field->id && $field->numFieldgroups()) {
					return ['ok' => true, 'item' => "field:$name", 'action' => 'skipped', 'reason' => 'Field is still assigned to a fieldgroup.'];
				}
				if($field && $field->id) $this->wire()->fields->delete($field);
				return ['ok' => true, 'item' => "field:$name", 'action' => $field && $field->id ? 'deleted' : 'missing'];
			}
			if($field && $field->id && !empty($entry['before'])) {
				$field->setImportData((array) $entry['before']);
				$field->save();
			}
			return ['ok' => true, 'item' => "field:$name", 'action' => 'restored'];
		} catch(\Throwable $e) {
			return ['ok' => false, 'item' => "field:$name", 'error' => $e->getMessage()];
		}
	}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	protected function rollbackFile(array $entry): array {
		$path = (string) ($entry['key'] ?? 'file');
		$file = $this->wire()->config->paths->root . $path;
		try {
			if(($entry['disposition'] ?? '') === 'create') {
				if(is_file($file)) unlink($file);
				return ['ok' => true, 'item' => "file:$path", 'action' => 'deleted'];
			}
			$backup = $this->session->getPath() . (string) ($entry['backup'] ?? '');
			if(!is_file($backup)) throw new WireException("Backup missing for $path.");
			if($this->wire()->files->filePutContents($file, (string) file_get_contents($backup), LOCK_EX) === false) throw new WireException("Unable to restore $path.");
			return ['ok' => true, 'item' => "file:$path", 'action' => 'restored'];
		} catch(\Throwable $e) {
			return ['ok' => false, 'item' => "file:$path", 'error' => $e->getMessage()];
		}
	}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	protected function rollbackModule(array $entry): array {
		$name = (string) ($entry['key'] ?? 'module');
		try {
			if(($entry['disposition'] ?? '') === 'create' && $this->wire()->modules->isInstalled($name)) {
				$this->wire()->modules->uninstall($name);
			}
			return ['ok' => true, 'item' => "module:$name", 'action' => 'uninstalled'];
		} catch(\Throwable $e) {
			return ['ok' => false, 'item' => "module:$name", 'error' => $e->getMessage()];
		}
	}

	protected function log(string $message, string $type, string $item = ''): void {
		$this->session->appendLog([
			'phase' => (string) ($this->session->load()['phase'] ?? ''),
			'type' => $type,
			'item' => $item,
			'message' => $message,
		]);
	}
}
