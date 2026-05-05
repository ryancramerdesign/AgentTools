<?php namespace ProcessWire;

/**
 * ProcessAgentTools
 *
 * Admin UI for AgentTools: view/apply migrations and ask the Engineer
 * (AI assistant) questions about or to make changes to the site.
 *
 * Copyright 2026 Ryan Cramer and Claude (Anthropic) | MIT
 *
 */
class ProcessAgentTools extends Process {

	public static function getModuleInfo() {
		return [
			'title' => 'Agent Tools',
			'summary' => 'Admin interface for AgentTools migrations and AI engineer.',
			'version' => 5,
			'author' => 'Claude (Anthropic) and Ryan Cramer',
			'icon' => 'at',
			'requires' => 'AgentTools',
			'page' => [
				'name' => 'agent-tools',
				'parent' => 'setup',
				'title' => 'Agent Tools',
			],
			'useNavJSON' => true,
			'nav' => [
				['url' => 'migrations/', 'label' => 'Migrations', 'icon' => 'database'],
				['url' => 'engineer/', 'label' => 'Engineer', 'icon' => 'commenting'],
				['url' => 'agents/', 'label' => 'Agents', 'icon' => 'universal-access'],
			],
		];
	}
	
	/**
	 * @var AgentTools|null 
	 * 
	 */
	protected $at = null;
	
	/**
	 * Words to indicate the Engineer is thinking
	 * 
	 * @var string[] 
	 * 
	 */
	protected $thinkingWords = [
		'Backwashing',
		'Bargaining',
		'Basting',
		'Coding',
		'Cogitating',
		'Deliberating',
		'Discombobulating',
		'Expediting',
		'Finagling',
		'Flibbertigibbeting',
		'Gallivanting',
		'Gesticulating',
		'Hemming',
		'Jawboning',
		'Kibbitzing',
		'Loitering',
		'Lollygagging',
		'Meandering',
		'Negotiating',
		'Nesting',
		'Outsourcing',
		'Percolating',
		'Ruminating',
		'Schmeering',
		'Schmoozing',
		'Schooling',
		'Scuttering',
		'Sensating',
		'Shilly-shallying',
		'Shrimping',
		'Shucking',
		'Siphoning',
		'Sizzling',
		'Skedaddling',
		'Skimming',
		'Snagging',
		'Solidifying',
		'Speculating',
		'Waffling',
		'Wrangling',
	];
	
	/**
	 * Require superuser for all actions
	 *
	 */
	public function init() {
		$this->at = $this->wire('at');
		if(!$this->wire()->user->isSuperuser()) throw new WirePermissionException("Superuser is required");
		if(!$this->at) {
			// not likely, but just in case as a fallback
			$this->at = $this->wire()->modules->getInstall('AgentTools');
			if(!$this->at) throw new WireException('This module requires the AgentTools module');
		}
		parent::init();
	}
	
	/**
	 * Return a translation label
	 * 
	 * @param string $name
	 * @return string
	 * 
	 */
	protected function label($name) {
		switch($name) {
			case 'agent-tools': return $this->_('Agent Tools');
			case 'agents': return $this->_('Agents'); 
			case 'applied': return $this->ukLabel($this->_('Applied'), 'success'); 
			case 'ask-create-migration': return $this->_('Ask the engineer to create a migration');
			case 'back': return $this->_('Back');
			case 'date-time': return $this->_('Date/time'); 
			case 'engineer': return $this->_('Engineer');
			case 'failed': return $this->ukLabel($this->_('Failed'), 'danger');
			case 'file': return $this->_('File'); 
			case 'migration': return $this->_('Migration');
			case 'migrations': return $this->_('Migrations');
			case 'pending': return $this->ukLabel($this->_('Pending')); 
			case 'status': return $this->_('Status'); 
		}
		return $name;	
	}
	
	/**
	 * Return an icon name for a given label/action
	 * 
	 * @param string $name
	 * @return string
	 * 
	 */
	protected function iconName($name) {
		switch($name) {
			case 'agent-tools': return 'asterisk';
			case 'agents': return 'universal-access';
			case 'apply': return 'play';
			case 'applied': return 'check';
			case 'back': return 'arrow-left';
			case 'delete': return 'trash-o';
			case 'engineer': return 'commenting';
			case 'failed': return 'times';
			case 'migrations': return 'database';
		}
		return 'question-circle';
	}
	
