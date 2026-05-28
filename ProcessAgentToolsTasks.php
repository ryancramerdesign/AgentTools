<?php namespace ProcessWire;

/**
 * ProcessAgentTools Tasks Helper
 *
 */
class ProcessAgentToolsTasks extends ProcessAgentToolsHelper {

	/**
	 * Render troubleshooting note
	 *
	 * @param string $prepend
	 * @return string
	 *
	 */
	public function troubleshootingNote($prepend = '') {
		$prepend = trim("$prepend " . $this->_('Some tasks can take a long time to execute.'));
		return $this->pat->troubleshootingNote($prepend);
	}

	/**
	 * List tasks
	 *
	 * @return string
	 *
	 */
	public function executeTasks() {
		$modules = $this->wire()->modules;
		$form = new InputfieldWrapper();
		$form->attr('id', 'tasks-list');
		$tabs = $modules->get('JqueryWireTabs');
		$tabsItems = [];

		$tasks = [
			'Built-in' => [],
			'Custom' => [],
		];

		foreach($this->at->getTasks() as $task) {
			/** @var AgentToolsTask $task */
			$key = $task->builtIn ? 'Built-in' : 'Custom';
			$tasks[$key][] = $task;
		}

		foreach($tasks as $name => $items) {
			$table = $this->buildTasksTable($items);
			$tabsItems[$name] = $table->render();
		}

		/** @var InputfieldButton $f */
		$f = $modules->get('InputfieldButton');
		$f->href = '../edit-task/';
		$f->val($this->_('Add Task'));
		$f->icon = 'plus-circle';
		$f->showInHeader(true);

		$note = $this->troubleshootingNote();

		return $tabs->render($tabsItems) . $f->render() . $note;
	}

	/**
	 * Build a tasks table
	 *
	 * @param array|AgentToolsTask[] $tasks
	 *
	 */
	protected function buildTasksTable(array $tasks) {
		$modules = $this->wire()->modules;

		$table = $modules->get('MarkupAdminDataTable'); /** @var MarkupAdminDataTable $table */
		$table->setEncodeEntities(false);
		$table->headerRow([
			$this->_('Title'),
			$this->_('Summary'),
		]);

		$qty = 0;

		foreach($tasks as $task) {
			if(!$task->admin) continue;
			$icon = $task->icon ? wireIconMarkup($task->icon, 'fw') : '';
			$title = htmlspecialchars($task->title);
			if($icon) $title = "$icon $title";
			$taskName = rawurlencode($task->name);
			$url = $task->builtIn ? "../run-task/$taskName/" : "../edit-task/$taskName/?run=1";
			$table->row([
				"<a href='$url'>$title</a>",
				htmlspecialchars($task->summary),
			]);
			$qty++;
		}

		if(!$qty) {
			$table->row([ 'No tasks found' ], [ 'colspan' => 3 ]);
		}

		return $table;
	}

