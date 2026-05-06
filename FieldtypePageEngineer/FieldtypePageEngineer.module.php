<?php namespace ProcessWire;

require_once(__DIR__ . '/PageEngineerField.php');

class FieldtypePageEngineer extends Fieldtype implements Module {

	public static function getModuleInfo() {
		return [
			'title' => 'Page Engineer',
			'version' => 1,
			'summary' => 'Agent Tools Page Engineer is an AI agent Fieldtype to help you with any page editing task.',
			'requires' => [ 'AgentTools' ],
		];
	}

	/**
	 * Get the Field class to use for fields of this type
	 *
	 * @param array $a Field data from DB (if needed)
	 * @return string
	 * @since 3.0.258
	 *
	 */
	public function getFieldClass(array $a = array()) {
		return 'PageEngineerField';
	}

	/**
	 * Return the blank value for this fieldtype
	 *
	 * @param Page $page
	 * @param Field $field
	 * @return PageEngineerItems
	 *
	 */
	public function getBlankValue(Page $page, Field $field) {
		$value = new PageEngineerItems();
		$this->wire($value);
		return $value;
	}

	/**
	 * Sanitize value for storage
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param string $value
	 * @return PageEngineerItems
	 *
	 */
	public function sanitizeValue(Page $page, Field $field, $value) {
		if($value instanceof PageEngineerItems) return $value;
		$newValue = $this->getBlankValue($page, $field);
		if(is_string($value) && strlen($value)) $newValue->setJson($value);
		return $newValue;
	}

	/**
	 * Return whether the given value is considered empty or not.
	 *
	 * @param Field $field
	 * @param mixed $value
	 * @return bool
	 *
	 */
	public function isEmptyValue(Field $field, $value) {
		if($value instanceof PageEngineerItems) return count($value) === 0;
		return empty($value);
	}

	/**
	 * Given a raw value (value as stored in database), return the value as it would appear in a Page object.
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param string $value
	 * @return PageEngineerItems
	 *
	 */
	public function ___wakeupValue(Page $page, Field $field, $value) {
		if($value instanceof PageEngineerItems) return $value;
		$values = $this->getBlankValue($page, $field);
		if(is_string($value) && strlen($value)) {
			$values->setJson($value);
			$values->resetTrackChanges();
		}
		return $values;
	}

	/**
	 * Given an 'awake' value, as set by wakeupValue(), convert the value back to a basic type for storage in database.
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param PageEngineerItems $value
	 * @return string
	 *
	 */
	public function ___sleepValue(Page $page, Field $field, $value) {
		if(!$value instanceof PageEngineerItems) return '';
		return $value->getJson();
	}

	/**
	 * Render an HTML markup version of the value
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param string $value
	 * @param string $property
	 * @return string
	 *
	 */
	public function ___markupValue(Page $page, Field $field, $value = null, $property = '') {
		if($value === null) $value = $page->getUnformatted($field->name);
		if(!$value instanceof PageEngineerItems) $value = $this->sanitizeValue($page, $field, $value);
		if(!$value->formatted()) $value = $this->formatValue($page, $field, $value);
		return '<pre>' . htmlspecialchars($value->getJson()) . '</pre>';
	}

	/**
	 * Format value when $page output formatting is on
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param string $value
	 * @return string
	 *
	 */
	public function ___formatValue(Page $page, Field $field, $value) {
		return $value;
	}

	/**
	 * Get the Inputfield module that provides input for Field
	 *
	 * @param Page $page
	 * @param Field $field
	 * @return Inputfield
	 *
	 */
	public function getInputfield(Page $page, Field $field) {
		$modules = $this->wire()->modules;

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$t = $this;

		/** @var PageEngineerItems $values */
		$values = $page->get($field->name);

		if(!$values instanceof PageEngineerItems) {
			$values = $this->getBlankValue($page, $field);
		}

		$f->addHookAfter('renderReadyHook', $this, 'renderReadyInputfield');

		$f->addHookAfter('processInput', function($e) use($t, $f, $page, $field, $values) {
			$text = $f->val();
			$method = 'ProcessPageEdit::processSaveRedirect';
			$t->addHookBefore($method, function($e) use($t, $text, $page, $field, $values) {
				$at = $t->wire('at'); /** @var AgentTools $at */
				$input = $t->wire()->input;
				if($input->post('_at_reset')) {
					$blank = $t->getBlankValue($page, $field);
					$page->setAndSave($field->name, $blank);
				} else if($input->post('_at_undo')) {
					$t->undoLastAgentEdit($page, $field, $values);
				} else if(strlen($text)) {
					$n = (int) $input->post('_at_agent');
					$agents = $at->getAgents()->getArray();
					$agent = isset($agents[$n]) ? $agents[$n] : null;
					if($agent) $t->wire->session->setFor($t, 'agent', $n);
					$requestItem = $values->newItem($text);
					$responseItem = $t->sendAgentRequest($page, $field, $text, $values, $agent);
					if(!$responseItem) return;
					$values->add($requestItem);
					$values->add($responseItem);
					$page->setAndSave($field->name, $values);
				}
			});
		});

		return $f;
	}

