<?php namespace ProcessWire;

/**
 * Agent Tools Site Map
 *
 * Generates a JSON site map of the ProcessWire installation for AI agent
 * orientation. Output is written to site/assets/at/site-map.json.
 *
 */
class AgentToolsSitemap extends AgentToolsHelper {

	/**
	 * Output filename for site map
	 *
	 */
	const outputFile = 'site-map.json';

	/**
	 * Output filename for schema
	 *
	 */
	const schemaFile = 'site-map-schema.json';

	/**
	 * Default page tree depth
	 *
	 */
	const defaultDepth = 3;

	/**
	 * Max children to show per page node (remaining noted via children_count)
	 *
	 */
	const childSample = 5;

	/**
	 * Get array of CLI help [ 'syntax' => 'description' ]
	 *
	 * @return array
	 *
	 */
	public function cliHelp() {
		return [
			'php index.php --at-sitemap-generate' => 'Generate site map JSON to site/assets/at/site-map.json',
			'php index.php --at-sitemap-generate-schema' => 'Generate schema JSON (fields, fieldgroups, templates) to site/assets/at/site-map-schema.json',
		];
	}

	/**
	 * Execute CLI action
	 *
	 * @param string $action
	 * @return bool|null
	 *
	 */
	public function cliExecute($action) {
		if($action === 'generate') {
			return $this->generate();
		} else if($action === 'generate-schema') {
			return $this->generateSchema();
		}
		return null;
	}

	/**
	 * Generate the site map JSON file
	 *
	 * @param int $depth Page tree depth (default 3)
	 * @return bool
	 *
	 */
	public function generate($depth = self::defaultDepth) {

		echo "Generating site map…\n";

		$pageCounts = $this->getPageCountsByTemplate();

		$data = [
			'generated' => date('Y-m-d H:i:s'),
			'processwire' => $this->wire()->config->version,
			'site' => $this->getSiteData(),
			'templates' => $this->getTemplatesData($pageCounts),
			'fields' => $this->getFieldsData(),
			'pages' => $this->getPagesData($depth),
			'modules' => $this->getModulesData(),
		];

		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$file = $this->at->getFilesPath() . self::outputFile;

		if(file_put_contents($file, $json) !== false) {
			echo "Site map written to: $file\n";
			return true;
		} else {
			echo "ERROR: Failed to write site map to: $file\n";
			return false;
		}
	}

	/**
	 * Get the output file path
	 *
	 * @return string
	 *
	 */
	public function getOutputFile() {
		return $this->at->getFilesPath() . self::outputFile;
	}

	/**
	 * Generate the schema JSON file (fields, fieldgroups, templates with full settings)
	 *
	 * @return bool
	 *
	 */
	public function generateSchema() {

		echo "Generating schema…\n";

		$data = [
			'generated' => date('Y-m-d H:i:s'),
			'_readme' => [
				'Fieldgroup-template matching: when a template has no "fieldgroup" property, its fieldgroup shares the same name as the template.',
				'Template properties are non-default values only. Absent properties use ProcessWire defaults.',
				'Fieldgroup context: the "context" object keys are field names; values are property overrides that apply to that field when used in templates referencing this fieldgroup, taking precedence over the global field definition in "fields".',
				'rolesPermissions: a `-` prefix on a permission name means it is revoked for that role rather than granted.',
			],
			'fields' => $this->getSchemaFieldsData(),
			'fieldgroups' => $this->getSchemaFieldgroupsData(),
			'templates' => $this->getSchemaTemplatesData(),
		];

		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
		$file = $this->at->getFilesPath() . self::schemaFile;

		if(file_put_contents($file, $json) !== false) {
			echo "Schema written to: $file\n";
			return true;
		} else {
			echo "ERROR: Failed to write schema to: $file\n";
			return false;
		}
	}

	/**
	 * Get full field data for schema (all non-system fields with type-specific settings)
	 *
	 * @return array
	 *
	 */
	protected function getSchemaFieldsData() {
		$data = [];
		foreach($this->wire()->fields as $field) {
			if($field->flags & Field::flagSystem) continue;
			$entry = $this->filterSchemaData($field->getArray());
			$entry['type'] = $field->type ? $field->type->className() : '';
			if($field->label) $entry['label'] = $field->label;
			if($field->flagsStr) $entry['flagsStr'] = $field->flagsStr;
			if($field->tagsList) $entry['tags'] = $field->tagsList;
			$data[$field->name] = $entry;
		}
		return $data;
	}