	/**
	 * Run task
	 *
	 * @return string
	 *
	 */
	public function executeRunTask() {
		$session = $this->wire()->session;
		$input = $this->wire()->input;
		$name = $input->urlSegment(2);
		$task = $this->at->getTasks()->getTask($name);
		$parentUrl = $this->wire()->page->url() . 'tasks/';
		if(!$task || !$task->builtIn) {
			$session->error($this->_('Task not found') . " - $name");
			$session->location($parentUrl);
		}
		/** @var AgentToolsTask $task */
		if(!$task->admin) {
			$session->error($this->_('This task is not available in the admin.'));
			$session->location($parentUrl);
		}
		$requirementsError = $this->getTaskRequirementsError($task);
		if($requirementsError) {
			$this->headline(sprintf($this->_('Run task: %s'), $task->title));
			$this->breadcrumb($parentUrl, $this->label('tasks'));
			$this->error($requirementsError);
			return '';
		}
		$this->headline(sprintf($this->_('Run task: %s'), $task->title));
		$this->breadcrumb($parentUrl, $this->label('tasks'));
		if(!$this->at->getPrimaryAgent()) {
			$agentsUrl = $this->wire()->page->url . 'agents/';
			$this->error(sprintf(
				$this->_('At least one agent must be configured. Please configure one in [Agents](%s).'),
				$agentsUrl
			), Notice::allowMarkdown);
			return '';
		}
		$form = $task->getConfigInputfields();
		$this->populateTaskFormFromLastRun($task, $form);
		$this->populateTaskFormFromQuery($task, $form);
		$form->attr('method', 'post');
		$this->addTaskAgentSelect($form);
		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_run_task');
		$f->addClass('at-show-thinking');
		$f->val($this->_('Run Task'));
		$f->icon = 'play';
		$f->appendMarkup .= $this->renderThinkingWords();
		$form->add($f);
		$form->prependMarkup .= $this->troubleshootingNote();

		if($form->isSubmitted()) {
			$form->processInput($input->post);
			if(!count($form->getErrors())) {
				return $this->processRunTask($task, $form);
			}
		}

		return $form->render();
	}

	/**
	 * Populate task form defaults from GET variables
	 *
	 * @param AgentToolsTask $task
	 * @param InputfieldWrapper $form
	 *
	 */
	protected function populateTaskFormFromQuery(AgentToolsTask $task, InputfieldWrapper $form): void {
		if($task->name !== 'migration-review') return;
		$migrations = trim((string) $this->wire()->input->get('migrations'));
		if($migrations === '') return;
		$values = [];
		foreach(explode(',', $migrations) as $name) {
			$name = basename(trim($name));
			if($name !== '') $values[] = $name;
		}
		if(!count($values)) return;
		$f = $form->getChildByName('migrations');
		if($f) $f->val($values);
	}

	/**
	 * Add agent selection to task run form
	 *
	 * @param InputfieldWrapper $form
	 *
	 */
	protected function addTaskAgentSelect(InputfieldWrapper $form): void {
		$availableModels = $this->at->engineer->getAvailableModels();
		if(count($availableModels) < 2) return;

		$meta = $this->wire()->user->meta('AgentTools') ?: [];
		$savedModelIndex = (string) ($meta['tasks_model_index'] ?? '0');

		$f = $form->InputfieldSelect;
		$f->attr('name', 'tasks_model_index');
		$f->label = $this->_('Agent');
		$f->description = $this->_('Choose which configured agent should run this task.');
		$f->icon = 'universal-access';
		foreach($availableModels as $index => $entry) {
			$f->addOption((string) $index, $entry['label']);
		}
		$f->val($savedModelIndex);
		$form->add($f);
	}