	/**
	 * Prepare the page-editor engineer Inputfield for render
	 *
	 * @param HookEvent $e
	 *
	 */
	public function renderReadyInputfield(HookEvent $e) {
		$f = $e->object; /** @var InputfieldTextarea $f */
		[ $page, $field ] = [ $f->hasPage, $f->hasField ];
		$values = $page->get($field->name);
		// clear value, ready for new questions
		if(count($values)) {
			// show last message
			$item = $values->last(); /** @var PageEngineerItem $item */
			$f->prependMarkup .= $item->markupValue();
		}
		$f->val(''); // ready for next prompt

		$modules = $this->wire()->modules;
		/** @var PageEngineerItem $lastItem */
		$lastItem = $values->last();

		// Show undo checkbox only when the last item is an agent response with a backup
		$hasLastEdit = $lastItem && $lastItem->isAgent && !empty((array) $lastItem->get('backupVersions'));

		$at = $this->wire('at'); /** @var AgentTools $at */
		$inputs = [];

		$agents = $at->getAgents();
		if(count($agents)) {
			/** @var InputfieldSelect $s */
			$s = $modules->get('InputfieldSelect');
			$s->attr('name', '_at_agent');
			$s->label = 'Agent/model';
			$s->addClass('uk-form-small');
			$s->required = true;
			foreach($agents->getArray() as $n => $agent) {
				$s->addOption($n, $agent->get('label|model'));
			}
			$val = (int) $this->wire->session->getFor($this, 'agent');
			$s->val($val);
			$inputs[] = $s->render();
		}

		if($hasLastEdit) {
			/** @var InputfieldCheckbox $c */
			$c = $modules->get('InputfieldCheckbox');
			$c->attr('name', '_at_undo');
			$c->label = $this->_('Undo last edit');
			$inputs[] = $c->render();
		}

		// Show reset checkbox when there is any conversation history
		$numMessages = count($values);
		if($numMessages) {
			/** @var InputfieldCheckbox $c */
			$c = $modules->get('InputfieldCheckbox');
			$c->attr('name', '_at_reset');
			$c->label = sprintf($this->_('Reset conversation (%d messages)'), $numMessages);
			$inputs[] = $c->render();
		}
		if(count($inputs)) {
			$f->addClass('InputfieldCheckbox', 'wrapClass');
			$f->appendMarkup .= '<p>' . implode('&nbsp; &nbsp;', $inputs) . '</p>';
		}

		// Identifying class for JS selector (added regardless of inputs)
		$f->addClass('PageEngineerInput', 'wrapClass');

		// Load assets once per page (guarded by static flag in case of multiple fields)
		static $assetsLoaded = false;
		if(!$assetsLoaded) {
			$assetsLoaded = true;
			$config = $this->wire()->config;
			$moduleUrl = $config->urls('FieldtypePageEngineer');
			$config->scripts->add($moduleUrl . 'PageEngineerField.js');
			$config->styles->add($moduleUrl . 'PageEngineerField.css');
			$config->js('FieldtypePageEngineer', [
				'processingText' => $this->_('Saving page and processing Engineer request…'),
				'timeoutText' => $this->_('Please be patient, this may take a minute. If you see a server error, the Engineer is still working — reload the page before resubmitting.'),
				'thinkingWords' => include(__DIR__ . '/words.php')
			]);
		}

		if(!$f->icon) $f->icon = 'commenting';
	}

