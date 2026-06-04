<?php namespace ProcessWire;

/**
 * AgentTools run trace.
 *
 * @property string $id Unique trace ID.
 * @property string $type Run type: engineer, task, or page-engineer.
 * @property string $status Trace status: running, success, or error.
 * @property int $started Unix timestamp when the run started.
 * @property int $finished Unix timestamp when the run finished.
 * @property int $durationMs Run duration in milliseconds.
 * @property string $provider AI provider identifier.
 * @property string $model AI model identifier.
 * @property string $agentId Stable AgentTools agent ID.
 * @property string $agentLabel Human-readable agent label.
 * @property string $endpointHost API endpoint host name.
 * @property bool $backgroundJob Whether this trace belongs to a background job.
 * @property int $maxIterations Maximum tool-use rounds allowed for the run.
 * @property int $toolRounds Number of tool-use rounds recorded.
 * @property array<int,array<string,mixed>> $toolCalls Recorded tool call summaries.
 * @property array<int,string> $apiDocs API docs referenced by tool calls.
 * @property array<int,string> $filesRead Files read by tool calls.
 * @property array<int,string> $siteInfo Site info types requested by tool calls.
 * @property array<int,string> $migrations Migrations created by tool calls.
 * @property int $requestLength Request length in bytes.
 * @property int $responseLength Response length in bytes.
 * @property string $request Optional stored request text.
 * @property string $response Optional stored response text.
 * @property string $error Error message, when the run failed.
 * @property string $traceFile Root-relative trace JSON file path.
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

	/**
	 * Construct a trace object.
	 *
	 * @param array<string,mixed> $data Initial trace data.
	 *
	 */
	public function __construct(array $data = []) {
		parent::__construct();
		$this->setArray($this->defaults);
		if($data) $this->setArray($data);
	}

	/**
	 * Set a trace property, normalizing known values to their default types.
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return WireData
	 *
	 */
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

	/**
	 * Return trace data as an array.
	 *
	 * @return array<string,mixed>
	 *
	 */
	public function toArray(): array {
		return $this->getArray();
	}
}
