<?php namespace ProcessWire;

/**
 * Agent Tools Engineer
 *
 * Provides a natural language interface to the ProcessWire API via an AI
 * assistant. Supports informational queries (answered via eval_php tool)
 * and site changes (saved as migration files for user review via
 * save_migration tool).
 *
 * Supports Anthropic (Claude) and OpenAI-compatible providers.
 *
 * Copyright 2026 Ryan Cramer and Claude (Anthropic) | MIT
 *
 */
class AgentToolsEngineer extends AgentToolsHelper {

	const providerAnthropic = 'anthropic';
	const providerOpenAI = 'openai';

	const defaultAnthropicModel = 'claude-sonnet-4-6';
	const defaultOpenAIModel = 'gpt-4o';

	/**
	 * Max tokens to request in each API response
	 *
	 */
	const maxTokens = 8192;

	/**
	 * Max tool call iterations per ask() to prevent runaway loops
	 *
	 */
	const maxIterations = 10;

	/**
	 * Max characters of eval_php output returned to the AI
	 *
	 */
	const maxOutputLength = 50000;

	/**
	 * Migration file saved during the current ask() call, if any
	 *
	 * @var string|null
	 *
	 */
	protected $savedMigration = null;

	/**
	 * Ask the engineer a question or request a site change
	 *
	 * @param string $request
	 * @return array [ 'response' => string, 'migration' => string|null, 'error' => string|null ]
	 *
	 */
	public function ask(string $request): array {

		$this->savedMigration = null;
		$result = ['response' => '', 'migration' => null, 'error' => null];

		try {
			$apiKey = (string) $this->at->get('engineer_api_key');
			if(!$apiKey) throw new WireException($this->_('API key is not configured in AgentTools module settings.'));

			$provider = (string) $this->at->get('engineer_provider') ?: self::providerAnthropic;
			$systemPrompt = $this->buildSystemPrompt();
			$tools = $this->getToolDefinitions($provider);
			$messages = [['role' => 'user', 'content' => $request]];

			for($i = 0; $i < self::maxIterations; $i++) {
				$response = $this->sendRequest($provider, $systemPrompt, $messages, $tools);
				$toolCalls = $this->extractToolCalls($provider, $response);

				if(empty($toolCalls)) {
					$result['response'] = $this->extractText($provider, $response);
					$result['migration'] = $this->savedMigration;
					return $result;
				}

				$this->appendAssistantMessage($provider, $messages, $response);

				foreach($toolCalls as $toolCall) {
					$output = $this->executeTool($toolCall['name'], $toolCall['input']);
					$this->appendToolResult($provider, $messages, $toolCall, $output);
				}
			}

			$result['error'] = $this->_('Request exceeded maximum tool call iterations.');

		} catch(\Throwable $e) {
			$result['error'] = $e->getMessage();
		}

		return $result;
	}

	/**
	 * Build the system prompt, including site map and schema context if available
	 *
	 * @return string
	 *
	 */
	protected function buildSystemPrompt(): string {

		$siteUrl = $this->wire()->config->httpRoot;

		$prompt =
			"You are an expert ProcessWire CMS engineer with complete knowledge of the ProcessWire API " .
			"and full access to this specific installation.\n\n" .

			"For informational requests, respond with clear concise text. Use the eval_php tool when " .
			"you need to query live site data not available in the site map or schema provided below.\n\n" .

			"For requests that make changes to the site (creating or modifying fields, templates, pages, " .
			"content, etc.), always use the save_migration tool rather than applying changes directly via " .
			"eval_php. This allows the user to review changes before they are applied.\n\n" .

			"ProcessWire API variables available to eval_php: \$pages, \$fields, \$templates, \$modules, " .
			"\$users, \$roles, \$permissions, \$config, \$at (AgentTools module instance).\n\n" .

			"When referencing pages by path in your response, format them as markdown links using this " .
			"site's base URL: $siteUrl (e.g. a page at /blog/post/ becomes [$siteUrl" . "blog/post/]($siteUrl" . "blog/post/)).\n\n" .

			"If a request is ambiguous, incomplete, or lacks sufficient context to act on confidently " .
			"(for example, it references previous context you don't have), ask the user for clarification " .
			"rather than guessing. Do not attempt to execute or create a migration for an ambiguous request.";

		$siteMapFile = $this->at->getFilesPath() . 'site-map.json';
		if(is_file($siteMapFile)) {
			$prompt .= "\n\n[SITE MAP]\n" . file_get_contents($siteMapFile);
		}

		$schemaFile = $this->at->getFilesPath() . 'site-map-schema.json';
		if(is_file($schemaFile)) {
			$prompt .= "\n\n[SCHEMA]\n" . file_get_contents($schemaFile);
		}

		$apiDocs = $this->getFieldtypeApiDocs();
		if($apiDocs) {
			$prompt .= "\n\n[FIELDTYPE API REFERENCE]\n" . $apiDocs;
		}

		return $prompt;
	}

