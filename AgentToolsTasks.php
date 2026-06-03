<?php namespace ProcessWire;

/**
 * WireArray of AgentToolsTask instances
 *
 * @implements IteratorAggregate<int, AgentToolsTask>
 *
 */
class AgentToolsTasks extends WireArray {

	/**
	 * @var AgentTools
	 *
	 */
	protected $at;

	/**
	 * @param AgentTools $at
	 *
	 */
	public function __construct(AgentTools $at) {
		parent::__construct();
		$this->at = $at;
		$this->loadAll();
	}

	public function isValidItem($item) {
		return $item instanceof AgentToolsTask;
	}

	/**
	 * Make a blank AgentToolsTask
	 *
	 * @return AgentToolsTask
	 *
	 */
	public function makeBlankItem(): AgentToolsTask {
		$task = new AgentToolsTask();
		$this->wire($task);
		return $task;
	}

	/**
	 * Load all built-in tasks from a directory
	 *
	 * @return self
	 *
	 */
	public function loadAll(): self {

		$path =  __DIR__ . '/tasks/';
		if(!is_dir($path)) return $this;
		$files1 = is_dir($path) ? glob($path . '*.php') : [];
		if(!is_array($files1)) $files1 = [];

		$path = $this->at->getFilesPath('tasks');
		$files2 = is_dir($path) ? glob($path . '*.json') : [];
		if(!is_array($files2)) $files2 = [];

		$files = array_merge($files1, $files2);
		sort($files);

		foreach($files as $file) {
			$this->addFile($file);
		}

		return $this;
	}

	/**
	 * Add task from PHP definition file
	 *
	 * @param string $file
	 * @return AgentToolsTask|null
	 *
	 */
	public function addFile(string $file): ?AgentToolsTask {
		if(!is_file($file)) return null;
		$extension= pathinfo($file, PATHINFO_EXTENSION);
		if($extension === 'php') {
			$data = include($file);
		} else {
			$data = file_get_contents($file);
			$data = json_decode($data, true);
		}
		if(!is_array($data)) return null;
		if(empty($data['name'])) $data['name'] = basename($file, ".$extension");
		if($extension === 'php') $data['builtIn'] = 1;
		$data['sourceFile'] = $file;
		$task = $this->makeBlankItem();
		$task->setArray($data);
		$this->add($task);
		return $task;
	}

	/**
	 * Get task by name
	 *
	 * @param string $value
	 * @param string $property
	 * @return AgentToolsTask|null
	 *
	 */
	public function getTask(string $value, $property = 'name'): ?AgentToolsTask {
		foreach($this as $task) {
			/** @var AgentToolsTask $task */
			if($task->$property === $value) return $task;
		}
		return null;
	}

	/**
	 * Run a task through the Site Engineer
	 *
	 * @param AgentToolsTask|string $task Task instance or task name
	 * @param array $input Input values for prompt rendering
	 * @param array $options Options passed to AgentToolsEngineer::ask()
	 * @return array
	 *
	 */
	public function run($task, array $input = [], array $options = []): array {
		if(is_string($task)) $task = $this->getTask($task);
		$result = [
			'error' => '',
			'response' => '',
			'migration' => '',
			'history' => [],
			'request' => '',
			'input' => $input,
			'task' => $task instanceof AgentToolsTask ? $task->name : '',
			'readOnly' => false,
			'dryRun' => !empty($options['dryRun']),
			'maxIterations' => 0,
		];
		if(!$task instanceof AgentToolsTask) {
			$result['error'] = 'Task not found.';
			return $result;
		}

		$request = $task->renderPrompt($input);
		if(array_key_exists('readOnly', $options)) {
			$readOnly = (bool) $options['readOnly'];
		} else {
			$readOnly = $task->mode === 'review';
			foreach($task->readOnlyWhen as $name => $value) {
				if(!array_key_exists($name, $input)) continue;
				if((string) $input[$name] === (string) $value) {
					$readOnly = true;
					break;
				}
			}
		}
		$options['readOnly'] = $readOnly;
		if(!isset($options['maxIterations']) && (int) $task->maxIterations > 0) {
			$options['maxIterations'] = (int) $task->maxIterations;
		}
		unset($options['dryRun']);
		if(empty($options['traceType'])) $options['traceType'] = 'task';
		$result['maxIterations'] = (int) ($options['maxIterations'] ?? 0);
		if($result['dryRun']) {
			$result['request'] = $request;
			$result['task'] = $task->name;
			$result['readOnly'] = $readOnly;
			return $result;
		}

		$at = $this->wire('at'); /** @var AgentTools $at */
		$result = array_merge($result, $at->engineer->ask($request, $options));
		$result['request'] = $request;
		$result['task'] = $task->name;
		$result['readOnly'] = $readOnly;
		return $result;
	}

	/**
	 * @return \ArrayIterator<int, AgentToolsTask>
	 *
	 */
	public function getIterator(): \ArrayIterator {
		return new \ArrayIterator($this->data);
	}

	/**
	 * Save custom task
	 *
	 * @param AgentToolsTask $task
	 * @return int|false Number of bytes written, or false on failure.
	 * @throws WireException
	 *
	 */
	public function save(AgentToolsTask $task) {
		if($task->builtIn) throw new WireException('Cannot save built-in task');
		if(!$task->title) throw new WireException('Task title is required');
		if(!$task->name) $task->name = $task->title; // converts using pageName
		$a = $task->getArray();
		unset($a['builtIn'], $a['sourceFile']);
		$file = $this->at->getFilesPath('tasks') . strtolower($task->name) . '.json';
		$json = json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		return $this->wire()->files->filePutContents($file, $json);
	}

	/**
	 * Get filename where task is stored
	 *
	 * @param AgentToolsTask $task
	 * @return string
	 *
	 */
	public function getTaskFile(AgentToolsTask $task): string  {
		if($task->builtIn) return __DIR__ . "/tasks/$task->name.php";
		return $this->at->getFilesPath('tasks') . "$task->name.json";
	}

	/**
	 * Delete custom task
	 *
	 * @param AgentToolsTask $task
	 * @return bool
	 * @throws WireException
	 *
	 */
	public function deleteTask(AgentToolsTask $task) {
		if($task->builtIn) throw new WireException('Cannot delete built-in task');
		$file = $this->getTaskFile($task);
		if(is_file($file)) {
			return $this->wire()->files->unlink($file);
		}
		return false;
	}

}
