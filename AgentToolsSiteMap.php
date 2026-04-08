<?php namespace ProcessWire;

/**
 * Agent Tools Site Map
 *
 * Generates a JSON site map of the ProcessWire installation for AI agent
 * orientation. Output is written to site/assets/at/site-map.json.
 *
 */
class AgentToolsSiteMap extends AgentToolsHelper {

	/**
	 * Output filename
	 *
	 */
	const outputFile = 'site-map.json';

	/**
	 * Default page tree depth
	 *
	 */
	const defaultDepth = 3;

	/**
	 * Get array of CLI help [ 'syntax' => 'description' ]
	 *
	 * @return array
	 *
	 */
	public function cliHelp() {
		return [
			'php index.php --at-sitemap-generate' => 'Generate site map JSON to site/assets/at/site-map.json',
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

		$data = [
			'generated' => date('Y-m-d H:i:s'),
			'processwire' => $this->wire()->config->version,
			'templates' => $this->getTemplatesData(),
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
	 * Get templates data
	 *
	 * @return array
	 *
	 */
	protected function getTemplatesData() {
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
			];
			if($template->noChildren) $entry['noChildren'] = true;
			if($template->noParents) $entry['noParents'] = (int) $template->noParents;
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
			$children = $page->children('include=all, status<' . Page::statusTrash);
			$data['children'] = [];
			foreach($children as $child) {
				$data['children'][] = $this->getPageData($child, $depth - 1);
			}
		}

		return $data;
	}

	/**
	 * Get installed non-core modules data
	 *
	 * @return array
	 *
	 */
	protected function getModulesData() {
		$data = [];
		$modules = $this->wire()->modules;
		foreach($modules as $module) {
			$info = $modules->getModuleInfo($module, ['noCache' => false]);
			if(!empty($info['core'])) continue;
			$data[] = [
				'name' => $info['name'],
				'title' => $info['title'] ?? $info['name'],
				'version' => $modules->formatVersion($info['version'] ?? 0),
			];
		}
		return $data;
	}
}