	/**
	 * Get Fieldtype API documentation from API.md files in the ProcessWire installation
	 *
	 * Reads API.md files from wire/modules/Fieldtype/ subdirectories if they exist.
	 * Returns empty string if PW root cannot be determined or no API.md files are found.
	 *
	 * @return string
	 *
	 */
	protected function getFieldtypeApiDocs(): string {
		$root = $this->wire()->config->paths->root;
		$fieldtypePath = $root . 'wire/modules/Fieldtype/';
		if(!is_dir($fieldtypePath)) return '';

		$docs = '';

		// API.md files in subdirectory fieldtypes
		foreach(glob($fieldtypePath . '*/API.md') as $file) {
			$docs .= file_get_contents($file) . "\n\n";
		}

		// Flat API.md shared by simple fieldtypes (directly in Fieldtype/)
		$flatApiFile = $fieldtypePath . 'API.md';
		if(is_file($flatApiFile)) {
			$docs .= file_get_contents($flatApiFile) . "\n\n";
		}

		return trim($docs);
	}

	/**
	 * Get tool definitions formatted for the given provider
	 *
	 * @param string $provider
	 * @return array
	 *
	 */
	protected function getToolDefinitions(string $provider): array {

		$evalDesc =
			"Evaluate PHP code with full ProcessWire API access. Use echo to output results. " .
			"Available variables: \$pages, \$fields, \$templates, \$modules, \$users, \$roles, " .
			"\$permissions, \$config, \$at. Do not include an opening <?php tag.";

		$migrationDesc =
			"Save a PHP migration file for the user to review and apply. Use for any changes to the site. " .
			"The code must be a complete PHP file beginning with: <?php namespace ProcessWire;";

		$evalParams = [
			'type' => 'object',
			'properties' => [
				'code' => ['type' => 'string', 'description' => 'PHP code to evaluate, without opening <?php tag'],
			],
			'required' => ['code'],
		];

		$migrationParams = [
			'type' => 'object',
			'properties' => [
				'code' => ['type' => 'string', 'description' => 'Complete PHP migration file contents, beginning with <?php namespace ProcessWire;'],
				'description' => ['type' => 'string', 'description' => 'Short snake_case description for the filename, e.g. add_toggles_field'],
			],
			'required' => ['code', 'description'],
		];

		if($provider === self::providerAnthropic) {
			return [
				['name' => 'eval_php', 'description' => $evalDesc, 'input_schema' => $evalParams],
				['name' => 'save_migration', 'description' => $migrationDesc, 'input_schema' => $migrationParams],
			];
		} else {
			return [
				['type' => 'function', 'function' => ['name' => 'eval_php', 'description' => $evalDesc, 'parameters' => $evalParams]],
				['type' => 'function', 'function' => ['name' => 'save_migration', 'description' => $migrationDesc, 'parameters' => $migrationParams]],
			];
		}
	}

	/**
	 * Send a request to the configured AI provider
	 *
	 * @param string $provider
	 * @param string $systemPrompt
	 * @param array $messages
	 * @param array $tools
	 * @return array
	 *
	 */
	protected function sendRequest(string $provider, string $systemPrompt, array $messages, array $tools): array {
		$apiKey = (string) $this->at->get('engineer_api_key');
		$model = (string) $this->at->get('engineer_model');

		if($provider === self::providerAnthropic) {
			if(!$model) $model = self::defaultAnthropicModel;
			return $this->sendAnthropicRequest($apiKey, $model, $systemPrompt, $messages, $tools);
		} else {
			if(!$model) $model = self::defaultOpenAIModel;
			$endpoint = rtrim((string) $this->at->get('engineer_endpoint') ?: 'https://api.openai.com/v1', '/');
			return $this->sendOpenAIRequest($apiKey, $model, $endpoint, $systemPrompt, $messages, $tools);
		}
	}