	/**
	 * Get full fieldgroup data for schema, including per-field context overrides
	 *
	 * @return array
	 *
	 */
	protected function getSchemaFieldgroupsData() {
		$data = [];
		foreach($this->wire()->fieldgroups as $fieldgroup) {
			$fields = [];
			$context = [];
			foreach($fieldgroup as $field) {
				$fields[] = $field->name;
				$fieldContext = $this->filterSchemaData($fieldgroup->getFieldContextArray($field->id));
				if(!empty($fieldContext)) $context[$field->name] = $fieldContext;
			}
			$entry = ['fields' => $fields];
			if(!empty($context)) $entry['context'] = $context;
			$data[$fieldgroup->name] = $entry;
		}
		return $data;
	}

	/**
	 * Get full template data for schema (all non-system templates with all settings)
	 *
	 * @return array
	 *
	 */
	protected function getSchemaTemplatesData() {
		$defaults = (new Template())->getArray();
		$data = [];
		foreach($this->wire()->templates as $template) {
			if($template->flags & Template::flagSystem) continue;
			$raw = $template->getArray();
			$entry = [];
			foreach($raw as $key => $value) {
				if($key[0] === '_') continue; // runtime-only
				if($key === 'ns' && ($value === 'ProcessWire' || $value === '')) continue; // default namespace
				if($key === 'compile') continue; // PW runtime concern, not relevant to agents
				if(is_array($value)) {
					if(!empty($value)) $entry[$key] = $value; // arrays: only if non-empty
				} else {
					if(!array_key_exists($key, $defaults) || $value !== $defaults[$key]) {
						$entry[$key] = $value; // scalars: only if non-default
					}
				}
			}
			// Always include modified regardless of default value
			$entry['modified'] = date('Y-m-d H:i:s', $raw['modified'] ?? 0);
			// Include fieldgroup only when it differs from the template name (shared fieldgroup)
			$fieldgroupName = $template->fieldgroup ? $template->fieldgroup->name : '';
			if($fieldgroupName !== $template->name) $entry['fieldgroup'] = $fieldgroupName;
			// Include pageClass only when it differs from the PW default
			$pageClass = $template->getPageClass();
			if($pageClass !== 'ProcessWire\\Page') $entry['pageClass'] = $pageClass;
			// Convert template ID arrays to template names
			if(!empty($entry['parentTemplates'])) {
				$entry['parentTemplates'] = $this->templateIdsToNames($entry['parentTemplates']);
			}
			if(!empty($entry['childTemplates'])) {
				$entry['childTemplates'] = $this->templateIdsToNames($entry['childTemplates']);
			}
			// Convert role ID arrays to role names
			foreach(['roles', 'editRoles', 'addRoles', 'createRoles'] as $key) {
				if(empty($entry[$key])) continue;
				$names = [];
				foreach($entry[$key] as $roleId) {
					$role = $this->wire()->roles->get((int) $roleId);
					if($role && $role->id) $names[] = $role->name;
				}
				$entry[$key] = $names;
			}
			// Convert rolesPermissions: role ID keys => permission ID values (negative = revoke)
			if(!empty($entry['rolesPermissions'])) {
				$converted = [];
				foreach($entry['rolesPermissions'] as $roleId => $permIds) {
					$role = $this->wire()->roles->get((int) $roleId);
					if(!$role || !$role->id) continue;
					$perms = [];
					foreach($permIds as $permId) {
						$revoke = $permId < 0;
						$perm = $this->wire()->permissions->get(abs((int) $permId));
						if($perm && $perm->id) $perms[] = ($revoke ? '-' : '') . $perm->name;
					}
					$converted[$role->name] = $perms;
				}
				$entry['rolesPermissions'] = $converted;
			}
			$data[$template->name] = $entry;
		}
		return $data;
	}

	/**
	 * Filter schema data array: remove runtime-only (_-prefixed) keys and
	 * redundant ns value when it is the default "ProcessWire" namespace
	 *
	 * @param array $data
	 * @return array
	 *
	 */
	protected function filterSchemaData(array $data) {
		foreach(array_keys($data) as $key) {
			if($key[0] === '_') {
				unset($data[$key]);
			} else if($key === 'ns' && ($data[$key] === 'ProcessWire' || $data[$key] === '')) {
				unset($data[$key]);
			}
		}
		return $data;
	}

	/**
	 * Get site info data
	 *
	 * @return array
	 *
	 */
	protected function getSiteData() {
		$config = $this->wire()->config;
		$scheme = $config->https ? 'https' : 'http';
		$homepage = $this->wire()->pages->get('/');
		return [
			'name' => ((string) $homepage->get('title')) ?: $config->httpHost,
			'url' => $scheme . '://' . $config->httpHost . $config->urls->root,
			'admin' => $config->urls->admin,
			'multilanguage' => $this->wire()->modules->isInstalled('LanguageSupport'),
		];
	}

