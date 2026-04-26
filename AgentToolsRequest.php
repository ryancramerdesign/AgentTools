<?php namespace ProcessWire;

/**
 * Agent Tools Request
 *
 * Encapsulates all parameters for a single provider request. Pass an instance
 * to `AgentToolsAgent::sendProviderRequest()` or `AgentToolsEngineer::sendProviderRequest()`.
 *
 * ~~~~~~
 * $agent = $at->getPrimaryAgent();
 *
 * // create a new request
 * $request = new AgentToolsRequest($agent);
 * $request->systemPrompt = 'You are a concise assistant.';
 * $request->messages = [['role' => 'user', 'content' => 'List the top 3 templates.']];
 * $request->options = ['openai' => ['reasoning_effort' => 'high']];
 *
 * // send the request and get a response
 * $response = $agent->sendProviderRequest($request);
 * ~~~~~~
 *
 * Hook example — modify the request before dispatch:
 * ~~~~~~
 * $wire->addHookBefore('AgentToolsEngineer::sendProviderRequest', function(HookEvent $e) {
 *     $request = $e->arguments(0); // AgentToolsRequest
 *     $request->options['openai']['temperature'] = 0.2;
 * });
 * ~~~~~~
 *
 * Copyright 2026 Ryan Cramer and Claude (Anthropic) | MIT
 *
 * @property string $provider Provider name: 'anthropic' or 'openai'
 * @property string $apiKey API key for the provider
 * @property string $model Model ID to use
 * @property string $endpoint Base endpoint URL (OpenAI-compatible providers only)
 * @property string $systemPrompt System prompt, or empty string for none
 * @property array $messages Array of message objects: [['role' => 'user'|'assistant', 'content' => '...'], ...]
 * @property array $tools Tool definitions in provider format
 * @property array $options Request options: timeout (int), anthropic (array), openai (array)
 *
 */
class AgentToolsRequest extends WireData {

	/**
	 * @var AgentToolsAgent|null
	 *
	 */
	protected $agent = null;

	/**
	 * Construct
	 *
	 * @param AgentToolsAgent|null $agent Optional agent to populate provider/model/apiKey/endpoint from
	 *
	 */
	public function __construct(?AgentToolsAgent $agent = null) {
		parent::__construct();
		$this->setArray([
			'provider' => 'openai',
			'apiKey' => '',
			'model' => '',
			'endpoint' => '',
			'systemPrompt' => '',
			'messages' => [],
			'tools' => [],
			'options' => [],
		]);
		if($agent !== null) $this->setAgent($agent);
	}

	/**
	 * Populate connection properties from an AgentToolsAgent
	 *
	 * Copies provider, apiKey, model, and endpoint from the agent. Any of these
	 * can be overridden afterwards by setting the property directly.
	 *
	 * @param AgentToolsAgent $agent
	 * @return self
	 *
	 */
	public function setAgent(AgentToolsAgent $agent): self {
		$this->agent = $agent;
		foreach(['provider', 'apiKey', 'model', 'endpointUrl'] as $key) {
			$this->set($key, $agent->get($key));
		}
		return $this;
	}

	/**
	 * Get the agent this request was populated from, if any
	 *
	 * @return AgentToolsAgent|null
	 *
	 */
	public function getAgent(): ?AgentToolsAgent {
		return $this->agent;
	}

	/**
	 * Set a property, normalizing 'endpointUrl' to 'endpoint' for AgentToolsAgent compatibility
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return Wire
	 *
	 */
	public function set($key, $value) {
		if($key === 'endpointUrl') $key = 'endpoint';
		return parent::set($key, $value);
	}
}
