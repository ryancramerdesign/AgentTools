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
 * @method array sendProviderRequest(AgentToolsRequest $request)
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
	 * debug mode: log request/response JSON to site/assets/logs/agent-tools-engineer.txt log
	 *
	 */
	const debugMode = false;
	
	/**
	 * Max conversation history pairs (user + assistant) to retain
	 *
	 * Each pair = one user message + one assistant reply. Oldest pairs are
	 * trimmed first when the limit is exceeded. Can be made configurable later.
	 *
	 * @var int
	 *
	 */
	protected $maxHistoryPairs = 10;

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
	 *  - `context` (string): 'all', 'custom', or 'none' (default: 'all')
	 *  - `contextItems` (array): Items to include when context='custom': sitemap_pages, sitemap_schema
	 *  - `history` (array): Prior conversation as [ ['role'=>'user','content'=>'...'], ['role'=>'assistant','content'=>'...'], ... ]
	 * @return array [ 'response' => string, 'migration' => string|null, 'error' => string|null, 'history' => array ]
	 *
	 */
	public function ask(string $request, array $options = []): array {

		$this->savedMigration = null;
		$result = ['response' => '', 'migration' => null, 'error' => null, 'history' => []];

		try {
			$primary = $this->at->getPrimaryAgent();
			$provider = $options['provider'] ?? ($primary ? $primary->provider : self::providerAnthropic);
			$apiKey = $options['apiKey'] ?? ($primary ? $primary->apiKey : '');
			$model = $options['model'] ?? '';
			$endpoint = $options['endpoint'] ?? '';

			if(!$apiKey) throw new WireException($this->_('API key is not configured in AgentTools module settings.'));

			// Build message history: prior pairs (text only) + current request
			$history = $options['history'] ?? [];
			$messages = [];
			foreach($history as $entry) {
				if(isset($entry['role']) && isset($entry['content'])) {
					$messages[] = ['role' => $entry['role'], 'content' => (string) $entry['content']];
				}
			}
			$messages[] = ['role' => 'user', 'content' => $request];

			$readOnly = isset($options['readOnly']) ? (bool) $options['readOnly'] : (bool) $this->at->get('engineer_readonly');
			$verbose = !empty($options['verbose']);
			$systemPrompt = $this->buildSystemPrompt($readOnly);
			$tools = $readOnly ? [] : $this->getToolDefinitions($provider);

			$providerRequest = new AgentToolsRequest();
			$this->wire($providerRequest); 
			$providerRequest->setArray([
				'provider' => $provider,
				'apiKey' => $apiKey,
				'model' => $model,
				'endpoint' => $endpoint,
				'systemPrompt' => $systemPrompt,
				'tools' => $tools,
			]);

			for($i = 0; $i < self::maxIterations; $i++) {
				$providerRequest->messages = $messages;
				$response = $this->sendProviderRequest($providerRequest);
				$toolCalls = $this->extractToolCalls($provider, $response);

				if(empty($toolCalls)) {
					$responseText = $this->extractText($provider, $response);
					$result['response'] = $responseText;
					$result['migration'] = $this->savedMigration;
					// Return updated history: trim to maxHistoryPairs, append this exchange
					$updatedHistory = array_merge($history, [
						['role' => 'user', 'content' => $request],
						['role' => 'assistant', 'content' => $responseText],
					]);
					$maxEntries = $this->maxHistoryPairs * 2;
					if(count($updatedHistory) > $maxEntries) {
						$updatedHistory = array_slice($updatedHistory, -$maxEntries);
					}
					$result['history'] = $updatedHistory;
					return $result;
				}

				$this->appendAssistantMessage($provider, $messages, $response);

				foreach($toolCalls as $toolCall) {
					if($verbose) fwrite(STDERR, "// tool: {$toolCall['name']}\n");
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
		foreach($this->at->getAgents() as $agent) {
			/** @var AgentToolsAgent $agent */
			$models[] = [
				'label' => $agent->label ?: $agent->model,
				'model' => $agent->model,
				'provider' => $agent->provider,
				'key' => $agent->apiKey,
				'endpoint' => $agent->endpointUrl,
			];
		}
		return $models;
	}

	/**
	 * CLI help entries for the Engineer
	 *
	 * @return array
	 *
	 */
	public function cliHelp(): array {
		return [
			'php index.php --at-engineer "REQUEST"' =>
				'Ask the Engineer a question or request a change [--model=N] [--readonly] [--verbose]',
			'php index.php --at-engineer-migrate "REQUEST"' =>
				'Have the Engineer create a migration; outputs the migration file path [--model=N] [--verbose]',
		];
	}

	/**
	 * Execute an Engineer CLI command
	 *
	 * Handles --at-engineer and --at-engineer-migrate.
	 *
	 * Flags (optional, placed before the request string):
	 *  --model=N   Use agent at index N (0 = primary)
	 *  --readonly  Allow queries only; migrations are disabled (--at-engineer only)
	 *  --verbose   Write tool call names to stderr as they execute
	 *
	 * @param string $action '' for ask, 'migrate' for migration-only
	 * @return bool|null True on success, false on failure, null if action not recognised
	 *
	 */
	public function cliExecute(string $action): ?bool {

		if($action !== '' && $action !== 'migrate') return null;

		$argv = $_SERVER['argv'];
		$args = array_slice($argv, 2);
		$migrate = ($action === 'migrate');
		$verbose = false;
		$readOnly = false;
		$modelIndex = null;
		$question = '';

		foreach($args as $arg) {
			if($arg === '--verbose') {
				$verbose = true;
			} else if($arg === '--readonly') {
				$readOnly = true;
			} else if(strpos($arg, '--model=') === 0) {
				$modelIndex = (int) substr($arg, 8);
			} else if(strpos($arg, '--') !== 0) {
				$question = $arg;
				break;
			}
		}

		if(!$question) return false;

		// Select agent by index or fall back to primary
		$agents = $this->at->getAgents();
		$agent = $modelIndex !== null ? $agents->eq($modelIndex) : $agents->first();

		if(!$agent || !$agent->apiKey) {
			fwrite(STDERR, "ERROR: No agent configured. Add API credentials in AgentTools module settings.\n");
			return false;
		}

		$options = [
			'provider' => $agent->provider,
			'apiKey' => $agent->apiKey,
			'model' => $agent->model,
			'endpoint' => $agent->endpointUrl,
			'readOnly' => $readOnly || (bool) $this->at->get('engineer_readonly'),
			'verbose' => $verbose,
		];

		$result = $this->ask($question, $options);

		if($result['error']) {
			fwrite(STDERR, "ERROR: " . $result['error'] . "\n");
			return false;
		}

		if($migrate) {
			if(!$result['migration']) {
				fwrite(STDERR, "ERROR: No migration was created for this request.\n");
				if($result['response']) fwrite(STDERR, $result['response'] . "\n");
				return false;
			}
			echo $result['response'] . "\n";
			echo "\nMigration: " . $result['migration'] . "\n";
		} else {
			echo $result['response'] . "\n";
			if($result['migration']) echo "\nMigration: " . $result['migration'] . "\n";
		}

		return true;
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
	 * Get a comma-separated list of API variables available to eval_php
	 *
	 * Includes core variables plus any conditionally available ones (e.g. $languages, $forms).
	 *
	 * @param bool $withNotes Include parenthetical notes for non-obvious variables (default true)
	 * @return string
	 *
	 */
	protected function getEvalPhpVars(bool $withNotes = true): string {
		$vars = [
			'$pages', '$fields', '$templates', '$modules', '$users', '$roles', '$permissions',
			'$config', '$sanitizer', '$datetime', '$files', '$database', '$urls',
			$withNotes ? '$at (AgentTools module instance)' : '$at',
		];
		if($this->wire('languages')) {
			$vars[] = $withNotes ? '$languages (multi-language support)' : '$languages';
		}
		if($this->wire('forms')) {
			$vars[] = $withNotes ? '$forms (FormBuilder)' : '$forms';
		}
		return implode(', ', $vars);
	}

	/**
	 * Build the system prompt
	 *
	 * @param bool $readOnly
	 * @return string
	 *
	 */
	protected function buildSystemPrompt(bool $readOnly = false): string {

		$siteUrl = rtrim($this->wire()->config->urls->httpRoot, '/');
		$apiVars = $this->getEvalPhpVars();

		$prompt =
			"You are an expert ProcessWire CMS engineer with complete knowledge of the ProcessWire API " .
			"and full access to this specific installation.\n\n" .

			"For informational requests, respond with clear concise text. Use the eval_php tool when " .
			"you need to query live site data.\n\n" .

			"For requests that make changes to the site (creating or modifying fields, templates, pages, " .
			"content, etc.), always use the save_migration tool rather than applying changes directly via " .
			"eval_php. This allows the user to review changes before they are applied. " .
			"Before writing a migration, use eval_php to verify current state (e.g. whether a field or " .
			"template already exists) so the migration is accurate. " .
			"Combine all changes for a single request into one migration file. Do not create multiple " .
			"migrations for a single request unless the user explicitly asks for them, or unless the " .
			"changes are technically unrelated and must be applied independently.\n\n" .

			"ProcessWire API variables available to eval_php: $apiVars.\n\n" .

			"Use the site_info tool to retrieve information about this site's pages or fields and templates. " .
			"Call with type='pages' for a map of the site's page tree, or type='schema' for the site's " .
			"fields and templates structure. Fetch only what the request requires.\n\n" .

			"Use the api_docs tool to discover and retrieve ProcessWire API documentation when needed. " .
			"Call with action='list' to see all available doc names, then action='get' with the doc name to read it. " .
			"Retrieve API docs before creating or modifying fields, templates, or other items where you need " .
			"to know available options or method signatures.\n\n" .

			"When referencing pages by path in your response, format them as markdown links using this " .
			"site's base URL: $siteUrl (e.g. a page at /blog/post/ becomes [$siteUrl/blog/post/]($siteUrl/blog/post/)).\n\n" .

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

		// Keep sitemaps current so site_info tool returns fresh data
		$siteMapFile = $this->at->getFilesPath() . 'site-map.json';
		if(is_file($siteMapFile) && $this->isSitemapStale($siteMapFile)) $this->regenerateSitemap();

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
	/**
	 * Get an index of all available API.md documentation files
	 *
	 * Scans core (/wire/modules/) and site (/site/modules/) at both one and two
	 * directory levels deep, returning a map of doc name => file path.
	 *
	 * @return array [ 'FieldtypeText' => '/path/to/API.md', ... ] sorted by name
	 *
	 */
	public function listApiDocs(): array {
		$docs = [];
		$config = $this->wire()->config;
		$searchPaths = [
			$config->paths->root . 'wire/core/',
			$config->paths->root . 'wire/modules/',
			$config->paths->siteModules,
		];
		foreach($searchPaths as $basePath) {
			if(!is_dir($basePath)) continue;
			foreach(glob($basePath . '*/API.md') ?: [] as $file) {
				$name = basename(dirname($file));
				$docs[$name] = $file;
			}
			foreach(glob($basePath . '*/*/API.md') ?: [] as $file) {
				$name = basename(dirname($file));
				$docs[$name] = $file;
			}
		}
		ksort($docs);
		return $docs;
	}

	/**
	 * Get the content of a specific API.md documentation file by name
	 *
	 * @param string $name Doc name as returned by listApiDocs() (e.g. 'FieldtypeText')
	 * @return string File contents, or an error message if not found
	 *
	 */
	public function getApiDocs(string $name): string {
		$docs = $this->listApiDocs();
		if(!isset($docs[$name])) {
			return "No API documentation found for '$name'. Use action='list' to see available docs.";
		}
		return (string) file_get_contents($docs[$name]);
	}

	/**
	 * Get tool definitions formatted for the given provider
	 *
	 * @param string $provider
	 * @return array
	 *
	 */
	protected function getToolDefinitions(string $provider): array {

		$apiVars = $this->getEvalPhpVars(false);
		$evalDesc =
			"Evaluate PHP code with full ProcessWire API access. Use echo to output results. " .
			"Available variables: $apiVars. Do not include an opening <?php tag.";

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

		$siteInfoDesc =
			"Retrieve information about this ProcessWire site. Use type='pages' for a map of the page tree, " .
			"or type='schema' for the site's fields and templates structure.";

		$siteInfoParams = [
			'type' => 'object',
			'properties' => [
				'type' => [
					'type' => 'string',
					'enum' => ['pages', 'schema'],
					'description' => "Use 'pages' for the site page tree, 'schema' for fields and templates",
				],
			],
			'required' => ['type'],
		];

		$apiDocsDesc =
			"Access ProcessWire API documentation. Use action='list' to get available doc names, " .
			"then action='get' with the doc name to retrieve its contents.";

		$apiDocsParams = [
			'type' => 'object',
			'properties' => [
				'action' => [
					'type' => 'string',
					'enum' => ['list', 'get'],
					'description' => "Use 'list' to see all available doc names, 'get' to retrieve a specific doc",
				],
				'name' => [
					'type' => 'string',
					'description' => "Name of the doc to retrieve, as returned by action='list' (required when action='get')",
				],
			],
			'required' => ['action'],
		];

		if($provider === self::providerAnthropic) {
			return [
				['name' => 'eval_php', 'description' => $evalDesc, 'input_schema' => $evalParams],
				['name' => 'save_migration', 'description' => $migrationDesc, 'input_schema' => $migrationParams],
				['name' => 'site_info', 'description' => $siteInfoDesc, 'input_schema' => $siteInfoParams],
				['name' => 'api_docs', 'description' => $apiDocsDesc, 'input_schema' => $apiDocsParams],
			];
		} else {
			return [
				['type' => 'function', 'function' => ['name' => 'eval_php', 'description' => $evalDesc, 'parameters' => $evalParams]],
				['type' => 'function', 'function' => ['name' => 'save_migration', 'description' => $migrationDesc, 'parameters' => $migrationParams]],
				['type' => 'function', 'function' => ['name' => 'site_info', 'description' => $siteInfoDesc, 'parameters' => $siteInfoParams]],
				['type' => 'function', 'function' => ['name' => 'api_docs', 'description' => $apiDocsDesc, 'parameters' => $apiDocsParams]],
			];
		}
	}

	/**
	 * Send a provider request from an AgentToolsRequest object
	 *
	 * This is the primary hookable dispatch point. Hook `AgentToolsEngineer::sendProviderRequest`
	 * with a `before` hook to inspect or mutate the request before it is sent, or an `after` hook
	 * to inspect or transform the raw response.
	 *
	 * ~~~~~~
	 * // Example: set reasoning_effort on every OpenAI request
	 * $wire->addHookBefore('AgentToolsEngineer::sendProviderRequest', function(HookEvent $e) {
	 *     $request = $e->arguments(0); // AgentToolsRequest
	 *     $opts = $request->options;
	 *     $opts['openai']['reasoning_effort'] = 'high';
	 *     $request->options = $opts;
	 * });
	 * ~~~~~~
	 *
	 * @param AgentToolsRequest $request
	 * @return array Raw provider response — check provider docs for structure
	 *
	 */
	public function ___sendProviderRequest(AgentToolsRequest $request): array {
		if($request->provider === self::providerAnthropic) {
			if(!$request->model) $request->model = self::defaultAnthropicModel;
			return $this->sendAnthropicRequest($request);
		} else {
			if(!$request->model) $request->model = self::defaultOpenAIModel;
			if(!$request->endpoint) {
				$request->endpoint = (string) $this->at->get('engineer_endpoint') ?: 'https://api.openai.com/v1/chat/completions';
			}
			// map legacy base URLs (stored before full-URL convention) to their full equivalents
			$legacyBaseUrls = [
				'https://api.openai.com/v1' => 'https://api.openai.com/v1/chat/completions',
				'https://api.groq.com/openai/v1' => 'https://api.groq.com/openai/v1/chat/completions',
				'https://openrouter.ai/api/v1' => 'https://openrouter.ai/api/v1/chat/completions',
				'https://generativelanguage.googleapis.com/v1beta/openai' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
				'https://generativelanguage.googleapis.com/v1beta/openai/' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
			];
			$endpoint = rtrim($request->endpoint, '/');
			$request->endpoint = $legacyBaseUrls[$endpoint] ?? $legacyBaseUrls[$request->endpoint] ?? $request->endpoint;
			return $this->sendOpenAIRequest($request);
		}
	}

	/**
	 * Send request to the configured AI provider (backwards-compatible positional-arg form)
	 *
	 * For new code, prefer constructing an AgentToolsRequest and calling sendProviderRequest().
	 * This method remains for backwards compatibility and delegates to sendProviderRequest().
	 *
	 * @param string $provider Provider name: 'anthropic' or 'openai'
	 * @param string $apiKey API key for the provider
	 * @param string $model Model ID to use
	 * @param string $endpoint Base endpoint URL (OpenAI-compatible providers only)
	 * @param string $systemPrompt System prompt, or empty string for none
	 * @param array $messages Array of message objects: [['role' => 'user'|'assistant', 'content' => '...'], ...]
	 * @param array $tools Tool definitions in provider format
	 * @param array $options Optional request options — see AgentToolsRequest $options for keys
	 * @return array Raw provider response — check provider docs for structure
	 * @deprecated Use AgentToolsRequest + sendProviderRequest() instead
	 *
	 */
	public function sendRequest(string $provider, string $apiKey, string $model, string $endpoint, string $systemPrompt, array $messages, array $tools = [], array $options = []): array {
		$request = new AgentToolsRequest();
		$request->setArray([
			'provider' => $provider,
			'apiKey' => $apiKey,
			'model' => $model,
			'endpoint' => $endpoint,
			'systemPrompt' => $systemPrompt,
			'messages' => $messages,
			'tools' => $tools,
			'options' => $options,
		]);
		return $this->sendProviderRequest($request);
	}

	/**
	 * Send request to Anthropic Messages API
	 *
	 * @param AgentToolsRequest $request
	 * @return array
	 *
	 */
	protected function sendAnthropicRequest(AgentToolsRequest $request): array {
		$cache = ['type' => 'ephemeral', 'ttl' => '1h'];
		$options = $request->options;
		$tools = $request->tools;

		// System prompt as a content block array so we can attach cache_control
		// Omit entirely if empty — Anthropic rejects empty text blocks
		$systemBlocks = $request->systemPrompt !== ''
			? [['type' => 'text', 'text' => $request->systemPrompt, 'cache_control' => $cache]]
			: [];

		// Cache tool definitions too — they are static per session
		if(!empty($tools)) {
			$tools[count($tools) - 1]['cache_control'] = $cache;
		}

		$payload = [
			'model' => $request->model,
			'max_tokens' => self::maxTokens,
			'messages' => $request->messages,
		];
		if(!empty($systemBlocks)) $payload['system'] = $systemBlocks;
		if(!empty($tools)) $payload['tools'] = $tools;

		// Merge caller-supplied Anthropic options, protecting core structural keys
		if(!empty($options['anthropic'])) {
			$reserved = array_flip(['model', 'messages', 'system', 'tools']);
			$payload = array_merge($payload, array_diff_key($options['anthropic'], $reserved));
		}

		$timeout = isset($options['timeout']) ? (int) $options['timeout'] : 120;

		return $this->curlPost(
			$request->endpoint ?: 'https://api.anthropic.com/v1/messages',
			$payload,
			[
				'x-api-key: ' . $request->apiKey,
				'anthropic-version: 2023-06-01',
				'content-type: application/json',
			],
			$timeout
		);
	}

	/**
	 * Send request to OpenAI-compatible Chat Completions API
	 *
	 * @param AgentToolsRequest $request
	 * @return array
	 *
	 */
	protected function sendOpenAIRequest(AgentToolsRequest $request): array {
		$options = $request->options;
		$endpoint = (string) $request->endpoint;
		$path = (string) parse_url($endpoint, PHP_URL_PATH);
		$isResponses = str_ends_with($path, '/responses');

		if($isResponses) {
			$payload = [
				'model' => $request->model,
				'input' => $this->buildOpenAIResponsesInput($request->messages),
			];
			if($request->systemPrompt !== '') $payload['instructions'] = $request->systemPrompt;
			if(!empty($request->tools)) $payload['tools'] = $request->tools;

			// Merge caller-supplied OpenAI options, protecting core structural keys
			if(!empty($options['openai'])) {
				$reserved = array_flip(['model', 'input', 'instructions', 'tools']);
				$payload = array_merge($payload, array_diff_key($options['openai'], $reserved));
			}
		} else {
			$payload = [
				'model' => $request->model,
				'messages' => array_merge([['role' => 'system', 'content' => $request->systemPrompt]], $request->messages),
			];
			if(!empty($request->tools)) $payload['tools'] = $request->tools;

			// Merge caller-supplied OpenAI options, protecting core structural keys
			if(!empty($options['openai'])) {
				$reserved = array_flip(['model', 'messages', 'tools']);
				$payload = array_merge($payload, array_diff_key($options['openai'], $reserved));
			}
		}

		$timeout = isset($options['timeout']) ? (int) $options['timeout'] : 120;

		return $this->curlPost(
			$endpoint,
			$payload,
			[
				'Authorization: Bearer ' . $request->apiKey,
				'content-type: application/json',
			],
			$timeout
		);
	}

	/**
	 * Convert Chat Completions-style messages into Responses API input items
	 *
	 * @param array $messages
	 * @return array
	 *
	 */
	protected function buildOpenAIResponsesInput(array $messages): array {
		$input = [];
		foreach($messages as $message) {
			if(!is_array($message)) continue;
			$role = (string) ($message['role'] ?? 'user');
			$content = $message['content'] ?? '';
			if(!is_string($content)) {
				$content = is_scalar($content) ? (string) $content : json_encode($content);
			}
			$type = $role === 'assistant' ? 'output_text' : 'input_text';
			$input[] = [
				'role' => in_array($role, ['user', 'assistant', 'system'], true) ? $role : 'user',
				'content' => [[
					'type' => $type,
					'text' => (string) $content,
				]],
			];
		}
		return $input ?: [[
			'role' => 'user',
			'content' => [[
				'type' => 'input_text',
				'text' => '',
			]],
		]];
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
		} else if($name === 'site_info') {
			$type = (string) ($input['type'] ?? '');
			$filesPath = $this->at->getFilesPath();
			if($type === 'pages') {
				$file = $filesPath . 'site-map.json';
				return is_file($file) ? (string) file_get_contents($file) : 'Site map not found. Run --at-sitemap-generate to generate it.';
			} else if($type === 'schema') {
				$file = $filesPath . 'site-map-schema.json';
				return is_file($file) ? (string) file_get_contents($file) : 'Schema not found. Run --at-sitemap-generate-schema to generate it.';
			}
			return "Invalid type '$type'. Use 'pages' or 'schema'.";
		} else if($name === 'api_docs') {
			$action = (string) ($input['action'] ?? 'list');
			if($action === 'get') {
				return $this->getApiDocs((string) ($input['name'] ?? ''));
			}
			$names = array_keys($this->listApiDocs());
			return $names ? implode("\n", $names) : 'No API documentation files found.';
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
	public function extractText(string $provider, array $response): string {
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
	 * @param int $timeout Request timeout in seconds (default 120)
	 * @return array Decoded JSON response
	 * @throws WireException on network error, non-JSON response, or HTTP error status
	 *
	 */
	protected function curlPost(string $url, array $payload, array $headers, int $timeout = 120): array {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($payload),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $timeout,
		]);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		if($response === false) throw new WireException("API request failed: $curlError");

		$data = json_decode($response, true);
		if(!is_array($data)) throw new WireException("Invalid API response: expected JSON");
		
		if(self::debugMode) $this->message([
			'url' => $url,
			'headers' => $headers, 
			'request' => $payload,
			'response' => $data
		]);
		
		if($httpCode >= 400) {
			$error = $data['error']['message'] ?? $data['error'] ?? $data['message'] ?? null;
			if($error === null) $error = trim($response) ?: 'Unknown error';
			if(is_array($error)) $error = json_encode($error);
			if(self::debugMode) $this->error("$httpCode: $error"); 
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
		$datalists = include(__DIR__ . '/datalists.php');
		
		/** @var InputfieldFieldset $outerFs */
		$outerFs = $modules->get('InputfieldFieldset');
		$outerFs->label = $this->_('Engineer');
		$outerFs->icon = 'commenting';
		$outerFs->description = 
			$this->_('Configure the primary AI agent here.') . ' ' . 
			$this->_('You can also edit and add more AI agents at [Setup > AgentTools > Agents](../setup/agent-tools/agents/).'); 

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'engineer_provider');
		$f->label = $this->_('AI Provider');
		$f->addOption(self::providerAnthropic, 'Anthropic (Claude)');
		$f->addOption(self::providerOpenAI, $this->_('OpenAI-compatible'));
		$f->val($this->at->get('engineer_provider') ?: self::providerAnthropic);
		$f->columnWidth = 50;
		$outerFs->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_api_key');
		$f->attr('type', 'password');
		$f->label = $this->_('API Key');
		$f->val($this->at->get('engineer_api_key') ?: '');
		$f->columnWidth = 50;
		$outerFs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_model');
		$f->attr('list', 'model-list');
		$f->label = $this->_('Model API identifier');
		$f->val($this->at->get('engineer_model') ?: '');
		$f->description = 'Example: `claude-sonnet-4-6` ';
		$o = '';
		foreach($datalists['model'] as $modelLabel => $modelName) {
			$o .= "<option value='$modelName' label='$modelLabel'>";
		}
		$f->appendMarkup = "<datalist id='model-list'>$o</datalist>";
		$f->columnWidth = 50;
		$outerFs->add($f);
		
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_label');
		$f->attr('list', 'label-list');
		$f->label = $this->_('Model API label (optional)');
		$f->val($this->at->get('engineer_label') ?: '');
		$f->description = 'Example: `Claude Sonnet 4.6` ';
		$o = '';
		foreach($datalists['model'] as $modelLabel => $modelName) {
			$o .= "<option value='$modelLabel' label='$modelName'>";
		}
		$f->appendMarkup = "<datalist id='label-list'>$o</datalist>";
		$f->columnWidth = 50;
		$outerFs->add($f);

		/** @var InputfieldURL $f */
		$f = $modules->get('InputfieldURL');
		$f->attr('name', 'engineer_endpoint');
		$f->label = $this->_('API endpoint URL');
		$f->val($this->at->get('engineer_endpoint') ?: '');
		$f->attr('list', 'endpoint-list');
		$o = '';
		foreach($datalists['endpointUrl'] as $endpointLabel => $endpointUrl) {
			$o .= "<option value='$endpointUrl' label='$endpointLabel'>";
		}
		$f->appendMarkup = "<datalist id='endpoint-list'>$o</datalist>";
		$outerFs->add($f);

		/** @var InputfieldToggle $f */
		$f = $modules->get('InputfieldToggle');
		$f->attr('name', 'engineer_readonly');
		$f->label = $this->_('Read-only mode');
		$f->description = $this->_('When enabled, the Engineer can answer questions and suggest changes but cannot execute code or create migration files.');
		$f->val((int) $this->at->get('engineer_readonly'));
		$outerFs->add($f);

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'engineer_additional_models');
		$f->label = $this->_('Agents (export/import)');
		$f->collapsed = Inputfield::collapsedYes;
		$f->description =
			$this->_('This field contains your agents configuration in pipe-separated format (one agent per line).') . ' ' .
			$this->_('You can copy this value to transfer your agent configuration to another installation, or paste a configuration from another installation here.');
		$f->attr('rows', 6);
		$f->val($this->at->getAgents()->getString()); 
		$outerFs->add($f);
		$inputfields->add($outerFs);
	}
}