	/**
	 * Get a feature description
	 * 
	 * @param string $name
	 * @return string
	 * 
	 */
	protected function description($name) {
		switch($name) {
			case 'migrations': return 
				$this->_('Migrations are scripts that you can run to automatically make changes on your site.') . ' ' .
				$this->_('Use this tool to apply, view, create or delete migrations.');
			case 'engineer': return
				$this->_('Your site engineer can tell you everything there is to know about your ProcessWire installation.') . ' ' .
				$this->_('Engineer can make changes, perform web development tasks, create migrations, and more.') . ' ' .
				$this->_('Please be sure you have full backups of your site and database before asking Engineer to make changes to your site.');
			case 'agents': return 	
				$this->_('Manage all your agents in one place. Configure up to ten agents with models, API keys, and endpoint URLs.') . ' ' . 
				$this->_('Once configured, you can use any of your agents with the Engineer.'); 
		}
		return 'unknown description name';
	}
	
	/**
	 * Render a uk-label element
	 *
	 * @param string $label
	 * @param string $type One of 'success', 'danger', 'warning' or omit for default
	 * @return string
	 *
	 */
	protected function ukLabel($label, $type = '') {
		if($type) $type = " uk-label-$type";
		return "<span class='uk-label$type'>$label</span>";
	}
	
	/**
	 * Render a <pre> and entity encode text
	 * 
	 * @param string $text
	 * @param array $options
	 *  - `entityEncode` (bool): Entity encode given text? (default=true)
	 *  - `wordWrap` (bool): Word wrap long lines? (default=true)
	 * @return string
	 * 
	 */
	protected function pre($text, array $options = []) {
		$defaults = [
			'entityEncode' => true,
			'wordWrap' => true, 
		];
		$options = array_merge($defaults, $options);
		$text = trim($text);
		$style = "background-color: transparent;";
		if($options['entityEncode']) $text = htmlspecialchars(trim($text));
		if($options['wordWrap']) $style .= "white-space: pre-wrap;";
		return "<pre style='$style'>$text</pre>";
	}
	
	
	/**
	 * Landing page: links to Migrations and Engineer
	 *
	 * @return string
	 *
	 */
	public function ___execute() {
		$this->headline($this->label('agent-tools'));
		$modules = $this->wire()->modules;
		$adminUrl = $this->wire()->page->url;
		$out = '<hr>';
		
		$tools = [ 'migrations', 'engineer', 'agents' ];
		
		foreach($tools as $name) {
			/** @var InputfieldButton $btn */
			$label = $this->label($name);
			$description =  $this->description($name);
			$href = $adminUrl . "$name/";
			$btn = $modules->get('InputfieldButton');
			$btn->href = $href;
			$btn->icon = $this->iconName($name);
			$btn->val($label);
			$btn = $btn->render();
			$out .= "<h2 class='uk-margin-remove'>$label</h2><p>$description</p><p>$btn</p><hr />";
		}

		return $out;
	}

	/**
	 * Migrations: show status table with apply button if pending
	 *
	 * @return string
	 *
	 */
	public function ___executeMigrations() {
		$input = $this->wire()->input;
		
		if($input->requestMethod('post')) {
			$this->wire()->session->CSRF()->validate();
		}

		if($input->post('submit_apply')) {
			return $this->processApply();
		}

		if($input->post('submit_apply_checked')) {
			return $this->processApplyChecked();
		}

		if($input->post('submit_delete_checked')) {
			$this->processDeleteChecked();
		}

		return $this->renderStatus();
	}

	/**
	 * Engineer: AI assistant for informational queries and site changes
	 *
	 * @return string
	 *
	 */
	public function ___executeEngineer() {
		$input = $this->wire()->input;
		$session = $this->wire()->session;
		
		$this->headline($this->label('engineer'));

		if($input->post('submit_engineer')) {
			$session->CSRF()->validate();
			return $this->processEngineerRequest();
		}

		$prefill = '';
		$forMigration = (bool) $input->get('migration');

		if($input->get('modify')) {
			$prefill = (string) $session->get('at_engineer_prefill');
			$session->remove('at_engineer_prefill');
		}

		return $this->renderEngineerForm($prefill, $forMigration);
	}