	/**
	 * Get page counts indexed by template ID (single query)
	 *
	 * @return array [ templateId => count ]
	 *
	 */
	protected function getPageCountsByTemplate() {
		$database = $this->wire()->database;
		$trashStatus = Page::statusTrash;
		$stmt = $database->prepare("SELECT templates_id, COUNT(*) AS cnt FROM pages WHERE status < :trashStatus GROUP BY templates_id");
		$stmt->execute([':trashStatus' => $trashStatus]);
		$counts = [];
		while($row = $stmt->fetch(\PDO::FETCH_NUM)) {
			$counts[(int) $row[0]] = (int) $row[1];
		}
		return $counts;
	}

	/**
	 * Get templates data
	 *
	 * @param array $pageCounts Page counts indexed by template ID
	 * @return array
	 *
	 */
	protected function getTemplatesData(array $pageCounts = []) {
		$data = [];
		foreach($this->wire()->templates as $template) {
			if($template->flags & Template::flagSystem) continue;
			$fields = [];
			foreach($template->fieldgroup as $field) {
				$fields[] = $field->name;
			}
			$entry = [
				'name' => $template->name,
				'label' => $template->label ?: $template->name,
				'fields' => $fields,
				'page_count' => $pageCounts[$template->id] ?? 0,
			];
			$filename = $template->filename();
			if($filename && is_file($filename)) {
				$entry['file'] = str_replace($this->wire()->config->paths->root, '', $filename);
			}
			if(count($template->childTemplates)) {
				$entry['childTemplates'] = $this->templateIdsToNames($template->childTemplates);
			}
			if(count($template->parentTemplates)) {
				$entry['parentTemplates'] = $this->templateIdsToNames($template->parentTemplates);
			}
			$data[] = $entry;
		}
		return $data;
	}

	/**
	 * Convert an array of template IDs to template names
	 *
	 * @param array $ids
	 * @return array
	 *
	 */
	protected function templateIdsToNames(array $ids) {
		$names = [];
		foreach($ids as $id) {
			$t = $this->wire()->templates->get((int) $id);
			if($t) $names[] = $t->name;
		}
		return $names;
	}

	/**
	 * Get fields data
	 *
	 * @return array
	 *
	 */
	protected function getFieldsData() {
		$data = [];
		foreach($this->wire()->fields as $field) {
			if($field->flags & Field::flagSystem) continue;
			$data[] = [
				'name' => $field->name,
				'label' => $field->label ?: $field->name,
				'type' => $field->type ? $field->type->className() : '',
			];
		}
		return $data;
	}

	/**
	 * Get pages data as a depth-limited tree
	 *
	 * @param int $depth
	 * @return array
	 *
	 */
	protected function getPagesData($depth = self::defaultDepth) {
		$homepage = $this->wire()->pages->get('/');
		return $this->getPageData($homepage, $depth);
	}

	/**
	 * Get data for a single page, recursing into children up to $depth levels
	 *
	 * @param Page $page
	 * @param int $depth
	 * @return array
	 *
	 */
	protected function getPageData(Page $page, $depth) {
		$data = [
			'id' => $page->id,
			'name' => $page->name,
			'path' => $page->path,
			'template' => $page->template->name,
			'title' => (string) $page->get('title'),
			'published' => !($page->status & Page::statusUnpublished),
		];

		$childCount = $page->numChildren(true);
		$data['children_count'] = $childCount;

		if($depth > 0 && $childCount > 0) {
			$children = $page->children('include=all, status<' . Page::statusTrash . ', limit=' . self::childSample);
			$data['children'] = [];
			foreach($children as $child) {
				$data['children'][] = $this->getPageData($child, $depth - 1);
			}
			if($childCount > self::childSample) {
				$data['children_shown'] = self::childSample;
			}
		}

		return $data;
	}

	/**
	 * Get installed and uninstalled non-core modules data
	 *
	 * @return array
	 *
	 */
	protected function getModulesData() {
		$data = [ 'installed' => [], 'uninstalled' => [] ];
		$modules = $this->wire()->modules;

		foreach($modules as $module) {
			$info = $modules->getModuleInfo($module, ['noCache' => false]);
			if(!empty($info['core'])) continue;
			$data['installed'][] = [
				'name' => $info['name'],
				'title' => $info['title'] ?? $info['name'],
				'version' => $modules->formatVersion($info['version'] ?? 0),
			];
		}

		$data['uninstalled'] = array_keys($modules->getInstallable());

		return $data;
	}
}