	/**
	 * Send request to Anthropic Messages API
	 *
	 * @param string $apiKey
	 * @param string $model
	 * @param string $system
	 * @param array $messages
	 * @param array $tools
	 * @return array
	 *
	 */
	protected function sendAnthropicRequest(string $apiKey, string $model, string $system, array $messages, array $tools): array {
		return $this->curlPost(
			'https://api.anthropic.com/v1/messages',
			[
				'model' => $model,
				'max_tokens' => self::maxTokens,
				'system' => $system,
				'messages' => $messages,
				'tools' => $tools,
			],
			[
				'x-api-key: ' . $apiKey,
				'anthropic-version: 2023-06-01',
				'content-type: application/json',
			]
		);
	}

	/**
	 * Send request to OpenAI-compatible Chat Completions API
	 *
	 * @param string $apiKey
	 * @param string $model
	 * @param string $endpoint Base URL, e.g. https://api.openai.com/v1
	 * @param string $system
	 * @param array $messages
	 * @param array $tools
	 * @return array
	 *
	 */
	protected function sendOpenAIRequest(string $apiKey, string $model, string $endpoint, string $system, array $messages, array $tools): array {
		return $this->curlPost(
			$endpoint . '/chat/completions',
			[
				'model' => $model,
				'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
				'tools' => $tools,
			],
			[
				'Authorization: Bearer ' . $apiKey,
				'content-type: application/json',
			]
		);
	}

	/**
	 * Execute a tool call and return its output as a string
	 *
	 * @param string $name Tool name
	 * @param array $input Tool input arguments
	 * @return string
	 *
	 */
	protected function executeTool(string $name, array $input): string {
		if($name === 'eval_php') {
			return $this->executeEvalPhp((string) ($input['code'] ?? ''));
		} else if($name === 'save_migration') {
			return $this->executeSaveMigration(
				(string) ($input['code'] ?? ''),
				(string) ($input['description'] ?? 'migration')
			);
		}
		return "Unknown tool: $name";
	}

	/**
	 * Execute PHP code with full ProcessWire API access
	 *
	 * @param string $code PHP code without opening <?php tag
	 * @return string Captured output, truncated to maxOutputLength
	 *
	 */
	protected function executeEvalPhp(string $code): string {
		$at = $this->at;
		extract($this->wire()->fuel->getArray());
		ob_start();
		try {
			eval('?>' . '<?php namespace ProcessWire; ' . $code);
		} catch(\Throwable $e) {
			echo "ERROR: " . $e->getMessage();
		}
		$output = ob_get_clean();
		if(strlen($output) > self::maxOutputLength) {
			$output = substr($output, 0, self::maxOutputLength) . "\n[output truncated]";
		}
		return $output;
	}

	/**
	 * Save a migration file for user review
	 *
	 * @param string $code Complete PHP migration file contents
	 * @param string $description Short snake_case description for filename
	 * @return string Confirmation message or error
	 *
	 */
	protected function executeSaveMigration(string $code, string $description): string {
		$description = preg_replace('/[^a-z0-9]+/', '_', strtolower($description));
		$description = trim($description, '_');
		if(!$description) $description = 'migration';
		$filename = date('YmdHis') . '_' . $description . '.php';
		$path = $this->at->getFilesPath('migrations') . $filename;
		if(file_put_contents($path, $code) !== false) {
			$this->savedMigration = $path;
			return "Migration saved: $filename";
		}
		return "ERROR: Failed to save migration file.";
	}

	/**
	 * Extract tool calls from an API response
	 *
	 * @param string $provider
	 * @param array $response
	 * @return array Each item: [ 'id' => string, 'name' => string, 'input' => array ]
	 *
	 */
	protected function extractToolCalls(string $provider, array $response): array {
		$calls = [];
		if($provider === self::providerAnthropic) {
			if(($response['stop_reason'] ?? '') !== 'tool_use') return [];
			foreach($response['content'] ?? [] as $block) {
				if(($block['type'] ?? '') === 'tool_use') {
					$calls[] = ['id' => $block['id'], 'name' => $block['name'], 'input' => $block['input']];
				}
			}
		} else {
			$choice = $response['choices'][0] ?? [];
			if(($choice['finish_reason'] ?? '') !== 'tool_calls') return [];
			foreach($choice['message']['tool_calls'] ?? [] as $tc) {
				$calls[] = [
					'id' => $tc['id'],
					'name' => $tc['function']['name'],
					'input' => json_decode($tc['function']['arguments'], true) ?? [],
				];
			}
		}
		return $calls;
	}

