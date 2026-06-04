<?php namespace ProcessWire;

/**
 * Scheduled AgentTools tasks.
 *
 * @implements IteratorAggregate<int, AgentToolsScheduledTask>
 *
 */
class AgentToolsScheduledTasks extends WireArray {

	/**
	 * @var AgentTools
	 *
	 */
	protected $at;

	/**
	 * Construct
	 *
	 * @param AgentTools $at
	 *
	 */
	public function __construct(AgentTools $at) {
		parent::__construct();
		$this->at = $at;
		$this->loadAll();
	}

	public function isValidItem($item) {
		return $item instanceof AgentToolsScheduledTask;
	}

	/**
	 * Make a blank scheduled task
	 *
	 * @return AgentToolsScheduledTask
	 *
	 */
	public function makeBlankItem(): AgentToolsScheduledTask {
		$task = new AgentToolsScheduledTask();
		$this->wire($task);
		return $task;
	}

	/**
	 * Load all scheduled tasks from disk
	 *
	 * @return self
	 *
	 */
	public function loadAll(): self {
		foreach($this->getFiles() as $file) {
			$this->addFile($file);
		}
		return $this;
	}

	/**
	 * Get scheduled task by name
	 *
	 * @param string $name
	 * @return AgentToolsScheduledTask|null
	 *
	 */
	public function getTask(string $name): ?AgentToolsScheduledTask {
		$name = $this->wire()->sanitizer->pageName($name);
		foreach($this as $task) {
			/** @var AgentToolsScheduledTask $task */
			if($task->name === $name) return $task;
		}
		return null;
	}

	/**
	 * Does a scheduled task exist?
	 *
	 * @param string $name
	 * @return bool
	 *
	 */
	public function exists(string $name): bool {
		return $this->getTask($name) instanceof AgentToolsScheduledTask;
	}

	/**
	 * Add a scheduled task from JSON file
	 *
	 * @param string $file
	 * @return AgentToolsScheduledTask|null
	 *
	 */
	public function addFile(string $file): ?AgentToolsScheduledTask {
		if(!is_file($file) || !is_readable($file)) return null;
		$data = json_decode((string) file_get_contents($file), true);
		if(!is_array($data)) return null;
		if(empty($data['name'])) $data['name'] = basename($file, '.json');
		$task = $this->makeBlankItem();
		$task->setArray($data);
		$this->add($task);
		return $task;
	}

