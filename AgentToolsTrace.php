<?php namespace ProcessWire;

/**
 * AgentTools run trace.
 *
 */
class AgentToolsTrace extends WireData {

	protected $defaults = [
		'id' => '',
		'type' => 'engineer',
		'status' => 'running',
		'started' => 0,
		'finished' => 0,
		'durationMs' => 0,
		'provider' => '',
		'model' => '',
		'agentId' => '',
		'agentLabel' => '',
		'endpointHost' => '',
		'backgroundJob' => false,
		'maxIterations' => 0,
		'toolRounds' => 0,
		'toolCalls' => [],
		'apiDocs' => [],
		'filesRead' => [],
		'siteInfo' => [],
		'migrations' => [],
		'requestLength' => 0,
		'responseLength' => 0,
		'request' => '',
		'response' => '',
		'error' => '',
		'traceFile' => '',
	];

	public function __construct(array $data = []) {
		parent::__construct();
		$this->setArray($this->defaults);
		if($data) $this->setArray($data);
	}

	public function set($key, $value) {
		if(array_key_exists($key, $this->defaults)) {
			$default = $this->defaults[$key];
			if(is_int($default)) {
				$value = (int) $value;
			} else if(is_bool($default)) {
				$value = (bool) $value;
			} else if(is_array($default)) {
				$value = is_array($value) ? $value : [];
			} else {
				$value = (string) $value;
			}
		}
		return parent::set($key, $value);
	}

	public function toArray(): array {
		return $this->getArray();
	}
}