	/**
	 * Extract the final text response from an API response
	 *
	 * @param string $provider
	 * @param array $response
	 * @return string
	 *
	 */
	protected function extractText(string $provider, array $response): string {
		if($provider === self::providerAnthropic) {
			$parts = [];
			foreach($response['content'] ?? [] as $block) {
				if(($block['type'] ?? '') === 'text') $parts[] = $block['text'];
			}
			return implode("\n", $parts);
		} else {
			return (string) ($response['choices'][0]['message']['content'] ?? '');
		}
	}

	/**
	 * Append the assistant's response to the messages array for the next iteration
	 *
	 * @param string $provider
	 * @param array $messages Modified in place
	 * @param array $response
	 *
	 */
	protected function appendAssistantMessage(string $provider, array &$messages, array $response): void {
		if($provider === self::providerAnthropic) {
			$messages[] = ['role' => 'assistant', 'content' => $response['content']];
		} else {
			$messages[] = $response['choices'][0]['message'];
		}
	}

	/**
	 * Append a tool result to the messages array
	 *
	 * Anthropic batches all tool results into a single user message;
	 * OpenAI uses one tool message per result.
	 *
	 * @param string $provider
	 * @param array $messages Modified in place
	 * @param array $toolCall The tool call this result belongs to
	 * @param string $output The tool's output
	 *
	 */
	protected function appendToolResult(string $provider, array &$messages, array $toolCall, string $output): void {
		if($provider === self::providerAnthropic) {
			$last = end($messages);
			$resultBlock = ['type' => 'tool_result', 'tool_use_id' => $toolCall['id'], 'content' => $output];
			if($last && $last['role'] === 'user' && is_array($last['content'])) {
				$messages[count($messages) - 1]['content'][] = $resultBlock;
			} else {
				$messages[] = ['role' => 'user', 'content' => [$resultBlock]];
			}
		} else {
			$messages[] = ['role' => 'tool', 'tool_call_id' => $toolCall['id'], 'content' => $output];
		}
	}

	/**
	 * Make a cURL POST request with a JSON body and return the decoded response
	 *
	 * @param string $url
	 * @param array $payload Request body (will be JSON encoded)
	 * @param array $headers HTTP headers in "Name: value" format
	 * @return array Decoded JSON response
	 * @throws WireException on network error, non-JSON response, or HTTP error status
	 *
	 */
	protected function curlPost(string $url, array $payload, array $headers): array {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($payload),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 120,
		]);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if($response === false) throw new WireException("API request failed: $curlError");

		$data = json_decode($response, true);
		if(!is_array($data)) throw new WireException("Invalid API response: expected JSON");

		if($httpCode >= 400) {
			$error = $data['error']['message'] ?? $data['error'] ?? 'Unknown error';
			if(is_array($error)) $error = json_encode($error);
			throw new WireException("API error ($httpCode): $error");
		}

		return $data;
	}

	/**
	 * Module config inputfields for API credentials and provider settings
	 *
	 * @param InputfieldWrapper $inputfields
	 *
	 */
	public function getConfigInputfields(InputfieldWrapper $inputfields): void {
		$modules = $this->wire()->modules;

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Engineer');
		$fs->icon = 'commenting';

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'engineer_provider');
		$f->label = $this->_('AI Provider');
		$f->addOption(self::providerAnthropic, 'Anthropic (Claude)');
		$f->addOption(self::providerOpenAI, $this->_('OpenAI-compatible'));
		$f->val($this->at->get('engineer_provider') ?: self::providerAnthropic);
		$fs->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_api_key');
		$f->attr('type', 'password');
		$f->label = $this->_('API Key');
		$f->val($this->at->get('engineer_api_key') ?: '');
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_model');
		$f->label = $this->_('Model');
		$f->description = sprintf(
			$this->_('Leave blank for default: %s (Anthropic) or %s (OpenAI-compatible).'),
			self::defaultAnthropicModel,
			self::defaultOpenAIModel
		);
		$f->val($this->at->get('engineer_model') ?: '');
		$fs->add($f);

		/** @var InputfieldURL $f */
		$f = $modules->get('InputfieldURL');
		$f->attr('name', 'engineer_endpoint');
		$f->label = $this->_('API Endpoint URL');
		$f->description = $this->_('Base URL for OpenAI-compatible providers. Default: https://api.openai.com/v1');
		$f->showIf = 'engineer_provider=' . self::providerOpenAI;
		$f->val($this->at->get('engineer_endpoint') ?: '');
		$fs->add($f);

		$inputfields->add($fs);
	}
}
