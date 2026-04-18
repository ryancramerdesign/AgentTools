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
	 * Context items included in the last buildSystemPrompt() call
	 *
	 * @var array
	 *
	 */
	public $lastContext = [];

	/**
	 * Ask the engineer a question or request a site change
	 *
	 * @param string $request
	 * @param array $options Optional overrides:
	 *  - `provider` (string): AI provider constant (default: module config)
	 *  - `apiKey` (string): API key (default: module config)
	 *  - `model` (string): Model identifier (default: module config / provider default)
	 *  - `endpoint` (string): API endpoint base URL for OpenAI-compatible providers
	 *  - `context` (string): 'all' or 'custom' (default: 'all')
	 *  - `contextItems` (array): Items to include when context='custom': sitemap_pages, sitemap_schema, api_core, api_site
	 * @return array [ 'response' => string, 'migration' => string|null, 'error' => string|null ]
	 *
	 */
	public function ask(string $request, array $options = []): array {

		$this->savedMigration = null;
		$result = ['response' => '', 'migration' => null, 'error' => null];

		try {
			$provider = $options['provider'] ?? ((string) $this->at->get('engineer_provider') ?: self::providerAnthropic);
			$apiKey = $options['apiKey'] ?? (string) $this->at->get('engineer_api_key');
			$model = $options['model'] ?? '';
			$endpoint = $options['endpoint'] ?? '';

			if(!$apiKey) throw new WireException($this->_('API key is not configured in AgentTools module settings.'));

			$readOnly = (bool) $this->at->get('engineer_readonly');
			$systemPrompt = $this->buildSystemPrompt($readOnly, $request, $options);
			$tools = $readOnly ? [] : $this->getToolDefinitions($provider);
			$messages = [['role' => 'user', 'content' => $request]];

			for($i = 0; $i < self::maxIterations; $i++) {
				$response = $this->sendRequest($provider, $apiKey, $model, $endpoint, $systemPrompt, $messages, $tools);
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
	 * Get all available models as a list of entries for the Control room model selector
	 *
	 * Combines the primary provider (CSV model field) with any additional models
	 * configured in engineer_additional_models. Each entry has: label, model,
	 * provider, key, endpoint.
	 *
	 * @return array
	 *
	 */
	public function getAvailableModels(): array {
		$models = [];

		$primaryKey = (string) $this->at->get('engineer_api_key');
		$primaryProvider = (string) $this->at->get('engineer_provider') ?: self::providerAnthropic;
		$primaryEndpoint = (string) $this->at->get('engineer_endpoint') ?: '';
		$primaryModelStr = (string) $this->at->get('engineer_model') ?:
			($primaryProvider === self::providerAnthropic ? self::defaultAnthropicModel : self::defaultOpenAIModel);

		foreach(explode(',', $primaryModelStr) as $modelId) {
			$modelId = trim($modelId);
			if(!$modelId) continue;
			$models[] = [
				'label' => $modelId,
				'model' => $modelId,
				'provider' => $primaryProvider,
				'key' => $primaryKey,
				'endpoint' => $primaryEndpoint,
			];
		}

		$additionalStr = trim((string) $this->at->get('engineer_additional_models'));
		if($additionalStr) {
			foreach(explode("\n", $additionalStr) as $line) {
				$entry = $this->parseAdditionalModelLine(trim($line));
				if($entry) $models[] = $entry;
			}
		}

		return $models;
	}

	/**
	 * Parse a single additional model line into a model entry array
	 *
	 * Pipe-separated format (whitespace around pipes is ignored):
	 *   model | key                        — provider auto-detected from key prefix
	 *   model | key | endpoint             — with custom endpoint URL
	 *   model | key | endpoint | label     — with custom endpoint and display label
	 *
	 * Provider is auto-detected as 'anthropic' if key starts with 'sk-ant-', otherwise 'openai'.
	 * Label defaults to "model (provider)" if not specified.
	 *
	 * @param string $line
	 * @return array|null
	 *
	 */
	protected function parseAdditionalModelLine(string $line): ?array {
		if(!$line || strpos($line, '#') === 0) return null;
		$parts = explode('|', $line, 4);
		$parts = array_map('trim', $parts);
		$count = count($parts);
		$endpoint = '';
		$label = '';

		if($count === 2) {
			[$model, $key] = $parts;
		} else if($count === 3) {
			[$model, $key, $endpoint] = $parts;
		} else {
			[$model, $key, $endpoint, $label] = $parts;
		}

		$provider = strpos($key, 'sk-ant-') === 0 ? self::providerAnthropic : self::providerOpenAI;

		$model = trim($model);
		$key = trim($key);
		$provider = trim($provider);
		$endpoint = trim($endpoint);
		$label = trim($label);

		if(!$model || !$key) return null;
		if(!$label) $label = $model . ' (' . $provider . ')';

		return [
			'label' => $label,
			'model' => $model,
			'provider' => $provider,
			'key' => $key,
			'endpoint' => $endpoint,
		];
	}

	/**
	 * Build the system prompt, including site map and schema context if available
	 *
	 * @param bool $readOnly
	 * @param string $request The user's request (used for keyword-based doc inclusion)
	 * @param array $options Context options from ask():
	 *  - `provider` (string): Active provider (affects API doc inclusion logic)
	 *  - `context` (string): 'all' or 'custom'
	 *  - `contextItems` (array): Items to include when context='custom'
	 * @return string
	 *
	 */
	protected function buildSystemPrompt(bool $readOnly = false, string $request = '', array $options = []): string {

		$siteUrl = $this->wire()->config->httpRoot;
		$provider = $options['provider'] ?? ((string) $this->at->get('engineer_provider') ?: self::providerAnthropic);
		$contextMode = $options['context'] ?? 'all';
		$contextItems = $options['contextItems'] ?? [];
		$isAll = $contextMode === 'all';

		$prompt =
			"You are an expert ProcessWire CMS engineer with complete knowledge of the ProcessWire API " .
			"and full access to this specific installation.\n\n" .

			"For informational requests, respond with clear concise text. Use the eval_php tool when " .
			"you need to query live site data not available in the site map or schema provided below.\n\n" .

			"For requests that make changes to the site (creating or modifying fields, templates, pages, " .
			"content, etc.), always use the save_migration tool rather than applying changes directly via " .
			"eval_php. This allows the user to review changes before they are applied. " .
			"Before writing a migration, use eval_php to verify current state (e.g. whether a field or " .
			"template already exists) so the migration is accurate. " .
			"Combine all changes for a single request into one migration file. Do not create multiple " .
			"migrations for a single request unless the user explicitly asks for them, or unless the " .
			"changes are technically unrelated and must be applied independently.\n\n" .

			"ProcessWire API variables available to eval_php: \$pages, \$fields, \$templates, \$modules, " .
			"\$users, \$roles, \$permissions, \$config, \$at (AgentTools module instance).\n\n" .

			"When referencing pages by path in your response, format them as markdown links using this " .
			"site's base URL: $siteUrl (e.g. a page at /blog/post/ becomes [$siteUrl" . "blog/post/]($siteUrl" . "blog/post/)).\n\n" .

			"When displaying dates or timestamps retrieved via eval_php, always format them as human-readable " .
			"strings (e.g. date('Y-m-d H:i:s', \$page->modified)) rather than returning raw Unix timestamps.\n\n" .

			"If a request is ambiguous, incomplete, or lacks sufficient context to act on confidently " .
			"(for example, it references previous context you don't have), ask the user for clarification " .
			"rather than guessing. Do not attempt to execute or create a migration for an ambiguous request.";

		if($readOnly) $prompt .=
			"\n\nYou are operating in read-only mode. You can answer questions, explain how things work, " .
			"and suggest approaches, but you cannot execute code or create migration files. " .
			"If asked to make a change, explain what would need to be done and provide example code, " .
			"but note that changes must be applied manually or via the CLI.";

		$this->lastContext = [];

		// Staleness check always runs regardless of context mode (sitemaps have CLI value too)
		$siteMapFile = $this->at->getFilesPath() . 'site-map.json';
		if(is_file($siteMapFile)) {
			if($this->isSitemapStale($siteMapFile)) $this->regenerateSitemap();
			if($isAll || in_array('sitemap_pages', $contextItems)) {
				$prompt .= "\n\n[SITE MAP]\n" . file_get_contents($siteMapFile);
				$this->lastContext[] = 'sitemap_pages';
			}
		}

		$schemaFile = $this->at->getFilesPath() . 'site-map-schema.json';
		if(is_file($schemaFile) && ($isAll || in_array('sitemap_schema', $contextItems))) {
			$prompt .= "\n\n[SCHEMA]\n" . file_get_contents($schemaFile);
			$this->lastContext[] = 'sitemap_schema';
		}

		if($isAll) {
			// Original behavior: always include for Anthropic (cached), keyword-detect for others
			$includeApiDocs = $provider === self::providerAnthropic || $this->requestNeedsFieldtypeDocs($request);
			if($includeApiDocs) {
				$apiDocs = $this->getFieldtypeApiDocs();
				if($apiDocs) {
					$prompt .= "\n\n[FIELDTYPE API REFERENCE]\n" . $apiDocs;
					$this->lastContext[] = 'api_core';
				}
			}
		} else {
			// Custom mode: include only what the user explicitly selected
			$includeCore = in_array('api_core', $contextItems);
			$includeSite = in_array('api_site', $contextItems);
			if($includeCore || $includeSite) {
				$apiDocs = $this->getFieldtypeApiDocs($includeCore, $includeSite);
				if($apiDocs) {
					$prompt .= "\n\n[FIELDTYPE API REFERENCE]\n" . $apiDocs;
					if($includeCore) $this->lastContext[] = 'api_core';
					if($includeSite) $this->lastContext[] = 'api_site';
				}
			}
		}

		return $prompt;
	}

	/**
	 * Is the site-map file older than the most recently modified field, template, or page?
	 *
	 * Compares site-map filemtime against:
	 *   - filemtime of fields.txt / templates.txt (written by the hook in AgentTools::ready())
	 *   - UNIX_TIMESTAMP(MAX(modified)) from the pages table (datetime)
	 *
	 * @param string $siteMapFile Full path to site-map.json
	 * @return bool True if the site-map is stale and should be regenerated
	 *
	 */
	protected function isSitemapStale(string $siteMapFile): bool {
		$sitemapMtime = filemtime($siteMapFile);

		// Fields and templates: tracked via fields.txt/templates.txt written by the hook in AgentTools::ready()
		foreach(['fields.txt', 'templates.txt'] as $trackingFile) {
			$path = $this->at->getFilesPath() . $trackingFile;
			if(is_file($path) && filemtime($path) > $sitemapMtime) return true;
		}

		// pages.modified is a datetime column
		$stmt = $this->wire()->database->query("SELECT UNIX_TIMESTAMP(MAX(modified)) FROM pages");
		$maxModified = (int) $stmt->fetchColumn();
		if($maxModified > $sitemapMtime) return true;

		return false;
	}

	/**
	 * Regenerate the site-map and schema files
	 *
	 * Called automatically when the site-map is detected as stale.
	 * Failures are silently swallowed so they don't interrupt an Engineer request.
	 *
	 */
	protected function regenerateSitemap(): void {
		try {
			$this->at->sitemap->generate();
			$this->at->sitemap->generateSchema();
		} catch(\Throwable $e) {
			// Silent: stale site-map is better than a broken Engineer request
		}
	}

	/**
	 * Does the request likely involve field creation or configuration?
	 *
	 * Used to decide whether to include the Fieldtype API reference in the system
	 * prompt. Errs on the side of inclusion — false positives waste a few tokens,
	 * false negatives leave the AI without reference docs it may need.
	 *
	 * @param string $request
	 * @return bool
	 *
	 */
	protected function requestNeedsFieldtypeDocs(string $request): bool {
		$keywords = [
			'field', 'fieldtype', 'inputfield',
			'textarea', 'checkbox', 'toggle', 'select',
			'image', 'file', 'upload',
			'repeater', 'pagetable', 'page reference',
			'datetime', 'date', 'email', 'url',
			'integer', 'float', 'decimal',
			'options field', 'multi',
		];
		$request = strtolower($request);
		foreach($keywords as $kw) {
			if(strpos($request, $kw) !== false) return true;
		}
		return false;
	}

	/**
	 * Get Fieldtype API documentation from API.md files in the ProcessWire installation
	 *
	 * Reads API.md files from Fieldtype module subdirectories. Can include core
	 * (/wire/modules/Fieldtype/) and/or site (/site/modules/) API.md files.
	 *
	 * @param bool $includeCore Include core Fieldtype API.md files (default=true)
	 * @param bool $includeSite Include site module API.md files (default=true)
	 * @return string
	 *
	 */
	protected function getFieldtypeApiDocs(bool $includeCore = true, bool $includeSite = true): string {
		$docs = '';

		if($includeCore) {
			$fieldtypePath = $this->wire()->config->paths->root . 'wire/modules/Fieldtype/';
			if(is_dir($fieldtypePath)) {
				foreach(glob($fieldtypePath . '*/API.md') as $file) {
					$docs .= file_get_contents($file) . "\n\n";
				}
				$flatApiFile = $fieldtypePath . 'API.md';
				if(is_file($flatApiFile)) {
					$docs .= file_get_contents($flatApiFile) . "\n\n";
				}
			}
		}

		if($includeSite) {
			$sitePath = $this->wire()->config->paths->siteModules;
			if(is_dir($sitePath)) {
				// Site Fieldtype subdirectory (mirrors core structure)
				$fieldtypePath = $sitePath . 'Fieldtype/';
				if(is_dir($fieldtypePath)) {
					foreach(glob($fieldtypePath . '*/API.md') as $file) {
						$docs .= file_get_contents($file) . "\n\n";
					}
					$flatApiFile = $fieldtypePath . 'API.md';
					if(is_file($flatApiFile)) {
						$docs .= file_get_contents($flatApiFile) . "\n\n";
					}
				}
				// Top-level site module API.md files (custom fieldtypes in own directory)
				foreach(glob($sitePath . '*/API.md') as $file) {
					$docs .= file_get_contents($file) . "\n\n";
				}
			}
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
				'summary' => ['type' => 'string', 'description' => 'Human-readable markdown summary of what the migration does, to be embedded as a comment in the file. Include any relevant notes for the developer.'],
			],
			'required' => ['code', 'description', 'summary'],
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
	 * Send a request to the AI provider
	 *
	 * @param string $provider Provider constant
	 * @param string $apiKey API key (may be overridden per-model)
	 * @param string $model Model identifier (empty string = use provider default)
	 * @param string $endpoint Base URL for OpenAI-compatible providers (empty = module config or OpenAI default)
	 * @param string $systemPrompt
	 * @param array $messages
	 * @param array $tools
	 * @return array
	 *
	 */
	protected function sendRequest(string $provider, string $apiKey, string $model, string $endpoint, string $systemPrompt, array $messages, array $tools): array {
		if($provider === self::providerAnthropic) {
			if(!$model) $model = self::defaultAnthropicModel;
			return $this->sendAnthropicRequest($apiKey, $model, $systemPrompt, $messages, $tools);
		} else {
			if(!$model) $model = self::defaultOpenAIModel;
			if(!$endpoint) $endpoint = (string) $this->at->get('engineer_endpoint') ?: 'https://api.openai.com/v1';
			$endpoint = rtrim($endpoint, '/');
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
		$cache = ['type' => 'ephemeral', 'ttl' => '1h'];

		// System prompt as a content block array so we can attach cache_control
		$systemBlocks = [
			['type' => 'text', 'text' => $system, 'cache_control' => $cache],
		];

		// Cache tool definitions too — they are static per session
		if(!empty($tools)) {
			$tools[count($tools) - 1]['cache_control'] = $cache;
		}

		return $this->curlPost(
			'https://api.anthropic.com/v1/messages',
			[
				'model' => $model,
				'max_tokens' => self::maxTokens,
				'system' => $systemBlocks,
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
				(string) ($input['description'] ?? 'migration'),
				(string) ($input['summary'] ?? '')
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
	 * @param string $summary Human-readable markdown summary to embed as a docblock comment
	 * @return string Confirmation message or error
	 *
	 */
	protected function executeSaveMigration(string $code, string $description, string $summary = ''): string {
		$description = preg_replace('/[^a-z0-9]+/', '_', strtolower($description));
		$description = trim($description, '_');
		if(!$description) $description = 'migration';
		$filename = date('YmdHis') . '_' . $description . '.php';
		$path = $this->at->getFilesPath('migrations') . $filename;
		if($summary) {
			// Embed summary as a docblock after the opening <?php tag
			$docblock = "/**\n";
			foreach(explode("\n", trim($summary)) as $line) {
				$docblock .= ' * ' . rtrim($line) . "\n";
			}
			$docblock .= " */\n";
			$code = preg_replace('/^(<\?php[^\n]*\n)/', '$1' . $docblock, $code, 1);
		}
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
		if($response === false) throw new WireException("API request failed: $curlError");

		$data = json_decode($response, true);
		if(!is_array($data)) throw new WireException("Invalid API response: expected JSON");

		if($httpCode >= 400) {
			$error = $data['error']['message'] ?? $data['error'] ?? $data['message'] ?? null;
			if($error === null) $error = trim($response) ?: 'Unknown error';
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

		/** @var InputfieldFieldset $outerFs */
		$outerFs = $modules->get('InputfieldFieldset');
		$outerFs->label = $this->_('Engineer');
		$outerFs->icon = 'commenting';

		// Primary AI provider fieldset
		/** @var InputfieldFieldset $primaryFs */
		$primaryFs = $modules->get('InputfieldFieldset');
		$primaryFs->label = $this->_('Primary AI provider');
		$outerFs->add($primaryFs);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'engineer_provider');
		$f->label = $this->_('AI Provider');
		$f->addOption(self::providerAnthropic, 'Anthropic (Claude)');
		$f->addOption(self::providerOpenAI, $this->_('OpenAI-compatible'));
		$f->val($this->at->get('engineer_provider') ?: self::providerAnthropic);
		$primaryFs->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_api_key');
		$f->label = $this->_('API Key');
		$f->val($this->at->get('engineer_api_key') ?: '');
		$primaryFs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_model');
		$f->attr('list', 'at_engineer_model_list');
		$f->label = $this->_('Model');
		$f->description = 
			$this->_('Model API identifier.') . ' ' . 
			sprintf(
				$this->_('Leave blank for default: %s (Anthropic) or %s (OpenAI-compatible).'),
				'`' . self::defaultAnthropicModel . '`',
				'`' . self::defaultOpenAIModel . '`',
			) . ' ' . 
			sprintf(
				$this->_("Enter multiple comma-separated identifiers to offer a choice in the Engineer's Control room (e.g. %s,%s)."),
				'`' . self::defaultAnthropicModel . '`',
				'`claude-opus-4-7`'
			) . ' ' . 
			$this->_('Common models are suggested as you type. First entered model is the default.');
		$f->val($this->at->get('engineer_model') ?: '');
		$claudeModels = [
			// Anthropic
			'claude-opus-4-7',
			'claude-opus-4-6',
			'claude-sonnet-4-6',
			'claude-haiku-4-5',
		];
		$knownModels = $claudeModels + [
			// OpenAI
			'gpt-4o',
			'gpt-4o-mini',
			'gpt-4-turbo',
			'o1',
			'o3-mini',
		];
		$f->detail =
			'Example for single model: `claude-sonnet-4-6` ' . "\n" .
			'Example for multi models: `' . implode(',', $claudeModels) . "`\n" .
			'Models entered must be from the same provider and use the same API key (above). ' .
			'For other providers, see "Additional models" below.';
		$options = implode('', array_map(function($m) { return "<option value='$m'>"; }, $knownModels));
		$f->appendMarkup = "<datalist id='at_engineer_model_list'>$options</datalist>";
		$primaryFs->add($f);

		/** @var InputfieldURL $f */
		$f = $modules->get('InputfieldURL');
		$f->attr('name', 'engineer_endpoint');
		$f->label = $this->_('API Endpoint URL');
		$f->description = $this->_('Base URL for OpenAI-compatible providers. Default: https://api.openai.com/v1');
		$f->showIf = 'engineer_provider=' . self::providerOpenAI;
		$f->val($this->at->get('engineer_endpoint') ?: '');
		$primaryFs->add($f);

		/** @var InputfieldToggle $f */
		$f = $modules->get('InputfieldToggle');
		$f->attr('name', 'engineer_readonly');
		$f->label = $this->_('Read-only mode');
		$f->description = $this->_('When enabled, the Engineer can answer questions and suggest changes but cannot execute code or create migration files.');
		$f->val((int) $this->at->get('engineer_readonly'));
		$primaryFs->add($f);

		// Additional models fieldset
		/** @var InputfieldFieldset $additionalFs */
		/*
		$additionalFs = $modules->get('InputfieldFieldset');
		$additionalFs->label = $this->_('Additional models');
		$additionalFs->collapsed = Inputfield::collapsedYes;
		*/

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'engineer_additional_models');
		$f->label = $this->_('Additional models');
		$f->collapsed = Inputfield::collapsedBlank;
		$f->description =
			'Add one model per line to make it available in the Engineer\'s Control room. Each model uses its own API key, ' .
			'independent of the primary provider above. Use the pipe-separated format: ' . "\n\n" . 
			'`model | api-key` ' . "\n" . 
			'`model | api-key | endpoint-url` ' . "\n" . 
			'`model | api-key | endpoint-url | label`' . "\n\n" . 
			'Provider is auto-detected from the key prefix (`sk-ant-*` = Anthropic, all others = OpenAI-compatible). ' .
			'Whitespace around pipes is optional. Lines beginning with `#` are ignored.';
		$f->appendMarkup .=
			"<p class='uk-margin-small-top uk-margin-remove-bottom'>Examples:</p>" . 
			"<pre class='uk-margin-remove'>" . 
			"# OpenAI\ngpt-4o | YOUR_OPENAI_API_KEY\n\n" .
			"# Anthropic (key prefix auto-detects provider)\nclaude-haiku-4-5-20251001 | sk-ant-YOUR_API_KEY\n\n" .
			"# Google Gemini\ngemini-2.0-flash | YOUR_API_KEY | https://generativelanguage.googleapis.com/v1beta/openai/\n\n" .
			"# Groq / Llama (label distinguishes it from other openai-compatible models)\nllama-3.3-70b-versatile | YOUR_API_KEY | https://api.groq.com/openai/v1 | Groq Llama 3.3\n\n" .
			"# Local Ollama (no real key required)\nllama3 | ollama | http://localhost:11434/v1" . 
			"</pre>";
		$f->attr('rows', 6);
		$f->val($this->at->get('engineer_additional_models') ?: '');
		$outerFs->add($f);
		$inputfields->add($outerFs);
	}
}