	/**
	 * Undo last agent edit(s)
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param PageEngineerItems $values
	 *
	 */
	protected function undoLastAgentEdit(Page $page, Field $field, PageEngineerItems $values) {
		$modules = $this->wire()->modules;

		// Restore the backup versions created before the last agent edit
		$lastItem = $values->last();
		$backupVersions = $lastItem ? (array) $lastItem->get('backupVersions') : [];

		if(!empty($backupVersions) && $modules->isInstalled('PagesVersions')) {
			/** @var PagesVersions $pagesVersions */
			$pagesVersions = $modules->get('PagesVersions');
			$pages = $this->wire()->pages;
			foreach($backupVersions as $pageId => $versionData) {
				// Support both current format ['version' => int, 'names' => array] and legacy int format
				if(is_array($versionData)) {
					$version = (int) $versionData['version'];
					$names = (array) ($versionData['names'] ?? []);
				} else {
					$version = (int) $versionData;
					$names = [];
				}
				$p = $pages->get((int) $pageId);
				if(!$p->id) continue;
				$nameOptions = !empty($names) ? ['names' => $names] : [];
				if($p->id === $page->id) {
					// For the page currently being saved, copy versioned field values
					// back onto $page so the normal save flow restores them
					$versionPage = $pagesVersions->getPageVersion($p, $version, $nameOptions);
					if($versionPage->id) {
						foreach($p->template->fieldgroup as $vf) {
							if(!empty($names) && !in_array($vf->name, $names)) continue;
							$page->set($vf->name, $versionPage->get($vf->name));
							$page->trackChange($vf->name);
						}
					}
				} else {
					// For child pages, restore and save directly
					$pagesVersions->restorePageVersion($p, $version, $nameOptions);
				}
				$pagesVersions->deletePageVersion($p, $version);
			}
		}

		// Remove the last agent response and the user question that preceded it
		$lastAgent = $values->last();
		if($lastAgent && $lastAgent->isAgent) $values->remove($lastAgent);

		$lastUser = $values->last();
		if($lastUser && !$lastUser->isAgent) $values->remove($lastUser);

		$page->set($field->name, $values);
		$page->trackChange($field->name);
		$page->save();
	}

	/**
	 * Send a request to the agent and return the response as a new item
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param string $questionText
	 * @param PageEngineerItems $values
	 * @param AgentToolsAgent|null $agent Agent to use or omit for primary
	 * @return PageEngineerItem|null
	 *
	 */
	protected function sendAgentRequest(Page $page, Field $field, $questionText, PageEngineerItems $values, $agent = null) {

		if(!strlen($questionText)) return null;
		set_time_limit(600);

		/** @var AgentTools $at */
		$at = $this->wire('at');

		if(!$agent) $agent = $at->getPrimaryAgent();
		if(!$agent) {
			return $values->newItem(
				$this->_('AgentTools is not configured. Please add an AI agent in Setup > Agent Tools > Agents.'),
				'', true
			);
		}

		// Take PagesVersions backup before the agent runs, if enabled
		$backupVersions = $field->backup ? $this->backupPages($page, $field) : [];

		// Build conversation history from existing items, trimmed to configured memory limit
		$history = [];
		foreach($values->getArray() as $item) {
			/** @var PageEngineerItem $item */
			$history[] = [
				'role' => $item->isAgent ? 'assistant' : 'user',
				'content' => $item->text,
			];
		}
		$maxPairs = (int) $at->get('engineer_mem_qty') ?: 10;
		$maxEntries = $maxPairs * 2;
		if(count($history) > $maxEntries) $history = array_slice($history, -$maxEntries);

		// Allow eval_php (to query/edit) and api_docs (to look up API documentation)
		// Exclude save_migration and site_info — page context is already in the system prompt
		$allTools = $at->engineer->getToolDefinitions($agent->provider);
		$allowedTools = ['eval_php', 'api_docs'];
		$tools = array_values(array_filter($allTools, function($tool) use($allowedTools) {
			$name = isset($tool['name']) ? $tool['name'] : (isset($tool['function']['name']) ? $tool['function']['name'] : '');
			return in_array($name, $allowedTools);
		}));

		$result = $at->engineer->ask($questionText, [
			'systemPrompt' => $this->buildFieldSystemPrompt($page, $field),
			'tools' => $tools,
			'history' => $history,
			'provider' => $agent->provider,
			'apiKey' => $agent->apiKey,
			'model' => $agent->model,
			'endpoint' => $agent->endpointUrl,
		]);

		$responseText = $result['response'] ?: ($result['error'] ? $this->_('Error: ') . $result['error'] : $this->_('No response received.'));
		$item = $values->newItem($responseText, $agent->model ?: $agent->provider, true);

		if(!empty($backupVersions)) {
			$item->set('backupVersions', $backupVersions);
		}

		// Also surface the reply as a page-editor notice so it's visible regardless of field position
		$at->message($item->markupValue(), Notice::noGroup | Notice::allowMarkup);

		return $item;
	}