	/**
	 * Process submitted task input and run through the Engineer
	 *
	 * @param AgentToolsTask $task
	 * @param InputfieldWrapper $form
	 * @return string
	 *
	 */
	protected function processRunTask(AgentToolsTask $task, InputfieldWrapper $form): string {
		$modules = $this->wire()->modules;
		$adminUrl = $this->wire()->page->url;
		$taskUrl = $adminUrl . 'run-task/' . rawurlencode($task->name) . '/';
		$tasksUrl = $adminUrl . 'tasks/';
		$out = '';

		$requirementsError = $this->getTaskRequirementsError($task);
		if($requirementsError) {
			$this->error($requirementsError);
			return '';
		}

		$values = [];
		foreach(array_keys($task->inputs) as $name) {
			$values[$name] = $form->getValueByName($name);
		}
		$this->wire()->session->set($this->getTaskInputKey($task), $values);

		$f = $form->getByName('tasks_model_index');
		$modelIndex = $f ? $f->val() : false;

		$f = $modelIndex === false ? $form->getByName('model') : false;
		if($f) {
			$value = $f->val();
			$options = array_keys($f->getOptions());
			$modelIndex = array_search($value, $options);
		}
		$modelIndex = (int) $modelIndex;

		$options = $this->getTaskAgentOptions($modelIndex, true);

		$result = $this->at->getTasks()->run($task, $values, $options);
		if(!$result['error'] && $result['history']) {
			$this->wire()->session->set($this->getTaskHistoryKey($task), $result['history']);
		}

		/** @var InputfieldForm $outForm */
		$outForm = $modules->get('InputfieldForm');

		if($result['error']) {
			$this->error($result['error']);
		}

		if($result['response']) {
			$out .= $this->formatEngineerResponse($result['response']);
		}

		if($result['migration']) {
			$filename = basename($result['migration']);
			$this->message(sprintf($this->_('Migration saved: %s'), $filename));
			$btn = $outForm->InputfieldButton;
			$btn->href = $adminUrl . 'view-migration/?name=' . urlencode($filename);
			$btn->icon = 'database';
			$btn->val($this->_('Review and apply migration'));
			$btn->showInHeader(true);
			$outForm->add($btn);
		}

		if($task->builtIn) {
			$btn = $outForm->InputfieldButton;
			$btn->href = $taskUrl;
			$btn->icon = 'repeat';
			$btn->val($this->_('Run again'));
			if($result['migration']) {
				$btn->setSecondary();
			} else {
				$btn->showInHeader(true);
			}
			$outForm->add($btn);
		}

		$btn = $outForm->InputfieldButton;
		$btn->href = $tasksUrl;
		$btn->icon = 'arrow-left';
		$btn->val($this->_('Back to Tasks'));
		$btn->setSecondary();
		$outForm->add($btn);

		$outForm->val($out);

		return $outForm->render() . $this->renderTaskReplyForm($task, $modelIndex, $result);
	}

	/**
	 * Get unmet task requirements error, or blank if requirements are met
	 *
	 * @param AgentToolsTask $task
	 * @return string
	 *
	 */
	protected function getTaskRequirementsError(AgentToolsTask $task): string {
		$minVersion = $task->requiresProcessWireVersion();
		if($minVersion !== '' && version_compare($this->wire()->config->version, $minVersion, '<')) {
			return sprintf(
				$this->_('This task requires ProcessWire %s or newer.'),
				$minVersion
			);
		}
		if($task->requiresLanguageSupport()) {
			$languages = $this->wire('languages');
			if(!$languages) return $this->_('This task requires LanguageSupport to be installed.');
			foreach($languages as $language) {
				/** @var Language $language */
				if(!$language->isDefault()) return '';
			}
			return $this->_('This task requires at least one non-default language.');
		}
		return '';
	}

	/**
	 * Reply to a task result
	 *
	 * @return string
	 * @todo Methods beginning with "execute" translate to a URL accessible action. If that's not the intention, the method should be named differently.
	 *
	 */
	public function executeReplyTask() {
		$session = $this->wire()->session;
		$input = $this->wire()->input;
		$name = $input->urlSegment(2);
		$task = $this->at->getTasks()->getTask($name);
		$parentUrl = $this->wire()->page->url() . 'tasks/';
		if(!$task || !$task->admin) {
			$session->error($this->_('Task not found') . " - $name");
			$session->location($parentUrl);
		}
		/** @var AgentToolsTask $task */
		$this->headline(sprintf($this->_('Reply to task: %s'), $task->title));
		$this->breadcrumb($parentUrl, $this->label('tasks'));

		/** @var InputfieldForm $form */
		$form = $this->wire()->modules->get('InputfieldForm');
		$form->attr('method', 'post');
		$form->processInput($input->post);

		$reply = trim((string) $input->post('task_reply'));
		if(!$reply) {
			$session->error($this->_('Please enter a reply.'));
			$session->location($this->wire()->page->url . 'run-task/' . rawurlencode($task->name) . '/');
		}

		$modelIndex = (int) $input->post('tasks_model_index');
		$options = $this->getTaskAgentOptions($modelIndex, true);
		$options['history'] = $session->get($this->getTaskHistoryKey($task)) ?: [];

		$result = $this->at->engineer->ask($reply, $options);
		if(!$result['error'] && $result['history']) {
			$session->set($this->getTaskHistoryKey($task), $result['history']);
		}

		return $this->renderTaskReplyResult($task, $reply, $result, $modelIndex);
	}

