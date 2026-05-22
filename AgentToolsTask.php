<?php namespace ProcessWire;

/**
 * Agent Tools Task
 *
 * Represents a reusable agent workflow/prompt that can be run from the admin.
 *
 * @property string $name
 * @property string $title
 * @property string $summary
 * @property string $description
 * @property string $icon
 * @property string $mode
 * @property array $inputs
 * @property string $prompt
 * @property bool|int $builtIn
 * @property bool|int $admin
 * @property bool|int $scheduleable
 * @property int $maxIterations
 * @property string $sourceFile
 * @property string $model
 *
 */
class AgentToolsTask extends WireData {

	protected $defaults = [
		'name' => '',
		'title' => '',
		'summary' => '',
		'description' => '',
		'icon' => 'tasks',
		'mode' => 'review',
		'inputs' => [],
		'prompt' => '',
		'builtIn' => 0,
		'admin' => 1,
		'scheduleable' => 0,
		'maxIterations' => 0,
		'sourceFile' => '',
		'model' => '',
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

	/**
	 * Set task property
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return WireData
	 *
	 */
	public function set($key, $value) {
		if(!array_key_exists($key, $this->defaults)) return parent::set($key, $value);
		if(is_string($this->defaults[$key])) {
			$value = trim("$value");
		} else if(is_array($this->defaults[$key])) {
			if(!is_array($value)) $value = [];
		} else if(is_int($this->defaults[$key])) {
			if(in_array($key, ['builtIn', 'admin', 'scheduleable'], true)) {
				$value = (int) (bool) $value;
			} else {
				$value = (int) $value;
			}
		}
		if($key === 'name') {
			$value = $this->wire()->sanitizer->pageName($value, true);
		}
		return parent::set($key, $value);
	}

	/**
	 * Return input default values indexed by input name
	 *
	 * @return array
	 *
	 */
	public function getDefaultInput(): array {
		$values = [];
		foreach($this->inputs as $name => $definition) {
			if(!is_array($definition)) continue;
			if(isset($definition['value'])) {
				$values[$name] = $definition['value'];
			} else if(isset($definition['default'])) {
				$values[$name] = $definition['default'];
			}
		}
		return $values;
	}

	/**
	 * Render the task prompt with optional input values
	 *
	 * @param array $input
	 * @return string
	 *
	 */
	public function renderPrompt(array $input = []): string {
		$values = array_merge($this->getDefaultInput(), $input);
		$prompt = $this->prompt;
		foreach($values as $name => $value) {
			$prompt = str_replace('{' . $name . '}', $this->renderValue($value), $prompt);
		}
		return trim($prompt);
	}

	/**
	 * Render value for prompt token replacement
	 *
	 * @param mixed $value
	 * @return string
	 *
	 */
	protected function renderValue($value): string {
		if(is_bool($value)) return $value ? 'yes' : 'no';
		if(is_scalar($value) || $value === null) return trim("$value");
		return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Get Inputfields (form) to provide interactive configuration for this task
	 *
	 * @param InputfieldWrapper|null $inputfields Wrapper to populate with task config inputs, or omit to create InputfieldForm
	 * @return InputfieldForm|InputfieldWrapper
	 *
	 */
	public function getConfigInputfields(?InputfieldWrapper $inputfields = null) {
		if($inputfields === null) $inputfields = $this->wire()->modules->get('InputfieldForm');
		return $inputfields->importArray($this->inputs);
	}
}