	/**
	 * Save a scheduled task
	 *
	 * @param AgentToolsScheduledTask $task
	 * @param string $previousName
	 * @param array $options
	 * @return int|false
	 * @throws WireException
	 *
	 */
	public function save(AgentToolsScheduledTask $task, string $previousName = '', array $options = []) {
		$options = array_merge([
			'updateNextRun' => true,
		], $options);
		if(!$task->title) throw new WireException('Scheduled task title is required');
		if(!$task->name) $task->name = $task->title;
		$now = time();
		if(!$task->created) $task->created = $now;
		if(!$task->createdUserId) $task->createdUserId = (int) $this->wire()->user->id;
		$task->modified = $now;
		if($options['updateNextRun']) $task->nextRun = $this->calculateNextRun($task, $now);

		$file = $this->getTaskFile($task);
		$previousName = $this->wire()->sanitizer->pageName($previousName);
		if($previousName && $previousName !== $task->name) {
			$previous = $this->getTask($previousName);
			if($previous) $this->remove($previous);
			$previousFile = $this->getPath() . strtolower($previousName) . '.json';
			if(is_file($previousFile)) $this->wire()->files->unlink($previousFile);
		}
		$json = json_encode($task->getArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if($json === false) throw new WireException('Unable to encode scheduled task JSON');
		$result = $this->wire()->files->filePutContents($file, $json . "\n", LOCK_EX);
		if(!$this->getTask($task->name)) $this->add($task);
		return $result;
	}

	/**
	 * Delete a scheduled task
	 *
	 * @param AgentToolsScheduledTask $task
	 * @return bool
	 *
	 */
	public function deleteTask(AgentToolsScheduledTask $task): bool {
		$file = $this->getTaskFile($task);
		$this->remove($task);
		return is_file($file) ? $this->wire()->files->unlink($file) : false;
	}

	/**
	 * Enqueue all due scheduled tasks.
	 *
	 * @param array $options
	 * @return array
	 *
	 */
	public function enqueueDue(array $options = []): array {
		$now = time();
		$queued = 0;
		$errors = [];
		foreach($this as $schedule) {
			/** @var AgentToolsScheduledTask $schedule */
			if($schedule->status !== 'active') continue;
			if(!$schedule->nextRun) $schedule->nextRun = $this->calculateNextRun($schedule, $schedule->lastRun ?: $now);
			if($schedule->nextRun > $now) continue;
			try {
				$this->enqueueRun($schedule, [ 'scheduled' => true ]);
				$schedule->lastRun = $now;
				$schedule->nextRun = $this->calculateNextRun($schedule, $now);
				$this->save($schedule);
				$queued++;
			} catch(\Throwable $e) {
				$errors[] = "$schedule->name: " . $e->getMessage();
			}
		}
		return [
			'queued' => $queued,
			'errors' => $errors,
		];
	}

	/**
	 * Enqueue one scheduled task run immediately.
	 *
	 * @param AgentToolsScheduledTask $schedule
	 * @param array $options
	 * @return array
	 * @throws WireException
	 *
	 */
	public function enqueueRun(AgentToolsScheduledTask $schedule, array $options = []): array {
		$task = $this->at->getTasks()->getTask($schedule->task);
		if(!$task) throw new WireException("Task not found: $schedule->task");
		$agent = $this->resolveAgent($schedule);
		if(!$agent) throw new WireException('Configured agent no longer exists.');
		$schedule->lastAgentId = $agent->id;
		if(!$schedule->agentId) $schedule->agentId = $agent->id;
		$userId = (int) ($options['userId'] ?? $schedule->createdUserId);
		$userName = (string) ($options['userName'] ?? '');
		if(!$userName && $userId) {
			$user = $this->wire()->users->get($userId);
			if($user && $user->id) $userName = $user->name;
		}
		$jobData = [
			'type' => 'task',
			'userId' => $userId,
			'userName' => $userName,
			'notifyEmail' => $schedule->notifyEmail,
			'agentId' => $agent->id,
			'url' => (string) ($options['url'] ?? ''),
			'agentToolsUrl' => (string) ($options['agentToolsUrl'] ?? ''),
			'siteUrl' => (string) ($options['siteUrl'] ?? $schedule->siteUrl),
			'taskName' => $task->name,
			'taskInput' => $schedule->inputs,
			'scheduledTask' => $schedule->name,
		];
		if((int) $task->maxIterations > 0) $jobData['maxIterations'] = (int) $task->maxIterations;
		$job = $this->at->jobs()->addJob($jobData);
		$this->save($schedule, '', [ 'updateNextRun' => false ]);
		return $job;
	}

	/**
	 * Resolve scheduled task agent.
	 *
	 * @param AgentToolsScheduledTask $schedule
	 * @return AgentToolsAgent|null
	 *
	 */
	public function resolveAgent(AgentToolsScheduledTask $schedule): ?AgentToolsAgent {
		$agents = $this->at->getAgents();
		$ids = $schedule->agentIds;
		if(!count($ids) && $schedule->agentId) $ids = [$schedule->agentId];
		$available = [];
		foreach($ids as $id) {
			$agent = $agents->getById($id);
			if($agent) $available[] = $agent;
		}
		if(count($available) === 1) return $available[0];
		if(count($available) > 1) {
			$next = 0;
			foreach($available as $index => $agent) {
				/** @var AgentToolsAgent $agent */
				if($agent->id === $schedule->lastAgentId) {
					$next = ($index + 1) % count($available);
					break;
				}
			}
			return $available[$next];
		}
		if($schedule->agentName) {
			foreach($agents as $agent) {
				/** @var AgentToolsAgent $agent */
				if($agent->model === $schedule->agentName) return $agent;
			}
		}
		return $agents->first() ?: null;
	}

	/**
	 * Calculate the next run timestamp.
	 *
	 * @param AgentToolsScheduledTask $task
	 * @param int $after
	 * @return int
	 *
	 */
	public function calculateNextRun(AgentToolsScheduledTask $task, int $after = 0): int {
		if($after < 1) $after = time();
		$frequency = $task->frequency;
		$intervals = [
			'15-minutes' => 15 * 60,
			'30-minutes' => 30 * 60,
			'hour' => 60 * 60,
			'2-hours' => 2 * 60 * 60,
			'4-hours' => 4 * 60 * 60,
			'6-hours' => 6 * 60 * 60,
			'12-hours' => 12 * 60 * 60,
		];
		if(isset($intervals[$frequency])) return $after + $intervals[$frequency];
		[$hour, $minute] = $this->parseTime($task->time);
		if($frequency === 'day') {
			$next = mktime($hour, $minute, 0, (int) date('n', $after), (int) date('j', $after), (int) date('Y', $after));
			if($next <= $after) $next = strtotime('+1 day', $next);
			return (int) $next;
		}
		if($frequency === 'week') {
			$weekday = max(1, min(7, (int) $task->weekday));
			$today = (int) date('N', $after);
			$days = ($weekday - $today + 7) % 7;
			$base = strtotime("+$days days", $after);
			$next = mktime($hour, $minute, 0, (int) date('n', $base), (int) date('j', $base), (int) date('Y', $base));
			if($next <= $after) $next = strtotime('+1 week', $next);
			return (int) $next;
		}
		if($frequency === 'month') {
			$year = (int) date('Y', $after);
			$month = (int) date('n', $after);
			$next = $this->makeMonthTime($year, $month, (int) $task->monthday, $hour, $minute);
			if($next <= $after) {
				$month++;
				if($month > 12) {
					$month = 1;
					$year++;
				}
				$next = $this->makeMonthTime($year, $month, (int) $task->monthday, $hour, $minute);
			}
			return $next;
		}
		return $after + 86400;
	}

	/**
	 * Parse HH:MM schedule time.
	 *
	 * @param string $time
	 * @return array{0:int,1:int}
	 *
	 */
	protected function parseTime(string $time): array {
		if(!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) return [6, 0];
		$hour = max(0, min(23, (int) $m[1]));
		$minute = max(0, min(59, (int) $m[2]));
		return [$hour, $minute];
	}

	/**
	 * Make a timestamp for a month schedule, clamped to month length.
	 *
	 * @param int $year
	 * @param int $month
	 * @param int $day
	 * @param int $hour
	 * @param int $minute
	 * @return int
	 *
	 */
	protected function makeMonthTime(int $year, int $month, int $day, int $hour, int $minute): int {
		$lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
		$day = max(1, min($day, $lastDay));
		return (int) mktime($hour, $minute, 0, $month, $day, $year);
	}

	/**
	 * Get scheduled task file
	 *
	 * @param AgentToolsScheduledTask $task
	 * @return string
	 *
	 */
	public function getTaskFile(AgentToolsScheduledTask $task): string {
		return $this->getPath() . strtolower($task->name) . '.json';
	}

	/**
	 * Get schedule JSON files.
	 *
	 * @return array
	 *
	 */
	protected function getFiles(): array {
		$files = glob($this->getPath() . '*.json');
		if(!$files) return [];
		sort($files, SORT_STRING);
		return $files;
	}

	/**
	 * Get storage path
	 *
	 * @return string
	 *
	 */
	protected function getPath(): string {
		return $this->at->getFilesPath('schedules');
	}

	/**
	 * @return \ArrayIterator<int, AgentToolsScheduledTask>
	 *
	 */
	public function getIterator(): \ArrayIterator {
		return new \ArrayIterator($this->data);
	}
}
