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
			'version' => 8,
			'author' => 'Claude (Anthropic), GPT 5.5 Codex and Ryan Cramer',
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
				['url' => 'tasks/', 'label' => 'Tasks', 'icon' => 'tasks'],
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
	protected $thinkingWords = [];

	/**
	 * ProcessAgentTools helpers indexed by name
	 *
	 * @var array|ProcessAgentToolsHelper[]
	 *
	 */
	protected $helpers = [];

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
		$this->thinkingWords = include(__DIR__ . '/FieldtypePageEngineer/words.php');
		$this->wire()->config->js('AgentTools', [
			'processingText' => $this->_('Processing… this may take a minute or two'),
			'timeoutText' => $this->_("A response is now taking shape. If a server error appears, ask me about it on the next request and I can help you fix it."),
			'thinkingWords' => $this->thinkingWords,
			'formulas' => [ 'Hello ' . ucfirst($this->wire()->user->name) ],
		]);
		parent::init();
		$this->loadProcessingAssets();
	}

	/**
	 * Get a helper instance
	 *
	 * @param string $name Name of helper, i.e. "tasks"
	 * @return ProcessAgentToolsHelper|ProcessAgentToolsAgents|ProcessAgentToolsMigrations|ProcessAgentToolsTasks
	 * @throws WireException
	 *
	 */
	public function getHelper($name) {
		if(!ctype_alpha($name)) throw new WireException("Invalid helper name: $name");
		if(isset($this->helpers[$name])) return $this->helpers[$name];
		$class = 'ProcessAgentTools' . ucfirst($name);
		$file = __DIR__ . "/$class.php";
		if(!file_exists($file)) throw new WireException("Helper file not found: $file");
		require_once(__DIR__ . '/ProcessAgentToolsHelper.php');
		require_once($file);
		$class = __NAMESPACE__ . '\\' . $class;
		$this->helpers[$name] = new $class($this, $this->at);
		return $this->helpers[$name];
	}

	/**
	 * Load shared AgentTools processing overlay assets
	 *
	 */
	protected function loadProcessingAssets(): void {
		$moduleUrl = $this->wire()->config->urls($this);
		$this->wire()->config->scripts->add($moduleUrl . 'processing.js');
		$this->wire()->config->styles->add($moduleUrl . 'processing.css');
	}

	/**
	 * Return a translation label
	 *
	 * @param string $name
	 * @return string
	 *
	 */
	public function label($name) {
		switch($name) {
			case 'agent-tools': return $this->_('Agent Tools');
			case 'agents': return $this->_('Agents');
			case 'applied': return $this->ukLabel($this->_('Applied'), 'success');
			case 'ask-create-migration': return $this->_('Ask the engineer to create a migration');
			case 'back': return $this->_('Back');
			case 'date-time': return $this->_('Date/time');
			case 'edit': return $this->_('Edit');
			case 'engineer': return $this->_('Engineer');
			case 'export': return $this->_('Export');
			case 'export-checked': return $this->_('Export checked');
			case 'failed': return $this->ukLabel($this->_('Failed'), 'danger');
			case 'file': return $this->_('File');
			case 'import': return $this->_('Import');
			case 'migration': return $this->_('Migration');
			case 'migrations': return $this->_('Migrations');
			case 'pending': return $this->ukLabel($this->_('Pending'));
			case 'run': return $this->_('Run');
			case 'save': return $this->_('Save');
			case 'status': return $this->_('Status');
			case 'tasks': return $this->_('Tasks');
		}
		return $name;
	}

	/**
	 * Render a note about troubleshooting timeouts (html)
	 *
	 * @return string
	 *
	 */
	public function troubleshootingNote($prepend = '') {
		$prepend = wireIconMarkup('warning') . " $prepend ";
		return "<p class='detail'>$prepend" . sprintf(
			$this->_('If you get timeouts or a server error please see the %s section of the AgentTools documentation for how to fix it.'),
			'<a target="_blank" href="https://processwire.com/modules/agent-tools/#troubleshooting">' . $this->_('troubleshooting') . "</a>"
		) . "</p>";
	}

	/**
	 * Return an icon name for a given label/action
	 *
	 * @param string $name
	 * @return string
	 *
	 */
	public function iconName($name) {
		switch($name) {
			case 'agent-tools': return 'asterisk';
			case 'agents': return 'universal-access';
			case 'apply': return 'play';
			case 'applied': return 'check';
			case 'back': return 'arrow-left';
			case 'delete': return 'trash-o';
			case 'engineer': return 'commenting';
			case 'export': return 'share-square-o';
			case 'failed': return 'times';
			case 'import': return 'download';
			case 'migrations': return 'database';
			case 'tasks': return 'tasks';
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
	public function description($name) {
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
			case 'tasks': return
				$this->_('Run predefined tasks with the Engineer. Tasks can assist with security, accessibility, monitoring, and more.');
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
	public function ukLabel($label, $type = '') {
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
	public function pre($text, array $options = []) {
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

		$info = self::getModuleInfo();

		foreach($info['nav'] as $item) {
			/** @var InputfieldButton $btn */
			$name = trim($item['url'], '/');
			$label = $this->label($name);
			if(empty($label)) $label = $item['label'];
			$description = $this->description($name);
			$href = $adminUrl . "$name/";
			$btn = $modules->get('InputfieldButton');
			$btn->href = $href;
			$btn->icon = $item['icon'];
			$btn->val($label);
			$btn = $btn->render();
			$out .= "<h2 class='uk-margin-remove'>$label</h2><p>$description</p><p>$btn</p><hr />";
		}

		return $out;
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

		if($this->at->get('engineer_suspicious') === 'all' && $this->at->isUserSuspicious()) {
			$this->error($this->_('Engineer access is temporarily suspended due to a previous suspicious request.'));
			return '';
		}

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
		$f->addClass('at-engineer-request');
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
		$f->addClass('at-show-thinking');
		$f->icon = 'send';
		$f->val($this->_('Send'));
		$f->appendMarkup .= $this->renderThinkingWords();
		$form->add($f);

		$note = $this->troubleshootingNote($this->_('Some engineer requests can take a long time to execute.'));

		return $form->render() . $note;
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
			$f->addClass('at-engineer-request');
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
			$f->addClass('at-show-thinking');
			$f->val($this->_('Send reply'));
			$f->icon = 'send';
			$f->appendMarkup .= $this->renderThinkingWords();
			$replyForm->add($f);
			$replyFormOutput = $replyForm->render();
		}

		return $form->render() . $replyFormOutput;
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
	 * Select random thinking words
	 *
	 * @return string
	 *
	 */
	public function renderThinkingWords() {
		$qty = count($this->thinkingWords);
		$word1 = $this->thinkingWords[mt_rand(0, $qty-1)];
		do { $word2 = $this->thinkingWords[mt_rand(0, $qty-1)]; } while($word2 === $word1);
		return " <span id='thinking' hidden>$word1 and $word2</span>";
	}

	/**
	 * Format and prepare engineer response for output
	 *
	 * @param string $response
	 * @return string
	 *
	 */
	public function formatEngineerResponse($response) {

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

	/******************************************************************
	 * AGENTS METHODS
	 *
	 */

	/**
	 * Agents configuration
	 *
	 * @return string
	 *
	 */
	public function ___executeAgents() {
		return $this->getHelper('agents')->executeAgents();
	}


	/******************************************************************
	 * MIGRATIONS METHODS
	 *
	 */

	/**
	 * Migrations: show status table with apply button if pending
	 *
	 * @return string
	 *
	 */
	public function ___executeMigrations() {
		return $this->getHelper('migrations')->executeMigrations();
	}

	/**
	 * View migration: display the PHP source of a single migration file
	 *
	 * @return string
	 *
	 */
	public function ___executeViewMigration() {
		return $this->getHelper('migrations')->executeViewMigration();
	}

	/**
	 * Import migration bundle: show form (GET) or process paste (POST)
	 *
	 * @return string
	 *
	 */
	public function ___executeImportMigration(): string {
		return $this->getHelper('migrations')->executeImportMigration();
	}

	/******************************************************************
	 * TASKS METHODS
	 *
	 */

	/**
	 * List tasks
	 *
	 * @return string
	 *
	 */
	public function executeTasks() {
		return $this->getHelper('tasks')->executeTasks();
	}

	/**
	 * Run task
	 *
	 * @return string
	 *
	 */
	public function executeRunTask() {
		return $this->getHelper('tasks')->executeRunTask();
	}

	/**
	 * Reply to a task result
	 *
	 * @return string
	 * @todo Methods beginning with "execute" translate to a URL accessible action. If that's not the intention, the method should be named differently.
	 *
	 */
	public function executeReplyTask() {
		return $this->getHelper('tasks')->executeReplyTask();
	}

	/**
	 * Add or edit task
	 *
	 * @return string
	 *
	 */
	public function executeEditTask() {
		return $this->getHelper('tasks')->executeEditTask();
	}
}