	/**
	 * Render the Engineer request form
	 *
	 * @param string $prefill Optional text to pre-fill the textarea
	 * @param bool $forMigration Did user arrive here from the "add migration" action? (default=false)
	 * @return string
	 *
	 */
	protected function renderEngineerForm(string $prefill = '', bool $forMigration = false): string {
		if(!$this->at->getPrimaryAgent()) {
			$agentsUrl = $this->wire()->page->url . 'agents/';
			$this->error(sprintf(
				$this->_('At least one agent must be configured. Please configure one in [Agents](%s).'),
				$agentsUrl
			), Notice::allowMarkdown);
			return '';
		}

		// Load persisted Control room preferences
		$meta = $this->wire()->user->meta('AgentTools') ?: [];
		$savedModelIndex = (string) ($meta['engineer_model_index'] ?? '0');
		$savedMemory = ($meta['engineer_memory'] ?? 'yes') === 'no' ? 'no' : 'yes';
		$history = $this->wire()->session->get('at_engineer_history') ?: [];
		$historyKb = $history ? round(strlen(json_encode($history)) / 1024, 1) : 0;

		/** @var InputfieldForm $form */
		$form = $this->wire()->modules->get('InputfieldForm');
		$form->attr('method', 'post');

		$f = $form->InputfieldTextarea;
		$f->attr('name', 'engineer_request');
		$f->icon = 'commenting';
		$f->attr('rows', 5);
		$f->val($prefill);

		if($forMigration) {
			$f->label = $this->_('Ask the site engineer to create a new migration');
			$f->attr('placeholder', $this->_('Example: Create a Text field named summary with the label Summary and add it to the basic-page template.'));
			$this->message($this->_('Example: Create a Text field named summary with the label Summary and add it to the basic-page template.'));
		} else {
			$f->label = $this->_('Ask the site engineer');
			$f->description =
				$this->_('Ask a question about your site, or request a change.') . ' ' .
				$this->_('Changes are saved as migration files for your review before being applied.');
			$f->detail = $this->description('engineer');
		}

		$form->add($f);

		if($savedMemory === 'yes' && !empty($history)) {
			$pairs = (int) (count($history) / 2);
			$f = $form->InputfieldMarkup;
			$f->label = sprintf(
				$this->_n('Conversation history (%d exchange)', 'Conversation history (%d exchanges)', $pairs),
				$pairs
			);
			$f->icon = 'history';
			$f->collapsed = Inputfield::collapsedYes;
			$historyOut = '';
			foreach(array_chunk($history, 2) as $pair) {
				if(isset($pair[0])) $historyOut .= '<blockquote><p>' . $this->wire()->sanitizer->entities($pair[0]['content']) . '</p></blockquote>';
				if(isset($pair[1])) $historyOut .= $this->formatEngineerResponse($pair[1]['content']);
			}
			$f->val($historyOut);
			$form->add($f);
		}

		$availableModels = $this->at->engineer->getAvailableModels();
		$modelLabel = isset($availableModels[(int) $savedModelIndex]) ? $availableModels[(int) $savedModelIndex]['label'] : 'Default';
		$memoryLabel = $savedMemory === 'yes' ? 'On' : 'Off';

		$fs = $form->InputfieldFieldset;
		$fs->label = "Control room — $modelLabel · Memory: $memoryLabel";
		$fs->icon = 'sliders';
		$fs->collapsed = Inputfield::collapsedYes;
		$form->add($fs);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'engineer_model_index');
		$f->label = $this->_('Model');
		foreach($availableModels as $index => $entry) {
			$f->addOption((string) $index, $entry['label']);
		}
		$f->val($savedModelIndex);
		$fs->add($f);

		$f = $form->InputfieldRadios;
		$f->attr('name', 'engineer_memory');
		$f->label = $this->_('Conversation history');
		$f->addOption('yes', $this->_('Yes: remember this conversation'));
		$f->addOption('no', $this->_('No: each request is independent'));
		$f->val($savedMemory);
		$f->optionColumns = 1;
		$f->detail = $this->_('When enabled, prior exchanges in this session are included with each request so the Engineer can refer back to them.');
		if($savedMemory === 'yes' && $historyKb > 0) {
			$f->appendMarkup = '<p class="uk-margin-small-top">' .
				'<label><input type="checkbox" class="uk-checkbox" name="engineer_memory_reset" value="1"> ' .
				sprintf($this->_('Reset conversation history (%s kb)'), $historyKb) .
				'</label></p>';
		}
		$fs->add($f);

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_engineer');
		$f->icon = 'send';
		$f->val($this->_('Send'));
		$f->appendMarkup .= $this->renderThinkingWords();
		$form->add($f);

