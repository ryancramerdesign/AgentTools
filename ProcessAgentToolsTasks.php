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
	 * Add background job checkbox to form
	 *
	 * @param InputfieldWrapper $form
	 *
	 */
	protected function addBackgroundJobCheckbox(InputfieldWrapper $form): void {
		$user = $this->wire()->user;
		$email = trim((string) $user->email);
		$healthy = $this->at->jobs()->isCronHealthy();

		$f = $form->InputfieldCheckbox;
		$f->attr('name', 'at_run_background');
		$f->label = $this->_('Run in background?');
		$f->icon = 'clock-o';
		$f->description =
			$this->_('Recommended because tasks sometimes need more time than http requests allow.');
		if($email) {
			$f->label2 = sprintf($this->_('Run in background and email [%s](mailto:%s) when done'), $email, $email);
		} else {
			$f->notes = $this->label('background-job-email-missing');
			$f->attr('disabled', 'disabled');
		}
		if(!$healthy) {
			$f->collapsed = Inputfield::collapsedYes;
			$f->description = $this->label('background-job-cron-stale');
			$f->attr('disabled', 'disabled');
		}
		$form->add($f);
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
		$wrapper = new InputfieldWrapper();

		$taskTypes = [
			'builtin' => [],
			'custom' => [],
			'scheduled' => iterator_to_array($this->at->getScheduledTasks()),
		];

		$taskTypeLabels = [
			'builtin' => $this->_('Built-in'),
			'custom' => $this->_('Custom'),
			'scheduled' => $this->_('Scheduled'),
		];

		foreach($this->at->getTasks() as $task) {
			/** @var AgentToolsTask $task */
			$key = $task->builtIn ? 'builtin' : 'custom';
			$taskTypes[$key][] = $task;
		}

		foreach($taskTypes as $key => $items) {
			if($key === 'scheduled') {
				$table = $this->buildScheduledTasksTable($items);
			} else {
				$table = $this->buildTasksTable($items);
			}

			if($key === 'builtin') {
				$f = null; // no 'add' button for built-in tasks
			} else {
				/** @var InputfieldButton $f */
				$f = $modules->get('InputfieldButton');
				$f->href = ($key === 'scheduled' ? '../edit-scheduled-task/' : '../edit-task/') . '?new=1';
				$f->val($key === 'scheduled' ? $this->_('Schedule task') : $this->label('add-task'));
				$f->icon = 'plus-circle';
			}

			$label = $taskTypeLabels[$key];
			// $tabsItems[$label] = $table->render() . ($f ? $f->render() : '');
			/** @var InputfieldMarkup $fieldset */
			$fm = $modules->get('InputfieldMarkup');
			$fm->wrapClass('at-task-type');
			$fm->label = $label;
			$fm->value = $table->render() . ($f ? $f->render() : '');
			$fm->collapsed = Inputfield::collapsedYes;
			$wrapper->add($fm);
		}

		$note = $this->troubleshootingNote();

		return $wrapper->render() . $note;

		return $tabs->render($tabsItems) . $note;
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
		$this->addBackgroundJobCheckbox($form);

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_run_task');
		$f->addClass('at-show-thinking');
		$f->val($this->_('Run Task'));
		$f->icon = 'play';
		$f->appendMarkup .= $this->renderThinkingWords();
		$form->add($f);
		$form->appendMarkup .= $this->troubleshootingNote();

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

		if($this->wire()->input->post('at_run_background')) {
			$backgroundError = $this->getBackgroundJobError();
			if($backgroundError) {
				$this->error($backgroundError);
				return '';
			}
			$user = $this->wire()->user;
			$this->saveTaskModelIndex($modelIndex);
			$job = $this->at->jobs()->addJob([
				'type' => 'task',
				'userId' => (int) $user->id,
				'userName' => (string) $user->name,
				'notifyEmail' => (string) $user->email,
				'agentId' => (string) ($this->at->engineer->getAvailableModels()[$modelIndex]['id'] ?? ''),
				'modelIndex' => $modelIndex,
				'url' => $this->wire()->page->httpUrl(),
				'agentToolsUrl' => $this->wire()->page->httpUrl(),
				'taskName' => $task->name,
				'taskInput' => $values,
				'maxIterations' => (int) $task->maxIterations,
			]);
			return $this->renderQueuedJobConfirmation(
				$job,
				$tasksUrl,
				$this->_('Back to Tasks'),
				'arrow-left'
			);
		}

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
	 * Save selected task model index to user meta
	 *
	 * @param int $modelIndex
	 *
	 */
	protected function saveTaskModelIndex(int $modelIndex): void {
		$availableModels = $this->at->engineer->getAvailableModels();
		if(!isset($availableModels[$modelIndex])) return;
		$meta = $this->wire()->user->meta('AgentTools') ?: [];
		$meta['tasks_model_index'] = $modelIndex;
		$this->wire()->user->meta('AgentTools', $meta);
		$this->message(sprintf($this->_('Task queued with agent: %s'), $availableModels[$modelIndex]['label']));
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

		if($allowRun) {
			$this->addBackgroundJobCheckbox($form);

			$f2 = $form->InputfieldSubmit;
			$f2->attr('name', 'submit_run_task');
			$f2->val($this->label('run'));
			$f2->addClass('at-show-thinking');
			$f2->icon = 'send';
			$f2->appendMarkup .= $this->renderThinkingWords();
			$form->add($f2);
		}

		$f1 = $form->InputfieldSubmit;
		$f1->attr('name', 'submit_save_task');
		$f1->val($this->label('save'));
		//$f1->showInHeader(true);
		if($allowRun) $f1->setSecondary();
		$form->add($f1);

		$form->appendMarkup .= $this->troubleshootingNote();

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

	/**
	 * Edit scheduled task
	 *
	 * @return string
	 *
	 */
	public function executeEditScheduledTask(): string {
		$session = $this->wire()->session;
		$input = $this->wire()->input;

		$schedules = $this->at->getScheduledTasks();
		$editTaskName = $input->urlSegment(2);

		if($editTaskName) {
			$task = $schedules->getTask($editTaskName);
			if(!$task) {
				$session->error($this->_('Scheduled task not found'));
				$session->location($this->wire()->page->url . 'tasks/');
			}
		} else {
			// add new task
			$task = $schedules->makeBlankItem();
		}
		if(!$task->task && $input->post('task')) $task->task = $input->post->pageName('task');

		$form = $this->buildEditScheduledTaskForm($task);
		$run = $input->post('submit_run_task');
		$save = $run || $input->post('submit_save_task');

		if($save) {
			if(!$this->processScheduledTask($form, $task, !$run)) return $form->render();
			if($run) {
				$job = $schedules->enqueueRun($task, [
					'userId' => (int) $this->wire()->user->id,
					'userName' => (string) $this->wire()->user->name,
					'url' => $this->wire()->page->httpUrl(),
					'agentToolsUrl' => $this->wire()->page->httpUrl(),
				]);
				return $this->renderQueuedJobConfirmation(
					$job,
					$this->wire()->page->url . 'tasks/',
					$this->_('Back to Tasks'),
					'arrow-left'
				);
			}
		}

		if(!$task->name) {
			$this->headline($this->_('Add new scheduled task'));
		} else {
			$this->headline(sprintf($this->_('Edit scheduled task: %s'), $task->title));
		}

		$this->breadcrumb('../', $this->label('tasks'));

		return $form->render();
	}

	/**
	 * Process the scheduled task edit form
	 *
	 * @param InputfieldForm $form
	 * @param AgentToolsScheduledTask $task
	 * @throws WireException
	 *
	 */
	protected function processScheduledTask(InputfieldForm $form, AgentToolsScheduledTask $task, bool $redirect = true): bool {

		$input = $this->wire()->input;
		$form->processInput($input->post);
		$session = $this->wire()->session;
		$sanitizer = $this->wire()->sanitizer;
		$isNew = !$task->name;
		$prevName = $isNew ? '' : $task->name;

		if(count($form->getErrors())) return false;

		if($task->name && $input->post('_delete_task') === $task->name) {
			$this->at->getScheduledTasks()->deleteTask($task);
			$session->message($this->_('Scheduled task deleted'));
			$session->location($this->wire()->page->url . 'tasks/');
		}

		foreach($form->getAll() as $f) {
			$name = $f->name;
			$val = $f->val();

			if($name === 'task') {
				$val = $sanitizer->pageName($val);

			} else if($name === 'name') {
				$val = $sanitizer->pageName($val);
				if($isNew || (!$isNew && $val !== $prevName)) {
					$nameExists = $this->at->getScheduledTasks()->exists($val);
					if($nameExists) {
						$f->error(sprintf($this->_('Scheduled task name "%s" already in use'), $val));
						continue;
					}
				}

			} else if($name === 'notifyEmail') {
				$a = [];
				foreach(explode(',', $val) as $email) {
					$email = trim($email);
					$v = $sanitizer->email(strtolower($email));
					if($v !== strtolower($email)) {
						$f->error(sprintf($this->_('Invalid email address: %s'), $email));
					} else {
						if($v) $a[] = $email;
					}
				}
				$val = implode(',', $a);
			} else if($name === 'time') {
				$val = trim((string) $val);
				if($val !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $val)) {
					$f->error($this->_('Time must be in HH:MM format.'));
					continue;
				}
			} else if(strpos($name, 'submit_') === 0 || $name === '_delete_task') {
				continue;
			} else if(!$task->hasProperty($name)) {
				continue;
			}
			$task->set($name, $val);
		}
		$runTask = $this->at->getTasks()->getTask($task->task);
		if($runTask) {
			$inputs = [];
			foreach(array_keys($runTask->inputs) as $name) {
				$inputs[$name] = $form->getValueByName($name);
			}
			$task->inputs = $inputs;
		}

		if(count($form->getErrors())) return false;
		$this->at->getScheduledTasks()->save($task, $prevName);
		$session->message(sprintf($this->_('Saved scheduled task: %s'), $task->title));
		if($redirect) {
			$url = $this->wire()->page->url . 'edit-scheduled-task/' . rawurlencode($task->name) . '/';
			$session->location($url);
		}
		return true;
	}

	/**
	 * Build the task edit form (for custom tasks)
	 *
	 * @param AgentToolsScheduledTask $task
	 * @return InputfieldForm
	 *
	 */
	protected function buildEditScheduledTaskForm(AgentToolsScheduledTask $task): InputfieldForm {

		$modules = $this->wire()->modules;
		$form = $modules->get('InputfieldForm'); /** @var InputfieldForm $form */
		$isNew = !$task->name;

		$form->attr('action', './');

		$f = $form->InputfieldText;
		$f->attr('name', 'title');
		$f->attr('id', 'task_title');
		$f->label = $this->_('Scheduled Task title');
		$f->required = true;
		$f->columnWidth = 50;
		$f->val($task->title);
		$form->add($f);

		$f = $form->InputfieldName;
		$f->attr('name', 'name');
		$f->attr('id', 'task_name');
		$f->label = $this->_('Scheduled task name');
		$f->notes = $f->description;
		$f->description = '';
		$f->icon = 'code';
		$f->columnWidth = 50;
		$f->required = true;
		$f->val($task->name);
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'task');
		$f->label = $this->label('task');
		$f->required = true;
		$taskOptions = [ 'custom' => [], 'built-in' => [] ];
		foreach($this->at->getTasks() as $t) {
			if($t->builtIn && !$t->scheduleable) continue;
			if($t->builtIn) $taskOptions['built-in'][$t->name] = $t->title;
				else $taskOptions['custom'][$t->name] = $t->title;
		}
		$f->addOptions($taskOptions);
		$f->val($task->task);
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'agentId');
		$f->label = $this->_('Agent/model');
		$f->required = true;
		foreach($this->at->getAgents() as $agent) {
			$label = $agent->get('label|model');
			$f->addOption($agent->id, $label);
		}
		$f->val($task->agentId);
		$form->add($f);

		$f = $form->InputfieldRadios;
		$f->attr('name', 'status');
		$f->label = $this->_('Status');
		$f->addOption('paused', $this->_('Paused'));
		$f->addOption('active', $this->_('Active'));
		$f->optionColumns = 1;
		$f->icon = $task->status === 'paused' ? 'pause' : 'play';
		$f->val($task->status);
		$form->add($f);

		$f = $form->InputfieldText;
		$f->attr('name', 'notifyEmail');
		$f->label = $this->_('Notify email(s)');
		$f->description = $this->_('Enter one or more email addresses separated by commas.');
		$f->icon = 'envelope';
		$f->val($task->notifyEmail);
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'frequency');
		$f->label = $this->_('Frequency');
		$f->icon = 'refresh';
		$f->required = true;
		$f->addOption('15-minutes', $this->_('Every 15 minutes'));
		$f->addOption('30-minutes', $this->_('Every 30 minutes'));
		$f->addOption('hour', $this->_('Hourly'));
		$f->addOption('2-hours', $this->_('Every 2 hours'));
		$f->addOption('4-hours', $this->_('Every 4 hours'));
		$f->addOption('6-hours', $this->_('Every 6 hours'));
		$f->addOption('12-hours', $this->_('Every 12 hours'));
		$f->addOption('day', $this->_('Daily'));
		$f->addOption('week', $this->_('Weekly'));
		$f->addOption('month', $this->_('Monthly'));
		$f->val($task->frequency);
		$form->add($f);

		$f = $form->InputfieldText;
		$f->attr('name', 'time');
		$f->label = $this->_('Time');
		$f->description = $this->_('Use 24-hour HH:MM format, like 06:00 or 23:30.');
		$f->icon = 'clock-o';
		$f->showIf = 'frequency=day|week|month';
		$f->val($task->time);
		$form->add($f);

		$f = $form->InputfieldSelect;
		$f->attr('name', 'weekday');
		$f->label = $this->_('Day of week');
		$f->showIf = 'frequency=week';
		$f->addOptions([
			1 => $this->_('Monday'),
			2 => $this->_('Tuesday'),
			3 => $this->_('Wednesday'),
			4 => $this->_('Thursday'),
			5 => $this->_('Friday'),
			6 => $this->_('Saturday'),
			7 => $this->_('Sunday'),
		]);
		$f->val($task->weekday);
		$form->add($f);

		$f = $form->InputfieldInteger;
		$f->attr('name', 'monthday');
		$f->label = $this->_('Day of month');
		$f->description = $this->_('Use 1-31. Months with fewer days run on the last day of the month.');
		$f->min = 1;
		$f->max = 31;
		$f->showIf = 'frequency=month';
		$f->val($task->monthday);
		$form->add($f);

		$runTask = $task->task ? $this->at->getTasks()->getTask($task->task) : null;
		if($runTask && count($runTask->inputs)) {
			$fs = $form->InputfieldFieldset;
			$fs->label = $this->_('Task settings');
			$fs->icon = 'sliders';
			$runTask->getConfigInputfields($fs);
			foreach($task->inputs as $name => $value) {
				$field = $fs->getChildByName($name);
				if($field) $field->val($value);
			}
			$form->add($fs);
		}

		if(!$isNew) {
			$f = $form->InputfieldCheckbox;
			$f->attr('name', '_delete_task');
			$f->label = $this->_('Delete task?');
			$f->description = $this->_('Check the box and click Save to permanently delete this task from the system.');
			$f->icon = 'trash-o';
			$f->collapsed = Inputfield::collapsedYes;
			$f->val($task->name);
			$form->add($f);
		}

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_save_task');
		$f->val($this->label('save'));
		$f->showInHeader(true);
		$form->add($f);

		if(!$isNew && $task->task) {
			$f = $form->InputfieldSubmit;
			$f->attr('name', 'submit_run_task');
			$f->val($this->label('run-now'));
			$f->addClass('at-show-thinking');
			$f->icon = 'send';
			$f->appendMarkup .= $this->renderThinkingWords();
			$f->setSecondary();
			$form->add($f);
		}

		return $form;
	}

	/**
	 * Build a tasks table
	 *
	 * @param array|AgentToolsScheduledTask[] $tasks
	 *
	 */
	protected function buildScheduledTasksTable($tasks) {
		$modules = $this->wire()->modules;

		$table = $modules->get('MarkupAdminDataTable'); /** @var MarkupAdminDataTable $table */
		$table->setEncodeEntities(false);
		$headerRow = [
			$this->label('title'),
			$this->label('task'),
			$this->label('status'),
			$this->label('frequency'),
			$this->_('Last run'),
			$this->_('Next run'),
		];
		$table->headerRow($headerRow);

		$qty = 0;

		foreach($tasks as $task) {
			$title = htmlspecialchars($task->title);
			$name = rawurlencode($task->name);
			$runTask = $this->at->getTasks()->getTask($task->task);
			$runTask = $runTask ? $runTask->title : $task->task;
			$runTask = htmlspecialchars($runTask);
			if($task->status === 'paused') {
				$nextRun = $this->_('Paused');
			} else if($task->nextRun && $task->nextRun <= time()) {
				$nextRun = $this->_('Due now');
			} else {
				$nextRun = $task->nextRun ? wireRelativeTimeStr($task->nextRun) : $this->_('Not scheduled');
			}
			$url = "../edit-scheduled-task/$name/";
			$table->row([
				"<a href='$url'>$title</a>",
				$runTask,
				$task->status,
				$task->frequency,
				$task->lastRun ? wireRelativeTimeStr($task->lastRun) : $this->_('Never'),
				$nextRun,
			]);
			$qty++;
		}

		if(!$qty) {
			$table->row([ 'No scheduled tasks found' ], [ 'colspan' => count($headerRow) ]);
		}

		return $table;
	}
}

require_once(__DIR__ . '/AgentToolsScheduledTask.php');
