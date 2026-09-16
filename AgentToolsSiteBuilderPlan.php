<?php namespace ProcessWire;

/**
 * Parse and validate Site Builder plans against the current ProcessWire site.
 *
 */
class AgentToolsSiteBuilderPlan extends Wire {

	/** @var AgentTools */
	protected $at;

	/** @var array<string,array<string,bool>> */
	protected $propertyCache = [];

	/**
	 * @param AgentTools $at
	 *
	 */
	public function __construct(AgentTools $at) {
		parent::__construct();
		$this->at = $at;
	}

	/**
	 * Decode a JSON plan, accepting an optional fenced Markdown wrapper.
	 *
	 * @param string $text
	 * @return array<string,mixed>
	 * @throws WireException
	 *
	 */
	public function decode(string $text): array {
		$text = trim($text);
		if(preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches)) $text = trim($matches[1]);
		$plan = json_decode($text, true);
		if(!is_array($plan)) throw new WireException('The Site Builder plan is not valid JSON: ' . json_last_error_msg());
		return $plan;
	}

	/**
	 * Validate a plan and return all errors found.
	 *
	 * @param array<string,mixed> $plan
	 * @return string[]
	 *
	 */
	public function validate(array $plan): array {
		$errors = [];
		if((int) ($plan['schemaVersion'] ?? 0) !== 1) $errors[] = 'schemaVersion must be 1.';
		if(trim((string) ($plan['title'] ?? '')) === '') $errors[] = 'title is required.';
		foreach(['fields', 'templates', 'pages', 'files', 'modules'] as $key) {
			if(!isset($plan[$key]) || !is_array($plan[$key])) $errors[] = "$key must be an array.";
		}
		if(isset($plan['verification']) && !is_array($plan['verification'])) $errors[] = 'verification must be an object.';
		if($errors) return $errors;
		$plan = $this->normalize($plan);

		$fieldMap = $this->indexItems($plan['fields'], 'name', 'field', $errors);
		$templateMap = $this->indexItems($plan['templates'], 'name', 'template', $errors);
		$pageMap = $this->indexItems($plan['pages'], 'key', 'page', $errors);
		$moduleMap = $this->indexItems($plan['modules'], 'name', 'module', $errors);
		$this->validateModules($plan['modules'], $errors);
		$this->validateFields($fieldMap, $errors, $moduleMap);
		$this->validateTemplates($templateMap, $fieldMap, $plan['files'], $errors, $moduleMap);
		$this->validatePages($pageMap, $templateMap, $fieldMap, $errors);
		$this->validateFiles($plan['files'], $templateMap, $errors);
		$this->validateVerification((array) ($plan['verification'] ?? []), $pageMap, $templateMap, $errors);
		return array_values(array_unique($errors));
	}

	/**
	 * Normalize a plan and derive required verification entries when omitted.
	 *
	 * @param array<string,mixed> $plan
	 * @return array<string,mixed>
	 *
	 */
	public function normalize(array $plan, array &$warnings = []): array {
		$plannedFields = [];
		foreach((array) ($plan['fields'] ?? []) as $index => $field) {
			if(!is_array($field) || empty($field['name'])) continue;
			$field = $this->normalizeFieldSettings($field, $warnings);
			$plan['fields'][$index] = $field;
			$plannedFields[(string) $field['name']] = $field;
		}
		foreach((array) ($plan['templates'] ?? []) as $index => $template) {
			if(!is_array($template)) continue;
			if(($template['disposition'] ?? '') === 'reuse') {
				$template['fields'] = array_values(array_filter((array) ($template['fields'] ?? []), function($field) use($plannedFields) {
					return is_array($field) && isset($plannedFields[(string) ($field['name'] ?? '')]);
				}));
			}
			foreach((array) ($template['fields'] ?? []) as $fieldIndex => $templateField) {
				if(!is_array($templateField)) continue;
				$fieldName = (string) ($templateField['name'] ?? '');
				if($fieldName === '' || !isset($plannedFields[$fieldName])) continue;
				$template['fields'][$fieldIndex] = $this->normalizeFieldContextSettings($templateField, $plannedFields[$fieldName], $warnings);
			}
			$plan['templates'][$index] = $template;
		}
		$knownFileRoles = [
			'site/templates/_init.php' => 'init',
			'site/templates/_main.php' => 'main',
			'site/templates/admin.php' => 'admin',
			'site/ready.php' => 'ready',
			'site/init.php' => 'siteInit',
		];
		foreach((array) ($plan['files'] ?? []) as $index => $file) {
			if(!is_array($file)) continue;
			$path = str_replace('\\', '/', (string) ($file['path'] ?? ''));
			if(isset($knownFileRoles[$path])) {
				$file['role'] = $knownFileRoles[$path];
				$plan['files'][$index] = $file;
			}
		}
		$verification = is_array($plan['verification'] ?? null) ? $plan['verification'] : [];
		$routes = is_array($verification['routes'] ?? null) ? $verification['routes'] : [];
		$adminPages = is_array($verification['adminPages'] ?? null) ? $verification['adminPages'] : [];
		$checks = is_array($verification['checks'] ?? null) ? $verification['checks'] : [];
		$templates = $this->getTemplateMap($plan);
		$pages = $this->getPageMap($plan);

		$routePages = [];
		foreach($routes as $route) {
			if(is_array($route) && !empty($route['page'])) $routePages[(string) $route['page']] = true;
		}
		$firstPageByTemplate = [];
		foreach($pages as $key => $page) {
			$templateName = (string) ($page['template'] ?? '');
			if($templateName !== '' && !isset($firstPageByTemplate[$templateName])) $firstPageByTemplate[$templateName] = $key;
			if(!empty($templates[$templateName]['dataOnly']) || isset($routePages[$key])) continue;
			$routes[] = ['page' => $key];
			$routePages[$key] = true;
		}

		$coveredTemplates = [];
		foreach($adminPages as $item) {
			if(is_array($item) && !empty($item['template'])) $coveredTemplates[(string) $item['template']] = true;
		}
		foreach($templates as $name => $template) {
			if(isset($coveredTemplates[$name]) || empty($firstPageByTemplate[$name])) continue;
			$adminPages[] = ['template' => $name, 'page' => $firstPageByTemplate[$name]];
			$coveredTemplates[$name] = true;
		}

		$plan['verification'] = [
			'routes' => array_values($routes),
			'adminPages' => array_values($adminPages),
			'checks' => array_values($checks),
		];
		$warnings = array_values(array_unique($warnings));
		return $plan;
	}

	/**
	 * Get the planning system prompt and canonical schema rules.
	 *
	 * @return string
	 *
	 */
	public function getSystemPrompt(): string {
		return <<<'PROMPT'
		You are the planning phase of ProcessWire AgentTools Site Builder. Produce exactly one JSON object and no Markdown or commentary. The object must use schemaVersion 1 and contain: title, summary, assumptions[], features[], design{}, fields[], templates[], pages[], files[], modules[], and openQuestions[]. It may also contain verification{}.

Each field has name, disposition (create/reuse/update), type, label, summary, and settings. Each template has name, disposition, label, summary, dataOnly, singleton, fields[], allowedParents, allowedChildren, and settings. Each template field has name, required, columnWidth, and settings. Each page has key, disposition, parent (page key or null), name, template, status, values, and optional contentBrief. Each file has path, role, disposition, summary, optional template, and optional methods. Modules are explicit objects with name, source, and disposition. Verification contains routes[], adminPages[], and checks[].

	Use the current-site snapshot supplied with the request. Anything present in the snapshot must use reuse or update; use create only for new resources. For reused fields, copy the type and relevant settings exactly from the snapshot. The snapshot's fields list excludes system fields. Its coreFields list contains all existing ProcessWire system fields marked system=true. A system field may be added to a planned template with disposition reuse, its exact type, and its relevant snapshot settings; it must never use create or update.

	Use stable field/template names and page keys, never database IDs. Disposition create requires absence, reuse means no changes, and update means modify an existing resource. A reuse page cannot have values or contentBrief. Page names need only be unique among siblings. Every template field must reference fields[]. For reused templates, list only fields used by planned pages; do not copy every existing assignment from the snapshot, because omitted assignments remain untouched. Every new front-end template needs exactly one role=template file; an existing template may rely on its existing file when that file will not change. Data-only templates do not need template files. File paths are limited to site/templates/, site/classes/, site/modules/, and the exact files site/ready.php and site/init.php. File roles are init, main, template, pageClass, stylesheet, script, module, ready, siteInit, admin, or include. Files allow only create or update; omit existing files that will not be written.

	A plan with a visual design must include a role=stylesheet file. When profileNotes names a primary stylesheet such as site/templates/styles/main.css, include that exact file with create or update as appropriate so all planned styling work is approved before the build begins.

allowedParents and allowedChildren are the only family controls. null preserves defaults; [] means none. Never put noParents, noChildren, parentTemplates, or childTemplates in settings. singleton=true means noParents=-1 and cannot be combined with allowedParents=[]. Template updates are additive in version 1: omit removeFields and never remove an existing field from a template. Rich text uses InputfieldTinyMCE with contentType>=1. Do not use CKEditor. File/image fields require outputFormat=2 for a single value or outputFormat=1 for an array, and maxFiles must agree. Prefer singular field names for single values and plural names for multiple values.

	The homepage and its home template already exist. When changing them, use disposition update. For the home template use singleton=false and allowedParents=null so its existing root-only family setting is preserved.

Hooks shared by front end and admin belong in site/ready.php or site/init.php; front-end-only hooks in site/templates/_init.php; admin-only hooks in site/templates/admin.php. Do not generate a module solely to hold hooks. Generated templates use markup regions with _init.php prepended and _main.php appended. With usePageClasses enabled, follow ProcessWire template-name Page-class conventions. Image uploads are outside version 1. When profileNotes documents an image helper, plan to render every image through that helper and design around the image area it returns, including placeholders; the layout must still work when placeholders are disabled and the helper returns nothing. Without a documented image helper, avoid warnings and broken image markup and make layouts look complete without an image. Omit empty optional values rather than casting them into visible placeholders such as 0.

	Keep short reviewable values in pages[].values. Put long content intent in contentBrief, as an object keyed by field name, so it can be authored during build. verification may be omitted or contain routes, adminPages, and checks; missing routes and representative admin pages are derived automatically. Ensure the plan is internally consistent and leave openQuestions empty when it is ready for approval.

	Follow this compact shape example (example names are illustrative; use the supplied snapshot and user request for the actual plan):
	{
	  "schemaVersion": 1,
	  "title": "Field Notes",
	  "summary": "An editorial publication.",
	  "assumptions": [],
	  "features": [{"key":"articles","label":"Articles","summary":"Article listing and detail pages."}],
	  "design": {"direction":"editorial","cssApproach":"agenttools-base","javascript":"vanilla","summary":"Readable editorial layout.","tokens":{}},
	  "fields": [
	    {"name":"title","disposition":"reuse","type":"FieldtypePageTitle","label":"Title","summary":"Existing title field.","settings":{}},
	    {"name":"body","disposition":"create","type":"FieldtypeTextarea","label":"Body","summary":"Formatted page content.","settings":{"inputfieldClass":"InputfieldTinyMCE","contentType":1,"rows":10,"textformatters":[]}}
	  ],
	  "templates": [{"name":"home","disposition":"update","label":"Home","summary":"Homepage.","dataOnly":false,"singleton":false,"fields":[{"name":"title","required":true,"columnWidth":100,"settings":{}},{"name":"body","required":false,"columnWidth":100,"settings":{}}],"allowedParents":[],"allowedChildren":null,"settings":{}}],
	  "pages": [{"key":"home","disposition":"update","parent":null,"name":"home","template":"home","status":"published","values":{"title":"Field Notes"},"contentBrief":{"body":"A concise introduction to the publication."}}],
	  "files": [
	    {"path":"site/templates/home.php","role":"template","disposition":"update","template":"home","summary":"Render the homepage."},
	    {"path":"site/templates/styles/main.css","role":"stylesheet","disposition":"create","summary":"Site layout and design tokens."}
	  ],
	  "modules": [{"name":"ProcessPageEdit","source":"core","disposition":"reuse"}],
	  "verification": {"routes":[{"page":"home"}],"adminPages":[{"template":"home","page":"home"}],"checks":[]},
	  "openQuestions": []
	}
PROMPT;
	}

	/**
	 * Get a compact snapshot of resources relevant to Site Builder planning.
	 *
	 * @return array<string,mixed>
	 *
	 */
	public function getSiteSnapshot(): array {
		$fields = [];
		$coreFields = [];
		$settingNames = ['inputfieldClass', 'contentType', 'maxFiles', 'outputFormat', 'rows', 'textformatters'];
		foreach($this->wire()->fields as $field) {
			$entry = [
				'name' => (string) $field->name,
				'type' => $field->type ? $field->type->className() : '',
				'label' => (string) ($field->label ?: $field->name),
				'settings' => [],
			];
			foreach($settingNames as $name) {
				$value = $this->snapshotValue($field->get($name));
				if($value !== null && $value !== '' && $value !== []) $entry['settings'][$name] = $value;
			}
			if($field->flags & Field::flagSystem) {
				$entry['system'] = true;
				$coreFields[] = $entry;
				continue;
			}
			$fields[] = $entry;
		}

		$templates = [];
		foreach($this->wire()->templates as $template) {
			if($template->flags & Template::flagSystem) continue;
			$templateFields = [];
			foreach($template->fieldgroup as $field) $templateFields[] = (string) $field->name;
			$filename = (string) $template->filename();
			$templates[] = [
				'name' => (string) $template->name,
				'label' => (string) ($template->label ?: $template->name),
				'fields' => $templateFields,
				'allowedParents' => $this->snapshotAllowedTemplates($template, true),
				'allowedChildren' => $this->snapshotAllowedTemplates($template, false),
				'singleton' => (int) $template->noParents === -1,
				'templateFile' => $this->rootRelativePath($filename),
				'templateFileExists' => $filename !== '' && is_file($filename),
			];
		}

		$pages = [];
		$home = $this->wire()->pages->get(1);
		if($home && $home->id) {
			$pages[] = $this->snapshotPage($home, null, 'home');
			$adminId = (int) $this->wire()->config->adminRootPageID;
			$trashId = (int) $this->wire()->config->trashPageID;
			foreach($home->children('include=all') as $page) {
				if($page->id === $adminId || $page->id === $trashId || $page->status & Page::statusTrash) continue;
				$pages[] = $this->snapshotPage($page, 'home', (string) $page->name);
			}
		}

		$agentsFile = $this->wire()->config->paths->site . 'AGENTS.md';
		return [
			'snapshotVersion' => 1,
			'fields' => $fields,
			'coreFields' => $coreFields,
			'templates' => $templates,
			'pages' => $pages,
			'files' => $this->snapshotFiles(),
			'profileNotes' => is_file($agentsFile) && is_readable($agentsFile) ? (string) file_get_contents($agentsFile) : '',
		];
	}

	/** @return array<string,array<string,mixed>> */
	public function getFieldMap(array $plan): array {
		$errors = [];
		return $this->indexItems((array) ($plan['fields'] ?? []), 'name', 'field', $errors);
	}

	/** @return array<string,array<string,mixed>> */
	public function getTemplateMap(array $plan): array {
		$errors = [];
		return $this->indexItems((array) ($plan['templates'] ?? []), 'name', 'template', $errors);
	}

	/** @return array<string,array<string,mixed>> */
	public function getPageMap(array $plan): array {
		$errors = [];
		return $this->indexItems((array) ($plan['pages'] ?? []), 'key', 'page', $errors);
	}

	/** @return array<string,array<string,mixed>> */
	public function getFileMap(array $plan): array {
		$map = [];
		foreach((array) ($plan['files'] ?? []) as $item) {
			if(is_array($item) && !empty($item['path'])) $map[(string) $item['path']] = $item;
		}
		return $map;
	}

	/**
	 * @param array $items
	 * @param string $key
	 * @param string $label
	 * @param string[] $errors
	 * @return array<string,array<string,mixed>>
	 */
	protected function indexItems(array $items, string $key, string $label, array &$errors): array {
		$map = [];
		foreach($items as $index => $item) {
			if(!is_array($item)) {
				$errors[] = "$label entry $index must be an object.";
				continue;
			}
			$name = trim((string) ($item[$key] ?? ''));
			if($name === '') {
				$errors[] = "$label entry $index is missing $key.";
				continue;
			}
			if(isset($map[$name])) $errors[] = "Duplicate $label $key: $name.";
			$map[$name] = $item;
		}
		return $map;
	}

	/** @param array<string,array<string,mixed>> $fields @param string[] $errors @param array<string,array<string,mixed>> $modules */
	protected function validateFields(array $fields, array &$errors, array $modules = []): void {
		foreach($fields as $name => $item) {
			if($this->wire()->sanitizer->fieldName($name) !== $name) $errors[] = "Invalid field name: $name.";
			$disposition = $this->validateDisposition($item, "field $name", $errors);
			$typeName = $this->normalizeFieldtypeName((string) ($item['type'] ?? ''));
			if($typeName === '') {
				$errors[] = "Field $name is missing type.";
				continue;
			}
			$installed = $this->wire()->modules->isInstalled($typeName);
			if(!$installed && !$this->isPlannedModuleInstall($typeName, $modules)) {
				$errors[] = "Field $name uses uninstalled type $typeName; add it to modules[] with disposition create.";
				continue;
			}
			$fieldtype = $this->wire()->modules->getModule($typeName, ['noInstall' => true, 'noThrow' => true]);
			if(!$fieldtype instanceof Fieldtype) {
				$errors[] = "Field $name uses unavailable type $typeName.";
				continue;
			}
			$settings = $item['settings'] ?? [];
			if(!is_array($settings)) {
				$errors[] = "Field $name settings must be an object.";
				continue;
			}
			$allowed = $this->getFieldProperties($fieldtype);
			foreach(array_keys($settings) as $property) {
				if(!isset($allowed[$property])) $errors[] = "Unknown setting $property for field $name ($typeName).";
			}

			$isFile = $fieldtype instanceof FieldtypeFile;
			if($isFile) {
				if(!array_key_exists('outputFormat', $settings)) {
					$errors[] = "File/image field $name must specify outputFormat.";
				} else {
					$outputFormat = (int) $settings['outputFormat'];
					$maxFiles = (int) ($settings['maxFiles'] ?? 0);
					if(!in_array($outputFormat, [1, 2], true)) $errors[] = "File/image field $name outputFormat must be 1 or 2.";
					if($outputFormat === 2 && $maxFiles !== 1) $errors[] = "Single-value file/image field $name must set maxFiles to 1.";
					if($outputFormat === 1 && $maxFiles === 1) $errors[] = "Array file/image field $name cannot set maxFiles to 1.";
				}
			}
			if(($settings['inputfieldClass'] ?? '') === 'InputfieldTinyMCE' && (int) ($settings['contentType'] ?? 0) < 1) {
				$errors[] = "TinyMCE field $name must set contentType to at least 1.";
			}
			if(($settings['inputfieldClass'] ?? '') === 'InputfieldCKEditor') $errors[] = "Field $name must use InputfieldTinyMCE rather than CKEditor.";

			$existing = $this->wire()->fields->get($name);
			if($existing && $existing->id && ($existing->flags & Field::flagSystem) && $disposition !== 'reuse') {
				$errors[] = "System field $name must use disposition reuse.";
			}
			if($disposition === 'create' && $existing && $existing->id) $errors[] = "Field $name is marked create but already exists.";
			if(($disposition === 'reuse' || $disposition === 'update') && (!$existing || !$existing->id)) $errors[] = "Field $name is marked $disposition but does not exist.";
			if($existing && $existing->id && ($disposition === 'reuse' || $disposition === 'update')) {
				$actualType = $existing->type ? $existing->type->className() : '';
				if(strcasecmp($actualType, $typeName) !== 0) $errors[] = "Field $name type mismatch: plan has $typeName, site has $actualType.";
				if($disposition === 'reuse') {
					foreach($settings as $property => $value) {
						if(!$this->valuesMatch($existing->get($property), $value)) $errors[] = "Reused field $name does not match setting $property.";
					}
				}
			}
		}
	}

	/** @param array<string,array<string,mixed>> $templates @param array<string,array<string,mixed>> $fields @param array $files @param string[] $errors @param array<string,array<string,mixed>> $modules */
	protected function validateTemplates(array $templates, array $fields, array $files, array &$errors, array $modules = []): void {
		$templateProperties = $this->getAnnotatedProperties(Template::class);
		$forbidden = ['noParents', 'noChildren', 'parentTemplates', 'childTemplates'];
		$templateFiles = [];
		foreach($files as $file) {
			if(is_array($file) && ($file['role'] ?? '') === 'template' && !empty($file['template'])) {
				$templateFiles[(string) $file['template']][] = $file;
			}
		}
		foreach($templates as $name => $item) {
			if($this->wire()->sanitizer->templateName($name) !== $name) $errors[] = "Invalid template name: $name.";
			$disposition = $this->validateDisposition($item, "template $name", $errors);
			if(!is_bool($item['dataOnly'] ?? null)) $errors[] = "Template $name dataOnly must be boolean.";
			if(!is_bool($item['singleton'] ?? null)) $errors[] = "Template $name singleton must be boolean.";
			if(!empty($item['singleton']) && isset($item['allowedParents']) && is_array($item['allowedParents']) && count($item['allowedParents']) === 0) {
				$errors[] = "Template $name cannot combine singleton=true with allowedParents=[].";
			}
			if(!empty($item['removeFields'])) $errors[] = "Template $name cannot remove fields in Site Builder version 1.";
			foreach(['allowedParents', 'allowedChildren'] as $familyKey) {
				$value = $item[$familyKey] ?? null;
				if($value !== null && !is_array($value)) $errors[] = "Template $name $familyKey must be an array or null.";
				if(is_array($value)) {
					foreach($value as $related) {
						if(!isset($templates[$related]) && !$this->wire()->templates->get((string) $related)) {
							$errors[] = "Template $name $familyKey references unknown template $related.";
						}
					}
				}
			}
			$settings = $item['settings'] ?? [];
			if(!is_array($settings)) {
				$errors[] = "Template $name settings must be an object.";
				$settings = [];
			} else {
				foreach(array_keys($settings) as $property) {
					if(in_array($property, $forbidden, true)) $errors[] = "Template $name must express $property through allowedParents/allowedChildren.";
					else if(!isset($templateProperties[$property])) $errors[] = "Unknown setting $property for template $name.";
				}
			}
			$templateFields = $item['fields'] ?? [];
			if(!is_array($templateFields)) {
				$errors[] = "Template $name fields must be an array.";
				$templateFields = [];
			} else {
				$seen = [];
				foreach($templateFields as $index => $templateField) {
					if(!is_array($templateField) || empty($templateField['name'])) {
						$errors[] = "Template $name field entry $index is missing name.";
						continue;
					}
					$fieldName = (string) $templateField['name'];
					if(isset($seen[$fieldName])) $errors[] = "Template $name repeats field $fieldName.";
					$seen[$fieldName] = true;
					if(!isset($fields[$fieldName])) $errors[] = "Template $name references field $fieldName that is absent from fields[].";
					$contextSettings = $templateField['settings'] ?? [];
					if(!is_array($contextSettings)) {
						$errors[] = "Template $name field $fieldName settings must be an object.";
					} else if(isset($fields[$fieldName])) {
						$typeName = $this->normalizeFieldtypeName((string) ($fields[$fieldName]['type'] ?? ''));
						$fieldtype = $typeName === '' ? null : $this->getPlannedFieldtype($typeName, $modules);
						if($fieldtype instanceof Fieldtype) {
							$allowed = $this->getFieldProperties($fieldtype);
							foreach(array_keys($contextSettings) as $property) {
								if(!isset($allowed[$property])) $errors[] = "Unknown context setting $property for template $name field $fieldName ($typeName).";
							}
						}
					}
				}
			}
			$existing = $this->wire()->templates->get($name);
			$plannedFileCount = count($templateFiles[$name] ?? []);
			$existingFile = $existing && $existing->id ? (string) $existing->filename() : '';
			if(empty($item['dataOnly']) && ($plannedFileCount > 1 || ($plannedFileCount < 1 && !is_file($existingFile)))) {
				$errors[] = "Front-end template $name must have one role=template file in the plan or an existing template file.";
			}
			if($disposition === 'create' && $existing && $existing->id) $errors[] = "Template $name is marked create but already exists.";
			if(($disposition === 'reuse' || $disposition === 'update') && (!$existing || !$existing->id)) $errors[] = "Template $name is marked $disposition but does not exist.";
			if($existing && $existing->id && $disposition === 'reuse') {
				foreach($templateFields as $templateField) {
					$fieldName = is_array($templateField) ? (string) ($templateField['name'] ?? '') : '';
					if($fieldName !== '' && !$existing->fieldgroup->hasField($fieldName)) $errors[] = "Reused template $name does not contain field $fieldName.";
				}
				foreach($settings as $property => $value) {
					if(!$this->valuesMatch($existing->get($property), $value)) $errors[] = "Reused template $name does not match setting $property.";
				}
			}
		}
	}

	/** @param array<string,array<string,mixed>> $pages @param array<string,array<string,mixed>> $templates @param array<string,array<string,mixed>> $fields @param string[] $errors */
	protected function validatePages(array $pages, array $templates, array $fields, array &$errors): void {
		$siblings = [];
		foreach($pages as $key => $item) {
			$disposition = $this->validateDisposition($item, "page $key", $errors);
			$parent = $item['parent'] ?? null;
			if($parent !== null && !isset($pages[$parent])) $errors[] = "Page $key references unknown parent key $parent.";
			if($parent === null) {
				$home = $this->wire()->pages->get(1);
				if(($item['disposition'] ?? '') === 'create') $errors[] = "Root page $key cannot use disposition create.";
				if(!$home || (string) ($item['name'] ?? '') !== (string) $home->name) $errors[] = "Root page $key must represent the existing homepage.";
			}
			$template = (string) ($item['template'] ?? '');
			if(!isset($templates[$template]) && !$this->wire()->templates->get($template)) $errors[] = "Page $key references unknown template $template.";
			$name = (string) ($item['name'] ?? '');
			if($name === '' || $this->wire()->sanitizer->pageName($name) !== $name) $errors[] = "Page $key has invalid name $name.";
			$siblingKey = (string) $parent . "\0" . $name;
			if(isset($siblings[$siblingKey])) $errors[] = "Pages {$siblings[$siblingKey]} and $key have the same name under the same parent.";
			$siblings[$siblingKey] = $key;
			$values = $item['values'] ?? [];
			$brief = $item['contentBrief'] ?? [];
			if(!is_array($values)) $errors[] = "Page $key values must be an object.";
			if(!is_array($brief)) $errors[] = "Page $key contentBrief must be an object.";
			if($disposition === 'reuse' && (count((array) $values) || count((array) $brief))) $errors[] = "Reused page $key cannot contain values or contentBrief.";
			foreach(array_merge(array_keys((array) $values), array_keys((array) $brief)) as $fieldName) {
				if(!isset($fields[$fieldName])) $errors[] = "Page $key references unknown value field $fieldName.";
				if(isset($templates[$template])) {
					$templateFieldNames = array_column((array) ($templates[$template]['fields'] ?? []), 'name');
					if(!in_array($fieldName, $templateFieldNames, true)) $errors[] = "Page $key field $fieldName is not assigned to template $template.";
				}
			}
		}
	}

	/** @param array $files @param array<string,array<string,mixed>> $templates @param string[] $errors */
	protected function validateFiles(array $files, array $templates, array &$errors): void {
		$roles = ['init', 'main', 'template', 'pageClass', 'stylesheet', 'script', 'module', 'ready', 'siteInit', 'admin', 'include'];
		$roleList = implode(', ', $roles);
		$paths = [];
		foreach($files as $index => $item) {
			if(!is_array($item)) {
				$errors[] = "File entry $index must be an object.";
				continue;
			}
			$path = str_replace('\\', '/', trim((string) ($item['path'] ?? '')));
			if($path === '' || !$this->isAllowedFilePath($path)) $errors[] = "File entry $index has invalid or disallowed path $path.";
			if(isset($paths[$path])) $errors[] = "Duplicate file path: $path.";
			$paths[$path] = true;
			$role = (string) ($item['role'] ?? '');
			if(!in_array($role, $roles, true)) $errors[] = "File $path has invalid role $role; allowed roles: $roleList.";
			$disposition = $this->validateDisposition($item, "file $path", $errors, ['create', 'update']);
			if(($role === 'template' || $role === 'pageClass')) {
				$template = (string) ($item['template'] ?? '');
				if(!isset($templates[$template])) $errors[] = "File $path role $role references unknown template $template.";
			}
			$file = $this->wire()->config->paths->root . $path;
			if($disposition === 'create' && is_file($file)) $errors[] = "File $path is marked create but already exists.";
			if($disposition === 'update' && !is_file($file)) $errors[] = "File $path is marked update but does not exist.";
		}
	}

	/** @param array $modules @param string[] $errors */
	protected function validateModules(array $modules, array &$errors): void {
		foreach($modules as $index => $item) {
			if(!is_array($item) || trim((string) ($item['name'] ?? '')) === '') {
				$errors[] = "Module entry $index is missing name.";
				continue;
			}
			$name = (string) $item['name'];
			$source = (string) ($item['source'] ?? '');
			if(!in_array($source, ['core', 'site', 'generated'], true)) $errors[] = "Module $name has invalid source $source.";
			$disposition = $this->validateDisposition($item, "module $name", $errors);
			$installed = $this->wire()->modules->isInstalled($name);
			if($disposition === 'create' && $installed) $errors[] = "Module $name is marked create but is already installed.";
			if(($disposition === 'reuse' || $disposition === 'update') && !$installed) $errors[] = "Module $name is marked $disposition but is not installed.";
		}
	}

	/**
	 * Is an uninstalled module explicitly approved for installation by this plan?
	 *
	 * @param string $name
	 * @param array<string,array<string,mixed>> $modules
	 * @return bool
	 *
	 */
	protected function isPlannedModuleInstall(string $name, array $modules): bool {
		return isset($modules[$name]) && ($modules[$name]['disposition'] ?? '') === 'create';
	}

	/**
	 * Load an installed or approved-for-installation Fieldtype without installing it.
	 *
	 * @param string $name
	 * @param array<string,array<string,mixed>> $modules
	 * @return Fieldtype|null
	 *
	 */
	protected function getPlannedFieldtype(string $name, array $modules): ?Fieldtype {
		if(!$this->wire()->modules->isInstalled($name) && !$this->isPlannedModuleInstall($name, $modules)) return null;
		$fieldtype = $this->wire()->modules->getModule($name, ['noInstall' => true, 'noThrow' => true]);
		return $fieldtype instanceof Fieldtype ? $fieldtype : null;
	}

	/** @param array $verification @param array $pages @param array $templates @param string[] $errors */
	protected function validateVerification(array $verification, array $pages, array $templates, array &$errors): void {
		foreach((array) ($verification['routes'] ?? []) as $route) {
			$key = is_array($route) ? (string) ($route['page'] ?? '') : '';
			if($key === '' || !isset($pages[$key])) $errors[] = "Verification route references unknown page key $key.";
		}
		$covered = [];
		foreach((array) ($verification['adminPages'] ?? []) as $item) {
			$template = is_array($item) ? (string) ($item['template'] ?? '') : '';
			$page = is_array($item) ? (string) ($item['page'] ?? '') : '';
			if($template === '' || !isset($templates[$template])) $errors[] = "Admin verification references unknown template $template.";
			if($page === '' || !isset($pages[$page])) $errors[] = "Admin verification references unknown page key $page.";
			if($template !== '') $covered[$template] = true;
		}
		foreach($templates as $name => $template) {
			if(!isset($covered[$name])) $errors[] = "Template $name needs a representative admin page verification entry.";
		}
	}

	/** @param array $item @param string $label @param string[] $errors @param string[] $allowed */
	protected function validateDisposition(array $item, string $label, array &$errors, array $allowed = ['create', 'reuse', 'update']): string {
		$value = (string) ($item['disposition'] ?? '');
		if(!in_array($value, $allowed, true)) $errors[] = ucfirst($label) . ' has invalid disposition ' . $value . '.';
		return $value;
	}

	/** @return array<string,bool> */
	protected function getFieldProperties(Fieldtype $fieldtype): array {
		$properties = $this->getAnnotatedProperties(Field::class);
		try {
			$reflection = new \ReflectionClass($fieldtype);
			do {
				if($reflection->hasMethod('getFieldClass')) {
					$method = $reflection->getMethod('getFieldClass');
					if($method->getDeclaringClass()->getName() === $reflection->getName()) {
						$class = wireClassName((string) $method->invoke($fieldtype, []), true);
						if($class !== '' && class_exists($class)) {
							$properties = array_replace($properties, $this->getAnnotatedProperties($class));
						}
					}
				}
				$reflection = $reflection->getParentClass();
			} while($reflection && $reflection->isSubclassOf(Fieldtype::class));
		} catch(\Throwable $e) {
		}
		return $properties;
	}

	/** @param array<string,mixed> $field @param string[] $warnings @return array<string,mixed> */
	protected function normalizeFieldSettings(array $field, array &$warnings): array {
		$name = (string) ($field['name'] ?? '');
		$typeName = $this->normalizeFieldtypeName((string) ($field['type'] ?? ''));
		$fieldtype = $typeName === '' ? null : $this->wire()->modules->getModule($typeName, ['noInstall' => true, 'noThrow' => true]);
		if(!$fieldtype instanceof Fieldtype || !is_array($field['settings'] ?? null)) return $field;
		$allowed = $this->getFieldProperties($fieldtype);
		foreach(array_keys($field['settings']) as $property) {
			if(isset($allowed[$property])) continue;
			unset($field['settings'][$property]);
			$warnings[] = "Dropped unknown setting $property from field $name ($typeName).";
		}
		return $field;
	}

	/** @param array<string,mixed> $context @param array<string,mixed> $field @param string[] $warnings @return array<string,mixed> */
	protected function normalizeFieldContextSettings(array $context, array $field, array &$warnings): array {
		$fieldName = (string) ($field['name'] ?? '');
		$typeName = $this->normalizeFieldtypeName((string) ($field['type'] ?? ''));
		$fieldtype = $typeName === '' ? null : $this->wire()->modules->getModule($typeName, ['noInstall' => true, 'noThrow' => true]);
		if(!$fieldtype instanceof Fieldtype || !is_array($context['settings'] ?? null)) return $context;
		$allowed = $this->getFieldProperties($fieldtype);
		foreach(array_keys($context['settings']) as $property) {
			if(isset($allowed[$property])) continue;
			unset($context['settings'][$property]);
			$warnings[] = "Dropped unknown context setting $property from template field $fieldName ($typeName).";
		}
		return $context;
	}

	/** @return array<string,bool> */
	protected function getAnnotatedProperties(string $class): array {
		if(isset($this->propertyCache[$class])) return $this->propertyCache[$class];
		$properties = [];
		try {
			$reflection = new \ReflectionClass($class);
			do {
				$doc = (string) $reflection->getDocComment();
				if(preg_match_all('/@property(?:-read|-write)?\s+[^\s]+\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $doc, $matches)) {
					foreach($matches[1] as $property) $properties[$property] = true;
				}
				$reflection = $reflection->getParentClass();
			} while($reflection);
		} catch(\ReflectionException $e) {
		}
		return $this->propertyCache[$class] = $properties;
	}

	protected function normalizeFieldtypeName(string $name): string {
		$name = trim($name);
		if($name === '') return '';
		return stripos($name, 'Fieldtype') === 0 ? $name : 'Fieldtype' . ucfirst($name);
	}

	protected function isAllowedFilePath(string $path): bool {
		if(strpos($path, "\0") !== false || $path[0] === '/' || preg_match('!(^|/)\.\.?(/|$)!', $path)) return false;
		if($path === 'site/ready.php' || $path === 'site/init.php') return true;
		foreach(['site/templates/', 'site/classes/', 'site/modules/'] as $prefix) {
			if(strpos($path, $prefix) === 0 && strlen($path) > strlen($prefix)) return true;
		}
		return false;
	}

	/** @return array|null */
	protected function snapshotAllowedTemplates(Template $template, bool $parents): ?array {
		$restriction = (int) ($parents ? $template->noParents : $template->noChildren);
		if($parents && $restriction === -1) return null;
		if($restriction === 1) return [];
		$ids = (array) ($parents ? $template->parentTemplates : $template->childTemplates);
		if(!$ids) return null;
		$names = [];
		foreach($ids as $id) {
			$item = $this->wire()->templates->get((int) $id);
			if($item && $item->id) $names[] = (string) $item->name;
		}
		return $names ?: null;
	}

	/** @return array<string,mixed> */
	protected function snapshotPage(Page $page, ?string $parent, string $key): array {
		$status = $page->status & Page::statusUnpublished ? 'unpublished' : ($page->status & Page::statusHidden ? 'hidden' : 'published');
		return [
			'key' => $key,
			'name' => (string) $page->name,
			'template' => (string) $page->template->name,
			'parent' => $parent,
			'status' => $status,
		];
	}

	/** @return string[] */
	protected function snapshotFiles(): array {
		$root = $this->wire()->config->paths->root;
		$paths = [];
		foreach(['site/templates/', 'site/classes/', 'site/modules/'] as $relative) {
			$directory = $root . $relative;
			if(!is_dir($directory) || !is_readable($directory)) continue;
			try {
				$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
				foreach($iterator as $file) {
					if(!$file->isFile()) continue;
					$path = $this->rootRelativePath($file->getPathname());
					if($path !== '') $paths[$path] = true;
				}
			} catch(\UnexpectedValueException $e) {
			}
		}
		foreach(['site/ready.php', 'site/init.php'] as $relative) {
			if(is_file($root . $relative)) $paths[$relative] = true;
		}
		$paths = array_keys($paths);
		sort($paths, SORT_STRING);
		return $paths;
	}

	protected function rootRelativePath(string $path): string {
		$path = str_replace('\\', '/', $path);
		$root = rtrim(str_replace('\\', '/', $this->wire()->config->paths->root), '/') . '/';
		return strpos($path, $root) === 0 ? substr($path, strlen($root)) : '';
	}

	protected function snapshotValue($value) {
		if($value instanceof WireArray) {
			$values = [];
			foreach($value as $item) $values[] = is_object($item) && isset($item->name) ? (string) $item->name : (string) $item;
			return $values;
		}
		if(is_object($value)) return method_exists($value, '__toString') ? (string) $value : null;
		return $value;
	}

	protected function valuesMatch($actual, $expected): bool {
		if(is_object($actual) && method_exists($actual, '__toString')) $actual = (string) $actual;
		if(is_array($actual) || is_array($expected)) {
			return is_array($actual) && is_array($expected) && wireEncodeJSON($actual, true) === wireEncodeJSON($expected, true);
		}
		return (string) $actual === (string) $expected;
	}
}