		return $form->render();
	}

	/**
	 * Process an Engineer request and render the response
	 *
	 * @return string
	 *
	 */
	protected function processEngineerRequest(): string {
		
		$session = $this->wire()->session; 
		$sanitizer = $this->wire()->sanitizer;
		
		$input = $this->wire()->input;
		$request = trim((string) $input->post('engineer_request'));

		if(!strlen($request)) {
			$session->error($this->_('Please enter a request.'));
			$session->location($this->wire()->page->url . 'engineer/');
		}

		// Build options from Control room selections
		$options = [];
		$modelIndex = (int) $input->post('engineer_model_index');
		$availableModels = $this->at->engineer->getAvailableModels();
		if(isset($availableModels[$modelIndex])) {
			$entry = $availableModels[$modelIndex];
			$options['model'] = $entry['model'];
			$options['provider'] = $entry['provider'];
			$options['apiKey'] = $entry['key'];
			$options['endpoint'] = $entry['endpoint'];
		}

		// Handle conversation memory
		$memory = (string) $input->post('engineer_memory') === 'yes' ? 'yes' : 'no';
		$memoryReset = (bool) $input->post('engineer_memory_reset');
		$session = $this->wire()->session;
		if($memoryReset) $session->remove('at_engineer_history');
		if($memory === 'yes') {
			$options['history'] = $session->get('at_engineer_history') ?: [];
		}

		// Persist Control room selections per user
		$user = $this->wire()->user;
		$meta = $user->meta('AgentTools') ?: [];
		$meta['engineer_model_index'] = $modelIndex;
		$meta['engineer_memory'] = $memory;
		$user->meta('AgentTools', $meta);

		/** @var InputfieldForm $form */
		$form = $this->wire()->modules->get('InputfieldForm');

		$result = $this->at->engineer->ask($request, $options);

		// Save updated history to session if memory is enabled and request succeeded
		if($memory === 'yes' && !$result['error'] && $result['history']) {
			$session->set('at_engineer_history', $result['history']);
		}

		$out = "<blockquote><p>" . $sanitizer->entities($request) . "</p></blockquote>";

		if($result['error']) {
			$this->error($result['error']);
		}

		if($result['response']) {
			$out .= $this->formatEngineerResponse($result['response']);
		}

		$session->set('at_engineer_prefill', $request);
	
		$adminUrl = $this->wire()->page->url;

		if($result['migration']) {
			$filename = basename($result['migration']);
			$this->message(sprintf($this->_('Migration saved: %s'), $filename));
			$btn = $form->InputfieldButton;
			$btn->href = $adminUrl . 'view-migration/?name=' . urlencode($filename);
			$btn->icon = 'database';
			$btn->val($this->_('Review and apply migration'));
			$btn->showInHeader(true);
			$form->add($btn);
		}

		$btn = $form->InputfieldButton;
		$btn->href = $adminUrl . 'engineer/';
		$btn->icon = 'arrow-left';
		$btn->val($this->_('Ask another question'));
		if($result['migration']) {
			$btn->setSecondary();
		} else {
			$btn->showInHeader(true);
		}
		$form->add($btn);

		$btn = $form->InputfieldButton;
		$btn->href = $adminUrl . 'engineer/?modify=1';
		$btn->icon = 'edit';
		$btn->val($this->_('Modify my question'));
		$btn->setSecondary();
		$form->add($btn);

		$form->val($out);

		$replyFormOutput = '';
		if($memory === 'yes' && !$result['error']) {
			/** @var InputfieldForm $replyForm */
			$replyForm = $this->wire()->modules->get('InputfieldForm');
			$replyForm->attr('method', 'post');
			$replyForm->attr('action', $adminUrl . 'engineer/');

			$f = $replyForm->InputfieldTextarea;
			$f->attr('name', 'engineer_request');
			$f->label = $this->_('Reply');
			$f->icon = 'commenting';
			$f->attr('rows', 3);
			$replyForm->add($f);

			// Preserve Control room settings as hidden fields
			foreach([
				'engineer_model_index' => $modelIndex,
				'engineer_memory' => 'yes',
			] as $hiddenName => $hiddenValue) {
				$f = $replyForm->InputfieldHidden;
				$f->attr('name', $hiddenName);
				$f->attr('value', (string) $hiddenValue);
				$replyForm->add($f);
			}

			$f = $replyForm->InputfieldSubmit;
			$f->attr('name', 'submit_engineer');
			$f->val($this->_('Send reply'));
			$f->icon = 'send';
			$f->appendMarkup .= $this->renderThinkingWords();
			$replyForm->add($f);
			$replyFormOutput = $replyForm->render();
		}

		return $form->render() . $replyFormOutput;
	}
	
	/**
	 * Agents configuration 
	 * 
	 * @return string
	 * 
	 */
	public function ___executeAgents() {
		$modules = $this->wire()->modules;
		$at = $this->at;
		$maxAgents = 10;
		$form = $modules->get('InputfieldForm'); /** @var InputfieldForm $form */
		$agents = $at->getAgents();
		$dataLists = include(__DIR__ . '/datalists.php');
		
		$this->headline('Agents configuration');
		
		$labels = [
			'model' => 'Model ID',
			'label' => 'Optional label',
			'apiKey' => 'API key',
			'endpointUrl' => 'Endpoint URL',
		];
		
		$engineerKeys = [
			'engineer_model' => 'model', 
			'engineer_label' => 'label',
			'engineer_api_key' => 'apiKey',
			'engineer_endpoint' => 'endpointUrl',
		];

		$headerActions = [
			'apiKey' => [
				'onIcon' => 'toggle-on',
				'onEvent' => 'at-apikey-show',
				'onTooltip' => 'Hide API key',
				'offIcon' => 'toggle-off',
				'offEvent' => 'at-apikey-hide',
				'offTooltip' => 'Show API key',
			],
		];
		
		for($n = 1; $n <= $maxAgents; $n++) {
			
			$fs = $form->InputfieldFieldset;
			$fs->label = "Agent $n" . ($n === 1 ? ' (Primary)' : '');
			$fs->themeOffset = 1;
			$fs->collapsed = Inputfield::collapsedBlank;
			$form->add($fs);
			$agent = $agents->eq($n-1);
			
			foreach($labels as $name => $label) {
				$f = $form->InputfieldText;
				$f->attr('name', "$name$n");
				$f->label = $label;
				$f->columnWidth = 25;
				if($agent) $f->val($agent->get($name));
				if($name === 'apiKey') $f->attr('type', 'password');
				$fs->add($f);
			
				if(isset($headerActions[$name])) {
					$f->wrapClass("at-$name");
					$f->addHeaderAction($headerActions[$name]);
				}
				
				if(!isset($dataLists[$name])) continue;
				
				$f->attr('list', "$name-examples"); 
				$examples = $dataLists[$name];
				if(!count($examples)) continue; // already rendered the datalist
				$o = '';
				
				foreach($examples as $label => $example) {
					$o .= "<option value='$example' label='$label'>";
				}
				
				$f->appendMarkup = "<datalist id='$name-examples'>$o</datalist>";
				$dataLists[$name] = []; // ensure we render it only once
			}
		}
		
		$submit = $form->InputfieldSubmit;
		$submit->attr('name', 'submit_agents');
		$submit->value = $this->_('Save');
		$submit->showInHeader();
		$form->add($submit);
		
		if(!$form->isSubmitted($submit)) return $form->render();
		
		$form->processInput($this->wire()->input->post);
		$agents = new AgentToolsAgents();
		
		for($n = 1; $n <= $maxAgents; $n++) {
			$agent = new AgentToolsAgent();
			foreach($labels as $name => $label) {
				$agent->set($name, $form->getValueByName("$name$n"));
			}
			if($agent->model || $agent->apiKey || $agent->endpointUrl) $agents->add($agent);
		}
		
		$data = $modules->getConfig('AgentTools');
		$agent = $agents->first(); /** @var AgentToolsAgent $agent */
	
		// save settings (keeping legacy settings for now)
		foreach($engineerKeys as $key => $prop) {
			$data[$key] = $agent->get($prop);
		}
		$data['engineer_additional_models'] = $agents->getString();
		$modules->saveConfig($at, $data);
		$this->message('Saved agents');
		
		if(count($form->getErrors())) return $form->render();
		
		$this->wire()->session->location('./');
	}
	
	/**
	 * Does given text contain markdown-looking elements?
	 * 
	 * Note that detecting markdown is a best guess, not an absolute. 
	 * 
	 * @param string $text
	 * @param array $options
	 *  - `quick` (bool): Exit on first found markdown-like element rather than checking all (default=false)
	 *  - `verbose` (bool): Get array with [ 'name' => qty ] of found elements? (default=false)
	 * @return int|array Returns score of 0 if no markdown found, 1+ with number of markdown-like elements found,
	 *  or if the 'verbose' option is specified, an array is always returned. 
	 * 
	 */
	protected function isMarkdown($text, array $options = []) {
		$defaults = [ 'quick' => false, 'verbose' => false ];
		$options = array_merge($defaults, $options);
		$score = 0;
		$names = [];
		$patterns = [
			'ul-li' => "\n- ",
			'ul*li' => "\n* ", 
			'bold' => '**', 
			'link' => "](",
			'headline' => '/^#{1,6}\s/m',
			'inline-code' => '/`[^`]+`/m',
			'table-row' => '/^\s*\|.+\|\s*$/m',
			'code-block' => '/\n[~`]{3,}.+?\n[~`]{3,}/s',
		];
		foreach($patterns as $name => $pattern) {
			if(strpos($pattern, '/') === 0) { // regex
				$n = (int) preg_match_all($pattern, $text);
			} else {
				$n = substr_count($text, $pattern);
			}
			if($n) {
				$score += $n;
				$names[$name] = $n;
			}
			if($options['quick'] && $score > 0) break;
		}
		return $options['verbose'] ? $names : $score;
	}
	
	/**
	 * Format and prepare engineer response for output
	 * 
	 * @param string $response
	 * @return string 
	 * 
	 */
	protected function formatEngineerResponse($response) {
		
		if(strpos($response, '&') !== false) {
			$response = $this->wire()->sanitizer->unentities($response);
		}
		
		$markdown = null;
		if($this->isMarkdown($response)) {
			$markdown = $this->wire()->modules->get('TextformatterMarkdownExtra');
		}
		
		if($markdown) {
			// markdown
			$response = $markdown->markdown($response);
		} else if(strpos($response, '   ') !== false) {
			// preformatted text
			$response = $this->pre($response); 
		} else {
			// regular text	
			$response = "<p>" . nl2br(htmlspecialchars($response)) . "</p>";
		}
		
		$findReplace = [
			'<table>' => '<table class="uk-table uk-table-divider uk-table-small">', 
		];
		
		$response = str_replace(array_keys($findReplace), array_values($findReplace), $response);
		
		return $response;
	}
	
	/**
	 * View migration: display the PHP source of a single migration file
	 *
	 * @return string
	 *
	 */
	public function ___executeViewMigration() {
		
		$modules = $this->wire()->modules;
		$session = $this->wire()->session;
		$input = $this->wire()->input;
		$name = basename((string) $input->get('name'));

		// Validate: must match migration filename pattern
		if(!preg_match('/^\d{14}_[\w-]+\.php$/', $name)) {
			$session->error($this->_('Invalid migration name.'));
			$session->location('../migrations/');
		}

		$file = $this->at->getFilesPath('migrations') . $name;

		if(!is_file($file)) {
			$session->error($this->_('Migration file not found.'));
			$session->location('../migrations/');
		}

		$applied = $this->at->migrations->isApplied($file);
		$dateTime = $this->at->migrations->getDatetime($file);
		$title = $this->at->migrations->getTitle($file);
		
		$this->headline(sprintf($this->_('Migration: %s'), $title));
		$this->breadcrumb('../migrations/', $this->label('migrations'));
		
		// Handle apply POST
		if($input->post('submit_apply')) {
			$session->CSRF()->validate();
			return $this->runMigrationFiles([$file], '?name=' . urlencode($name));
		}
		
		$status = $this->label($applied ? 'applied' : 'pending');
	
		/** @var MarkupAdminDataTable Table */
		$table = $modules->get('MarkupAdminDataTable');
		$table->addClass('uk-margin-remove-bottom');
		$table->setEncodeEntities(false);
		$table->row([ $this->_('Status'), $status ]);
		$table->row([ $this->_('Date'), $dateTime ]);
		$table->row([ $this->_('File'), htmlspecialchars($file) ]);

		$form = $modules->get('InputfieldForm');
		$form->attr('method', 'post');
		$form->action('./?name=' . urlencode($name));
		
		$summary = $this->at->migrations->getSummary($file);
		if($summary) {
			$f = $form->InputfieldMarkup;
			$f->label = $this->_('Summary');
			$f->icon = 'commenting';
			$f->val($this->formatEngineerResponse($summary));
			$form->add($f);
		}

		$f = $form->InputfieldMarkup;
		$f->label = $this->_('Migration code');
		$f->icon = 'code';
		$f->val($this->pre(file_get_contents($file), [ 'wordWrap' => false ]));
		$form->add($f);
		
		$btn = $form->InputfieldButton;
		$btn->href = '../migrations/';
		$btn->icon = $this->iconName('back');
		$btn->val($this->label('back'));
		$btn->setSecondary();
		$form->add($btn);
		
		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_apply');
		$f->showInHeader(true);
		$f->icon = $applied ? 'refresh' : $this->iconName('apply');
		$f->val($applied ? 
			$this->_('Re-apply migration') : 
			$this->_('Apply migration')
		);
		$form->add($f);

		return
			$table->render() .
			$form->render();	
	}
	
	/**
	 * Render migration status table
	 *
	 * @return string
	 *
	 */
	protected function renderStatus() {
		$modules = $this->wire()->modules;
		
		$migrationsDir = $this->at->getFilesPath('migrations');
		$migrationFiles = $this->at->migrations->getFiles($migrationsDir);

		$this->headline($this->label('migrations'));

		if(empty($migrationFiles)) {
			$this->warning($this->_('No migration files found in:') . " `$migrationsDir`", Notice::allowMarkdown); 
			/** @var InputfieldButton $button */
			$button = $modules->get('InputfieldButton');
			$button->href = '../engineer/?migration=1';
			$button->value = $this->label('ask-create-migration');
			$button->icon = 'commenting';
			return $button->render();
		}

		$pendingCount = 0;
		foreach($migrationFiles as $file) {
			if(!$this->at->migrations->isApplied($file)) $pendingCount++;
		}

		/** @var MarkupAdminDataTable $table */
		$table = $modules->get('MarkupAdminDataTable');
		$table->setEncodeEntities(false);
		$table->setColNotSortable(0);
		$table->headerRow([
			'',
			$this->label('migration'),
			$this->label('date-time'),
			$this->label('status'),
		]);

		foreach($migrationFiles as $file) {
			$applied = $this->at->migrations->isApplied($file);
			$status = $this->label($applied ? 'applied' : 'pending');
			$basename = basename($file);
			$viewUrl = $this->wire()->page->url . 'view-migration/?name=' . urlencode($basename);
			$checkbox = "<input type='checkbox' name='migrations[]' class='uk-checkbox migration-checkbox' value='" . htmlspecialchars($basename) . "'>";
			$table->row([
				$checkbox,
				"<a href='$viewUrl'>" . htmlspecialchars($this->at->migrations->getTitle($file)) . "</a>",
				$this->at->migrations->getDatetime($file),
				$status,
			]);
		}

		$appliedCount = count($migrationFiles) - $pendingCount;
		$this->message(sprintf($this->_('%d applied, %d pending'), $appliedCount, $pendingCount));

		// Pass confirmation message and overlay text to JS
		$this->wire()->config->js('AgentTools', [
			'confirmDelete' => $this->_('Are you sure you want to delete the checked migration files? This cannot be undone.'),
			'processingText' => $this->_('Still processing… this may take up to 30 seconds.'),
			'timeoutText' => $this->_('If you see a server error, reload the page before resubmitting — your changes may already have been applied.'),
		]);

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');

		// Wrap table and action buttons in a single form so checkboxes are submitted
		$form->val($table->render());

		if($pendingCount > 0) {
			$label = sprintf(
				$this->_n('Apply %d pending migration', 'Apply %d pending migrations', $pendingCount),
				$pendingCount
			);
			/** @var InputfieldSubmit $f */
			$f = $modules->get('InputfieldSubmit');
			$f->attr('name', 'submit_apply');
			$f->icon = $this->iconName('apply'); 
			$f->val($label);
			$form->add($f);
		}

		/** @var InputfieldSubmit $f */
		$f = $modules->get('InputfieldSubmit');
		$f->attr('name', 'submit_apply_checked');
		$f->attr('id', 'submit_apply_checked');
		$f->icon = 'check';
		$f->val($this->_('Apply checked'));
		$f->setSecondary();
		$f->attr('hidden', 'hidden');
		$form->add($f);

		/** @var InputfieldSubmit $f */
		$f = $modules->get('InputfieldSubmit');
		$f->attr('name', 'submit_delete_checked');
		$f->attr('id', 'submit_delete_checked');
		$f->icon = $this->iconName('delete');
		$f->val($this->_('Delete checked'));
		$f->setSecondary();
		$f->attr('hidden', 'hidden');
		$form->add($f);

		/** @var InputfieldButton $btn */
		$btn = $modules->get('InputfieldButton');
		$btn->href = '../engineer/?migration=1';
		$btn->icon = $this->iconName('engineer');
		$btn->val($this->_('New migration'));
		$btn->setSecondary();
		$form->add($btn);

		return $form->render();
	}

	/**
	 * Apply pending migrations and render output
	 *
	 * @return string
	 *
	 */
	protected function processApply() {
		$migrationsDir = $this->at->getFilesPath('migrations');
		$migrationFiles = $this->at->migrations->getFiles($migrationsDir);

		$this->headline($this->_('Apply Migrations'));

		$pending = [];
		foreach($migrationFiles as $file) {
			if(!$this->at->migrations->isApplied($file)) $pending[] = $file;
		}

		if(empty($pending)) {
			$session = $this->wire()->session;
			$session->message($this->_('All migrations are already applied.'));
			$session->location('./');
		}

		return $this->runMigrationFiles($pending, './');
	}

	/**
	 * Get and validate checked migration files from POST, returning full paths
	 *
	 * @return array|null Array of full file paths, or null if none checked
	 *
	 */
	protected function getCheckedMigrations(): ?array {
		$names = $this->wire()->input->post('migrations');
		if(empty($names) || !is_array($names)) return null;

		$migrationsDir = $this->at->getFilesPath('migrations');
		$files = [];

		foreach($names as $name) {
			$name = basename((string) $name);
			if(!preg_match('/^\d{14}_[\w-]+\.php$/', $name)) continue;
			$file = $migrationsDir . $name;
			if(is_file($file)) $files[] = $file;
		}

		return empty($files) ? null : $files;
	}

	/**
	 * Apply checked migrations (pending or already applied)
	 *
	 * @return string
	 *
	 */
	protected function processApplyChecked(): string {
		$this->headline($this->_('Apply checked migrations'));
		$this->breadcrumb('../migrations/', $this->label('migrations'));

		$checked = $this->getCheckedMigrations();
		if(!$checked) {
			$session = $this->wire()->session;
			$session->warning($this->_('No migrations were checked.'));
			$session->location('./');
		}

		// Sort chronologically by filename timestamp
		sort($checked);

		return $this->runMigrationFiles($checked, './');
	}

	/**
	 * Execute migration files and render results
	 *
	 * Shared by processApply(), processApplyChecked(), and the single-migration
	 * apply on the view migration screen.
	 *
	 * @param array $migrationFiles Full file paths to run, in order
	 * @param string $backUrl URL for the "back" button (relative or absolute)
	 * @return string
	 *
	 */
	protected function runMigrationFiles(array $migrationFiles, string $backUrl): string {
		extract($this->wire()->fuel->getArray()); // note: this overwrites $files, if used

		$results = [];
		$passCount = 0;
		$failFile = null;

		foreach($migrationFiles as $file) {
			ob_start();
			try {
				include($file);
				$fileOutput = ob_get_clean();
				$this->at->migrations->addApplied($file);
				$passCount++;
				$results[] = [
					'file' => basename($file), 
					'output' => $fileOutput, 
					'success' => true
				];
			} catch(\Throwable $e) {
				$fileOutput = ob_get_clean();
				$results[] = [
					'file' => basename($file),
					'output' => trim($fileOutput) .
						"\nERROR: " . $e->getMessage() .
						"\n  File: " . $e->getFile() . " line " . $e->getLine(),
					'success' => false,
				];
				$failFile = basename($file);
				break;
			}
		}

		// Regenerate site-maps so they reflect the changes for future Engineer requests
		if($passCount > 0) {
			try {
				$this->at->sitemap->generate();
				$this->at->sitemap->generateSchema();
			} catch(\Throwable $e) {
				// Non-fatal: migration applied successfully even if sitemap update fails
			}
		}

		$out = '';

		foreach($results as $result) {
			if($result['success']) {
				$icon = wireIconMarkup($this->iconName('applied'), 'fw');
				$label = $this->label('applied');
			} else {
				$icon = wireIconMarkup($this->iconName('failed'), 'fw');
				$label = $this->label('failed');
			}
			$file = htmlspecialchars($result['file']);
			$out .= "<h3>$icon $file $label</h3>";
			if(strlen(trim($result['output']))) {
				$out .= $this->pre($result['output']); 
			}
		}

		if($failFile) {
			$this->error(sprintf($this->_('Stopped at: %s'), $failFile));
			$remaining = count($migrationFiles) - $passCount - 1;
			if($remaining > 0) {
				$this->warning(sprintf(
					$this->_('%d migration(s) applied. %d remaining migration(s) were NOT applied.'),
					$passCount,
					$remaining
				));
			}
		} else {
			$this->message(sprintf(
				$this->_n('Applied %d migration.', 'Applied %d migrations.', $passCount),
				$passCount
			));
		}

		/** @var InputfieldButton $btn */
		$btn = $this->wire()->modules->get('InputfieldButton');
		$btn->href = $backUrl;
		$btn->icon = 'arrow-left';
		$btn->val($this->_('Back'));
		$btn->setSecondary();
		$out .= $btn->render();

		return $out;
	}

	/**
	 * Delete checked migration files and remove from applied registry
	 *
	 */
	protected function processDeleteChecked() {
		$session = $this->wire()->session;
		
		$checked = $this->getCheckedMigrations();
		if(!$checked) {
			$session->warning($this->_('No migrations were checked.'));
			$session->location('./');
		}

		$deleteCount = 0;
		foreach($checked as $file) {
			$this->at->migrations->removeApplied($file);
			if($this->wire()->files->unlink($file)) $deleteCount++;
		}

		$session->message(sprintf(
			$this->_n('Deleted %d migration file.', 'Deleted %d migration files.', $deleteCount),
			$deleteCount
		));

		$session->location('./');
	}
	
	/**
	 * Select random thinking words 
	 * 
	 * @return string
	 * 
	 */
	protected function renderThinkingWords() {
		$qty = count($this->thinkingWords);
		$word1 = $this->thinkingWords[mt_rand(0, $qty-1)];
		do { $word2 = $this->thinkingWords[mt_rand(0, $qty-1)]; } while($word2 === $word1);
		return " <span id='thinking' hidden>$word1 and $word2</span>";
	}
}