	/**
	 * Get task agent options for given model index
	 *
	 * @param int $modelIndex
	 * @param bool $save Save selected model index to user meta?
	 * @return array
	 *
	 */
	protected function getTaskAgentOptions(int $modelIndex, bool $save = false): array {
		$options = [];
		$availableModels = $this->at->engineer->getAvailableModels();
		if(!isset($availableModels[$modelIndex])) return $options;
		$entry = $availableModels[$modelIndex];
		$options['model'] = $entry['model'];
		$options['provider'] = $entry['provider'];
		$options['apiKey'] = $entry['key'];
		$options['endpoint'] = $entry['endpoint'];
		if($save) {
			$meta = $this->wire()->user->meta('AgentTools') ?: [];
			$meta['tasks_model_index'] = $modelIndex;
			$this->wire()->user->meta('AgentTools', $meta);
			$this->message(sprintf($this->_('Task run with agent: %s'), $entry['label']));
		}
		return $options;
	}

	/**
	 * Get session key for task conversation history
	 *
	 * @param AgentToolsTask $task
	 * @return string
	 *
	 */
	protected function getTaskHistoryKey(AgentToolsTask $task): string {
		return 'at_task_history_' . $task->name;
	}

	/**
	 * Get session key for last submitted task input values
	 *
	 * @param AgentToolsTask $task
	 * @return string
	 *
	 */
	protected function getTaskInputKey(AgentToolsTask $task): string {
		return 'at_task_input_' . $task->name;
	}

	/**
	 * Populate task form defaults from last submitted values
	 *
	 * @param AgentToolsTask $task
	 * @param InputfieldWrapper $form
	 *
	 */
	protected function populateTaskFormFromLastRun(AgentToolsTask $task, InputfieldWrapper $form): void {
		$values = $this->wire()->session->get($this->getTaskInputKey($task));
		if(!is_array($values)) return;
		foreach($values as $name => $value) {
			if(!array_key_exists($name, $task->inputs)) continue;
			$f = $form->getChildByName($name);
			if($f) $f->val($value);
		}
	}

	/**
	 * Render a reply form for task result
	 *
	 * @param AgentToolsTask $task
	 * @param int $modelIndex
	 * @param array $result
	 * @return string
	 *
	 */
	protected function renderTaskReplyForm(AgentToolsTask $task, int $modelIndex, array $result): string {
		if($result['error']) return '';
		/** @var InputfieldForm $replyForm */
		$replyForm = $this->wire()->modules->get('InputfieldForm');
		$replyForm->attr('method', 'post');
		$replyForm->attr('action', $this->wire()->page->url . 'reply-task/' . rawurlencode($task->name) . '/');

		$f = $replyForm->InputfieldTextarea;
		$f->attr('name', 'task_reply');
		$f->label = $this->_('Reply');
		$f->icon = 'commenting';
		$f->attr('rows', 3);
		$replyForm->add($f);

		$f = $replyForm->InputfieldHidden;
		$f->attr('name', 'tasks_model_index');
		$f->attr('value', (string) $modelIndex);
		$replyForm->add($f);

		$f = $replyForm->InputfieldSubmit;
		$f->attr('name', 'submit_task_reply');
		$f->addClass('at-show-thinking');
		$f->val($this->_('Send reply'));
		$f->icon = 'send';
		$f->appendMarkup .= $this->renderThinkingWords();
		$replyForm->add($f);

		return $replyForm->render();
	}

