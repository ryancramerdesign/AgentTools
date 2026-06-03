<?php namespace ProcessWire;

/**
 * Agent Tools Agent
 *
 * @property string $provider
 * @property string $id
 * @property string $model
 * @property string $agentName
 * @property string $apiKey
 * @property string $endpointUrl
 * @property string $label
 * @property string $description
 *
 *
 */
class AgentToolsAgent extends WireData {

	protected $defaults = [
		'provider' => 'openai',
		'id' => '',
		'model' => '',
		'label' => '',
		'agentName' => '',
		'apiKey' => '' ,
		'endpointUrl' => '',
		'description' => '',
	];

	/**
	 * Construct
	 *
	 * @param string|array|null $data Optional array or "|" separated string of agent data
	 *
	 */
	public function __construct($data = null) {
		parent::__construct();
		$this->setArray($this->defaults);
		if($data === null) return;
		if(is_array($data)) {
			$this->setArray($data);
		} else if(is_string($data)) {
			$this->setString($data);
		}
	}

	public function set($key, $value) {
		if(isset($this->defaults[$key])) {
			$value = str_replace('|', ' ',  $value);
			$value = trim("$value");
		}
		if($key === 'id') {
			$value = strtolower(preg_replace('/[^-_a-zA-Z0-9]+/', '-', $value));
			$value = trim($value, '-');
		}
		if($key === 'apiKey') {
			parent::set('provider', strpos($value, 'sk-ant') === 0 ? 'anthropic' : 'openai');
		}
		return parent::set($key, $value);
	}

	/**
	 * Set string of agent data
	 *
	 * @param string $line String in format:
	 *  - "model | api-key | endpoint-url | label" or
	 *  - "provider | model | api-key | endpoint-url | label | description | id | agentName"
	 * @return bool
	 *
	 */
	public function setString($line) {
		if(strpos($line, '|') === false) return false;
		$line = trim($line);
		$parts = explode('|', $line);
		foreach($parts as $key => $part) $parts[$key] = trim($part);
		$first = $parts[0] ?? '';
		$explicitProvider = false;
		if($first === 'anthropic' || $first === 'openai') {
			parent::set('provider', array_shift($parts));
			$explicitProvider = true;
		}
		$this->model = array_shift($parts);
		$this->apiKey = array_shift($parts);
		if(count($parts)) $this->endpointUrl = array_shift($parts);
		if(count($parts)) $this->label = array_shift($parts);
		if(count($parts)) $this->description = array_shift($parts);
		if(count($parts)) $this->id = array_shift($parts);
		if(count($parts)) $this->agentName = array_shift($parts);
		// Auto-detect Anthropic-compatible endpoints when provider was not explicitly specified
		if(!$explicitProvider && $this->endpointUrl) {
			$path = (string) parse_url($this->endpointUrl, PHP_URL_PATH);
			if(str_ends_with($path, '/messages')) parent::set('provider', 'anthropic');
		}
		return true;
	}

	public function getString() {
		return trim(
			"$this->model | $this->apiKey | $this->endpointUrl | $this->label | " .
			"$this->description | $this->id | $this->agentName",
			'| '
		);
	}

	public function __toString() {
		return $this->getString();
	}

	/**
	 * Ask a question and get a text response
	 *
	 * This is the primary public API for sending a request to the agent. It handles
	 * message formatting and returns a plain text response.
	 *
	 * ~~~~~
	 * $agent = $at->getPrimaryAgent();
	 * $answer = $agent->ask('What is the capital of France?');
	 *
	 * // With a system prompt:
	 * $answer = $agent->ask('Summarize this content: ' . $body, 'You are a concise copywriter.');
	 *
	 * // With provider-specific options:
	 * $answer = $agent->ask('Draft a tagline.', '', [
	 *   'timeout' => 30,
	 *   'openai' => ['temperature' => 0.9, 'max_output_tokens' => 256],
	 * ]);
	 * ~~~~~
	 *
	 * @param string $question The question or request to send
	 * @param string $systemPrompt Optional instructions for the AI (leave blank for none)
	 * @param array $options Optional request options — see sendRequest() for supported keys
	 * @return string Text response from the AI, or error message string if the request failed
	 *
	 */
	public function ask(string $question, string $systemPrompt = '', array $options = []): string {
		$at = $this->wire('at'); /** @var AgentTools $at */
		$messages = [['role' => 'user', 'content' => $question]];
		try {
			$response = $this->sendRequest($systemPrompt, $messages, [], $options);
			return $at->engineer->extractText($this->provider, $response);
		} catch(\Throwable $e) {
			return $e->getMessage();
		}
	}

	/**
	 * Send request to agent using an AgentToolsRequest object (advanced)
	 *
	 * Prefer this over sendRequest() for new code — the request object is self-documenting,
	 * hookable, and extensible without signature changes.
	 *
	 * The request must already contain provider connection details. Construct it with
	 * `new AgentToolsRequest($agent)` to populate provider, model, API key, and endpoint
	 * from this agent.
	 *
	 * ~~~~~
	 * $request = new AgentToolsRequest($agent);
	 * $request->systemPrompt = 'You are a concise assistant.';
	 * $request->messages = [['role' => 'user', 'content' => 'List templates.']];
	 * $request->options = ['openai' => ['reasoning_effort' => 'high']];
	 * $response = $agent->sendProviderRequest($request);
	 * ~~~~~
	 *
	 * #pw-advanced
	 *
	 * @param AgentToolsRequest $request
	 * @return array Raw provider response — check provider docs for structure
	 *
	 */
	public function sendProviderRequest(AgentToolsRequest $request): array {
		$at = $this->wire('at'); /** @var AgentTools $at */
		return $at->engineer->sendProviderRequest($request);
	}

	/**
	 * Send request to agent with positional arguments (backwards-compatible)
	 *
	 * For new code, construct an AgentToolsRequest and use sendProviderRequest() instead.
	 * This method remains for backwards compatibility.
	 *
	 * #pw-advanced
	 *
	 * @param string $systemPrompt System prompt, or empty string for none
	 * @param array $messages Array of message objects: [['role' => 'user'|'assistant', 'content' => '...'], ...]
	 * @param array $tools Tool definitions in provider format (Anthropic or OpenAI)
	 * @param array $options Optional request options — see AgentToolsRequest $options for keys
	 * @return array Raw provider response — check provider docs for structure
	 *
	 */
	public function sendRequest(string $systemPrompt, array $messages, array $tools = [], array $options = []): array {
		$request = new AgentToolsRequest($this);
		$request->systemPrompt = $systemPrompt;
		$request->messages = $messages;
		$request->tools = $tools;
		$request->options = $options;
		return $this->sendProviderRequest($request);
	}

	public function getHash() {
		return md5("$this->model|$this->apiKey|$this->endpointUrl|$this->label|$this->description|$this->agentName");
	}

	/**
	 * Ensure this agent has a stable ID.
	 *
	 * @param array $existingIDs
	 * @return string
	 *
	 */
	public function ensureId(array $existingIDs = []): string {
		if($this->id && !in_array($this->id, $existingIDs, true)) return $this->id;
		do {
			$id = date('YmdHis') . '-' . bin2hex(random_bytes(4));
		} while(in_array($id, $existingIDs, true));
		$this->id = $id;
		return $id;
	}
}