	/**
	 * Build the system prompt for the field's AI agent, including page context
	 *
	 * @param Page $page
	 * @param Field $field
	 * @return string
	 *
	 */
	protected function buildFieldSystemPrompt(Page $page, Field $field): string {
		/** @var AgentTools $at */
		$at = $this->wire('at');
		$scope = (int) $field->scope;
		$onlyFields = $field->onlyFields;
		$instructions = trim((string) $field->instructions);

		$prompt = "You are an AI assistant helping a content editor make changes to a ProcessWire page in the admin panel.\n\n";

		if($instructions) {
			$prompt .= "## Instructions\n$instructions\n\n";
		}

		// Page context
		$prompt .= "## Page Being Edited\n";
		$prompt .= "- **ID:** {$page->id}\n";
		$prompt .= "- **URL:** {$page->url}\n";
		$prompt .= "- **Template:** {$page->template->name}\n\n";

		// Field values on the page being edited (always shown for context)
		$prompt .= "## Current Field Values\n";
		foreach($page->template->fieldgroup as $f) {
			/** @var Field $f */
			$value = $page->getUnformatted($f->name);
			$prompt .= "- **{$f->name}** ({$f->type->shortName}): " . $this->fieldValueToString($value) . "\n";
		}
		$prompt .= "\n";

		// Child pages if in scope
		if($scope === PageEngineerField::scopePageAndChildren || $scope === PageEngineerField::scopeChildren) {
			$childPages = $page->children('include=all');
			if(count($childPages)) {
				$prompt .= "## Child Pages\n";
				foreach($childPages as $child) {
					/** @var Page $child */
					$prompt .= "- **ID {$child->id}:** {$child->title} (template: {$child->template->name}, url: {$child->url})\n";
				}
				$prompt .= "\n";
			}
		}

		// Field restrictions
		if(!empty($onlyFields)) {
			$fieldList = implode(', ', $onlyFields);
			$prompt .= "## Field Restrictions\n";
			$prompt .= "You may only modify these fields: $fieldList. Do not modify any other fields, even if the user requests it.\n\n";
		}

		// Rules
		$apiVars = $at->engineer->getEvalPhpVars(false);
		$prompt .= "## Rules\n";
		if($scope === PageEngineerField::scopeChildren) {
			$prompt .= "- You may only make changes to child pages. Do not modify the page being edited, even if asked.\n";
		}
		$prompt .= "- Before modifying any page or field, verify it is editable: \$page->editable() and \$page->fieldEditable('field_name').\n";
		$prompt .= "- Apply changes directly using eval_php. Do not create migration files.\n";
		$prompt .= "- After completing changes, save the page with \$page->save(), \$page->save('field_name'), \$pages->save(\$page) or \$pages->saveField(\$page, 'field_name').\n";
		$prompt .= "- If the user's request is ambiguous, ask one clarifying question before proceeding.\n";
		$prompt .= "- After changes are applied, briefly confirm what was done.\n";
		$prompt .= "- ProcessWire API variables available in eval_php: $apiVars.\n";

		return $prompt;
	}

