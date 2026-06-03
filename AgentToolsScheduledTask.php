<?php namespace ProcessWire;

/**
 * Agent Tools Scheduled Task
 *
 * @property string $name
 * @property string $title
 * @property string $task
 * @property string $status
 * @property string $notifyEmail
 * @property string $agentId
 * @property array $agentIds
 * @property string $lastAgentId
 * @property string $agentName
 * @property array $inputs
 * @property string $frequency
 * @property string $schedule
 * @property string $time
 * @property int $weekday
 * @property int $monthday
 * @property int $lastRun
 * @property int $nextRun
 * @property int $createdUserId
 * @property int $created
 * @property int $modified
 *
 */
class AgentToolsScheduledTask extends WireData {

	protected $defaults = [
		'name' => '',
		'title' => '',
		'task' => '',
		'status' => 'paused',
		'notifyEmail' => '',
		'agentId' => '',
		'agentIds' => [],
		'lastAgentId' => '',
		'agentName' => '',
		'inputs' => [],
		'frequency' => '',
		'schedule' => '', // @todo do we want 'frequency', 'schedule' or both?
		'time' => '',
		'weekday' => 1,
		'monthday' => 1,
		'lastRun' => 0,
		'nextRun' => 0,
		'createdUserId' => 0,
		'created' => 0,
		'modified' => 0,
	];

	/**
	 * Construct
	 *
	 * @param array|null $data
	 *
	 */
	public function __construct(?array $data = null) {
		parent::__construct();
		$this->setArray($this->defaults);
		if($data) $this->setArray($data);
	}

	public function set($key, $value) {
		if(array_key_exists($key, $this->defaults)) {
			$default = $this->defaults[$key];
			if(is_int($default)) {
				$value = (int) "$value";
			} else if(is_string($default)) {
				$value = (string) $value;
			} else if(is_array($default)) {
				if(!is_array($value)) {
					$value = $key === 'agentIds' && strlen((string) $value) ? explode(',', (string) $value) : [];
				}
			}
			if($key === 'status' && ($value !== 'paused' && $value !== 'active')) {
				$value = 'paused';
			}
			if($key === 'agentIds') {
				$ids = [];
				foreach($value as $id) {
					$id = $this->wire()->sanitizer->pageName($id);
					if($id !== '') $ids[] = $id;
				}
				$value = array_values(array_unique($ids));
			}
			if($key === 'name' || $key === 'task' || $key === 'agentId' || $key === 'lastAgentId') {
				$value = $this->wire()->sanitizer->pageName($value);
			}
		}
		return parent::set($key, $value);
	}

	/**
	 * Is the given property part of the scheduled task data model?
	 *
	 * @param string $key
	 * @return bool
	 *
	 */
	public function hasProperty(string $key): bool {
		return array_key_exists($key, $this->defaults);
	}
}