	/**
	 * Render task reply result
	 *
	 * @param AgentToolsTask $task
	 * @param string $reply
	 * @param array $result
	 * @param int $modelIndex
	 * @return string
	 */
	protected function renderTaskReplyResult(AgentToolsTask $task, string $reply, array $result, int $modelIndex): string {
		$adminUrl = $this->wire()->page->url;
		$tasksUrl = $adminUrl . 'tasks/';
		/** @var InputfieldForm $outForm */
		$outForm = $this->wire()->modules->get('InputfieldForm');
		if($result['error']) $this->error($result['error']);
		$out = '<h2>' . $this->wire()->sanitizer->entities($task->title) . '</h2>';
		$out .= '<blockquote><p>' . nl2br($this->wire()->sanitizer->entities($reply)) . '</p></blockquote>';
		if($result['response']) $out .= $this->formatEngineerResponse($result['response']);
		if($result['migration']) {
			$filename = basename($result['migration']);
			$this->message(sprintf($this->_('Migration saved: %s'), $filename));
			$btn = $outForm->InputfieldButton;
			$btn->href = $adminUrl . 'view-migration/?name=' . urlencode($filename);
			$btn->icon = 'database';
			$btn->val($this->_('Review and apply migration'));
			$btn->showInHeader(true);
			$outForm->add($btn);
		}
		if($task->builtIn) {
			$btn = $outForm->InputfieldButton;
			$btn->href = $adminUrl . 'run-task/' . rawurlencode($task->name) . '/';
			$btn->icon = 'repeat';
			$btn->val($this->_('Run again'));
			$btn->setSecondary();
			$outForm->add($btn);
		}
		$btn = $outForm->InputfieldButton;
		$btn->href = $tasksUrl;
		$btn->icon = 'arrow-left';
		$btn->val($this->_('Back to Tasks'));
		$btn->setSecondary();
		$outForm->add($btn);
		$outForm->val($out);
		return $outForm->render() . $this->renderTaskReplyForm($task, $modelIndex, $result);
	}

	/**
	 * Build the task edit form (for custom tasks)
	 *
	 * @param AgentToolsTask $task
	 * @return InputfieldForm
	 *
	 */
	protected function buildEditTaskForm(AgentToolsTask $task) {

		if($task->builtIn) throw new WireException('This form is for custom tasks only');

		$modules = $this->wire()->modules;
		$form = $modules->get('InputfieldForm'); /** @var InputfieldForm $form */
		$allowRun = $task->name && $this->wire()->input->get('run');

		$f = $form->InputfieldText;
		$f->attr('name', 'title');
		$f->attr('id', 'task_title');
		$f->label = $this->_('Task title');
		$f->required = true;
		$f->val($task->title);
		$f->columnWidth = 50;
		$form->add($f);

		$f = $form->InputfieldName;
		$f->label = 'Task name';
		$f->notes = $f->description;
		$f->description = '';
		$f->attr('id+name', 'task_name');
		$f->val($task->name);
		$f->columnWidth = 50;
		$f->required = true;
		$form->add($f);

		$f = $form->InputfieldTextarea;
		$f->attr('name', 'summary');
		$f->label = $this->_('Summary');
		$f->description =  $this->_('Short description of what this task does.');
		$f->attr('rows', 3);
		$f->collapsed = Inputfield::collapsedBlank;
		$f->val($task->summary);
		$form->add($f);

		$f = $form->InputfieldTextarea;
		$f->attr('name', 'prompt');
		$f->label = $this->_('Prompt');
		$f->addClass('at-engineer-request');
		$f->description = $this->_('Tell the Site Engineer what you would like to do for this task.');
		$f->val($task->prompt);
		$f->required = true;
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'model');
		$f->label = $this->_('Model');
		foreach($this->at->engineer->getAvailableModels() as $model) {
			$f->addOption($model['model'], $model['label']);
		}
		$f->val($task->model);
		$f->required = true;
		$form->add($f);