	/**
	 * Take a PagesVersions backup of the page and any in-scope child pages
	 *
	 * @param Page $page
	 * @param Field $field
	 * @return array Map of page ID => ['version' => int, 'names' => array] for each page backed up
	 *
	 */
	protected function backupPages(Page $page, Field $field): array {
		$modules = $this->wire()->modules;
		if(!$modules->isInstalled('PagesVersions')) return [];
		/** @var PagesVersions $pagesVersions */
		$pagesVersions = $modules->get('PagesVersions');

		$scope = (int) $field->scope;
		$pagesToBackup = $scope === PageEngineerField::scopeChildren ? [] : [$page];

		if($scope === PageEngineerField::scopePageAndChildren || $scope === PageEngineerField::scopeChildren) {
			foreach($page->children('include=all') as $child) {
				$pagesToBackup[] = $child;
			}
		}

		$onlyFields = is_array($field->onlyFields) ? $field->onlyFields : [];

		$versions = [];
		foreach($pagesToBackup as $p) {
			if(!$pagesVersions->allowPageVersions($p)) continue;
			try {
				if(!empty($onlyFields)) {
					// PE field won't appear in onlyFields, so no special handling needed
					$names = $onlyFields;
				} else {
					// Back up all fields except Page Engineer fields — conversation history
					// is a log, not content, and should not be rolled back with the page
					$names = [];
					foreach($p->template->fieldgroup as $tf) {
						if(!($tf->type instanceof FieldtypePageEngineer)) $names[] = $tf->name;
					}
				}
				$options = ['description' => 'Page Engineer backup before AI edit'];
				if(!empty($names)) $options['names'] = $names;
				$version = $pagesVersions->addPageVersion($p, $options);
				if($version) $versions[$p->id] = ['version' => $version, 'names' => $names];
			} catch(\Throwable $e) {
				// Skip backup for this page silently; a failed snapshot is better than blocking the request
			}
		}

		return $versions;
	}

	/**
	 * Format a page field value as a short readable string for the system prompt
	 *
	 * @param mixed $value
	 * @return string
	 *
	 */
	protected function fieldValueToString($value): string {
		if($value === null) return '(null)';
		if(is_bool($value)) return $value ? 'true' : 'false';
		if(is_int($value) || is_float($value)) return (string) $value;
		if($value instanceof PageArray) return $value->count() . ' pages';
		if($value instanceof Page) return "Page #{$value->id}: {$value->title}";
		if($value instanceof WireArray) return $value->count() . ' items';
		if(is_array($value)) return json_encode($value);
		if(is_string($value)) {
			if(!strlen($value)) return '(empty)';
			return strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
		}
		return (string) $value;
	}

	/**
	 * Get database schema used by the Field
	 *
	 * @param Field $field
	 * @return array
	 *
	 */
	public function getDatabaseSchema(Field $field) {
		$schema = parent::getDatabaseSchema($field);
		$schema['data'] = 'mediumtext NOT NULL';
		$schema['keys']['data'] = 'FULLTEXT KEY data (data)';
		return $schema;
	}

	/**
	 * Get Inputfields to configure the Field
	 *
	 * @param Field $field
	 * @return InputfieldWrapper
	 *
	 */
	public function ___getConfigInputfields(Field $field) {
		$inputfields = parent::___getConfigInputfields($field);

		$inputfields->description = $this->_(
			'Only add this field to templates used by trusted editors. ' .
			'The Page Engineer has full ProcessWire API access and can read and modify any content the API allows.'
		);

		$f = $inputfields->InputfieldRadios;
		$f->attr('name', 'scope');
		$f->label = 'Limit scope for AI edits in page editor';
		$f->addOption(PageEngineerField::scopePage, 'Page being edited');
		$f->addOption(PageEngineerField::scopePageAndChildren, 'Page being edited + editable children');
		$f->addOption(PageEngineerField::scopeChildren, 'Editable children only (not the page being edited)');
		$f->val($field->scope);
		$inputfields->add($f);

		$f = $inputfields->InputfieldTextarea;
		$f->attr('name', 'instructions');
		$f->label = 'Instructions for AI agent that should accompany every request';
		$f->val($field->instructions);
		$inputfields->add($f);

		$f = $inputfields->InputfieldToggle;
		$f->attr('name', 'backup');
		$f->label = 'Backup changed fields to new version before applying changes?';
		$f->val((int) $field->backup);
		$inputfields->add($f);

		$f = $inputfields->InputfieldAsmSelect;
		$f->attr('name', 'onlyFields');
		$f->label = 'Limit scope of changes only these fields';
		$f->notes = 'If no fields selected then AI Engineer may modify any field requested by the page editor.';
		foreach($this->wire()->fields as $_field) {
			if($_field->name === $field->name) continue;
			$f->addOption($_field->name);
		}
		$f->val($field->onlyFields);
		$inputfields->add($f);

		return $inputfields;
	}
}