		$f = $form->InputfieldRadios;
		$f->attr('name', 'mode');
		$f->label = $this->_('Allow migration creation?');
		$f->description = $this->_('Allow this task to create migration files when requested? Leave off for review/report-only tasks.');
		$f->addOption('review_then_fix', $this->_('Yes'));
		$f->addOption('review', $this->_('No'));
		$f->optionColumns = 1;
		$f->val($task->mode);
		$form->add($f);

		$f = $form->InputfieldIcon;
		$f->attr('name', 'icon');
		$f->label = $this->_('Icon');
		$f->val($task->icon);
		$f->collapsed = Inputfield::collapsedBlank;
		$form->add($f);

		if($task->name) {
			$f = $form->InputfieldCheckbox;
			$f->attr('name', 'delete_task');
			$f->label = $this->_('Delete task?');
			$f->description = $this->_('Check the box and click Save to delete this task from the system.');
			$f->icon = 'trash-o';
			$f->val($task->name);
			$f->collapsed = Inputfield::collapsedYes;
			$form->add($f);
		}

		$f1 = $form->InputfieldSubmit;
		$f1->attr('name', 'submit_save_task');
		$f1->val($this->label('save'));
		//$f1->showInHeader(true);
		$form->add($f1);

		if($allowRun) {
			$f2 = $form->InputfieldSubmit;
			$f2->attr('name', 'submit_run_task');
			$f2->val($this->label('run'));
			//$f2->showInHeader(true);
			$f2->addClass('at-show-thinking');
			$f2->icon = 'send';
			$f2->appendMarkup .= $this->renderThinkingWords();
			$form->add($f2);
			$f1->setSecondary();
		}

		$form->prependMarkup .= $this->troubleshootingNote();

		return $form;
	}

	/**
	 * Add or edit task
	 *
	 * @return string
	 *
	 */
	public function executeEditTask() {

		$input = $this->wire()->input;
		$session = $this->wire()->session;
		$parentUrl = $this->wire()->page->url() . 'tasks/';
		$taskName = $input->urlSegment(2);
		$tasks = $this->at->getTasks();
		$task = $taskName ? $tasks->getTask($taskName) : new AgentToolsTask();
		$form = $this->buildEditTaskForm($task);
		$save = $input->post('submit_save_task');
		$run = $input->post('submit_run_task');
		$new = $task->name === '';
		$delete = $taskName && $input->post('delete_task') === $taskName;

		if($new) {
			$this->headline($this->_('Add new task'));
		} else {
			$this->headline(sprintf($this->_('Edit task: %s'), $task->title));
		}

		$this->breadcrumb($parentUrl, $this->label('tasks'));

		if(!$save && !$run) return $form->render();

		$form->processInput($input->post);

		$f = $form->getByName('task_name');
		if($f->val() !== $taskName) {
			// task renamed or is new
			if($tasks->getTask($f->val())) {
				// name collides with other task
				$f->error(sprintf($this->_('Task name "%s" already in use'), $f->val()));
				$f->val($taskName);
			}
		}

		if(count($form->getErrors())) return $form->render();

		$f = $form->getByName('delete_task');
		if($delete && $f->val() === $taskName) {
			// delete task requested
			if($tasks->deleteTask($task)) {
				$session->message(sprintf($this->_('Deleted task: %s'), $task->title));
				$session->location($parentUrl);
			}
		}

		foreach($form->getAll() as $f) {
			// populate form fields task
			if($f instanceof InputfieldSubmit) continue;
			$task->set($f->name, $f->val());
		}

		if($tasks->save($task)) {
			if($save) {
				// save only
				$session->message(sprintf($this->_('Saved task: %s'), $task->title));
			} else if($run) {
				// run the task after saving
				return $this->processRunTask($task, $form);
			}
		} else {
			$session->error($this->_('Failed to save task'));
		}

		$session->location($parentUrl);

		return '';
	}

}
