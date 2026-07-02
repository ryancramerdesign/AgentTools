<?php namespace ProcessWire;

/**
 * Local stdio MCP server for AgentTools.
 *
 */
class AgentToolsMcp extends AgentToolsHelper {

	const protocolVersion = '2025-06-18';

	/**
	 * Get array of CLI help [ 'syntax' => 'description' ]
	 *
	 * @return array
	 *
	 */
	public function cliHelp() {
		return [
			"php index.php --at-mcp" => "Run the local AgentTools MCP server over stdio",
		];
	}

	/**
	 * Execute CLI action.
	 *
	 * @param string $action
	 * @return bool|null
	 *
	 */
	public function cliExecute(string $action): ?bool {
		if($action !== '') return null;
		$this->serveStdio();
		return true;
	}

	/**
	 * Serve MCP JSON-RPC messages over newline-delimited stdio.
	 *
	 */
	public function serveStdio(): void {
		set_time_limit(0);
		$heartbeatSeconds = (int) getenv('AGENTTOOLS_MCP_HEARTBEAT');
		if($heartbeatSeconds < 1) {
			while(($line = fgets(STDIN)) !== false) {
				$line = trim($line);
				if($line === '') continue;
				$response = $this->handleJson($line);
				if($response === '') continue;
				echo $response . "\n";
				flush();
			}
			return;
		}

		while(true) {
			$read = [STDIN];
			$write = null;
			$except = null;
			$result = stream_select($read, $write, $except, $heartbeatSeconds);
			if($result === false) break;
			if($result === 0) {
				$response = $this->encode([
					'jsonrpc' => '2.0',
					'id' => 'heartbeat-' . time(),
					'method' => 'ping',
				]);
			} else {
				$line = fgets(STDIN);
				if($line === false) break;
				$line = trim($line);
				if($line === '') continue;
				$response = $this->handleJson($line);
				if($response === '') continue;
			}
			echo $response . "\n";
			flush();
		}
	}
	
	/**
	 * Handle one JSON-RPC message encoded as JSON.
	 *
	 * @param string $json
	 * @return string Empty string for notifications
	 *
	 */
	public function handleJson(string $json): string {
		$message = json_decode($json, true);
		if(!is_array($message) || json_last_error() !== JSON_ERROR_NONE) {
			return $this->encode($this->errorResponse(null, -32700, 'Parse error: ' . json_last_error_msg()));
		}
		$response = $this->handleMessage($message);
		return $response === null ? '' : $this->encode($response);
	}

	/**
	 * Handle one decoded JSON-RPC message.
	 *
	 * @param array $message
	 * @return array|null
	 *
	 */
	public function handleMessage(array $message): ?array {
		$hasId = array_key_exists('id', $message);
		$id = $hasId ? $message['id'] : null;
		$method = (string) ($message['method'] ?? '');

		if($method === '') {
			if(array_key_exists('result', $message) || array_key_exists('error', $message)) return null;
			return $hasId ? $this->errorResponse($id, -32600, 'Invalid Request: missing method.') : null;
		}

		try {
			if($method === 'initialize') {
				return $hasId ? $this->successResponse($id, $this->initializeResult($message['params'] ?? [])) : null;
			}
			if($method === 'notifications/initialized') {
				return null;
			}
			if($method === 'ping') {
				return $hasId ? $this->successResponse($id, new \stdClass()) : null;
			}
			if($method === 'tools/list') {
				return $hasId ? $this->successResponse($id, ['tools' => $this->getTools()]) : null;
			}
			if($method === 'tools/call') {
				if(!$hasId) return null;
				$params = $message['params'] ?? [];
				if(!is_array($params)) return $this->errorResponse($id, -32602, 'Invalid params.');
				$name = (string) ($params['name'] ?? '');
				$args = $params['arguments'] ?? ($params['args'] ?? []);
				if($name === '') return $this->errorResponse($id, -32602, 'Missing tool name.');
				if(!is_array($args)) return $this->errorResponse($id, -32602, 'Tool arguments must be an object.');
				return $this->successResponse($id, $this->callTool($name, $args));
			}
			return $hasId ? $this->errorResponse($id, -32601, "Method not found: $method") : null;
		} catch(\Throwable $e) {
			return $hasId ? $this->errorResponse($id, -32603, $e->getMessage()) : null;
		}
	}

	/**
	 * Get MCP initialize result.
	 *
	 * @param array $params
	 * @return array
	 *
	 */
	protected function initializeResult(array $params): array {
		$info = AgentTools::getModuleInfo();
		$protocolVersion = (string) ($params['protocolVersion'] ?? self::protocolVersion);
		return [
			'protocolVersion' => $protocolVersion ?: self::protocolVersion,
			'capabilities' => [
				'tools' => new \stdClass(),
			],
			'serverInfo' => [
				'name' => 'AgentTools',
				'version' => (string) ($info['version'] ?? ''),
			],
		];
	}

	/**
	 * Get MCP tool definitions.
	 *
	 * @return array
	 *
	 */
	public function getTools(): array {
		return [
			$this->tool(
				'at_status',
				'Show AgentTools, ProcessWire, PHP, site path, generated site info files, and available MCP tool names.',
				[
					'type' => 'object',
					'properties' => new \stdClass(),
				]
			),
			$this->tool(
				'at_site_info',
				'Retrieve ProcessWire site information: page tree, fields/templates schema, or installed module classes.',
				[
					'type' => 'object',
					'properties' => [
						'type' => [
							'type' => 'string',
							'enum' => ['pages', 'schema', 'modules'],
							'description' => 'Information to retrieve. Use schema for fields/templates and ordered template fields.',
						],
						'refresh' => [
							'type' => 'boolean',
							'description' => 'Regenerate the page tree or schema before reading it.',
						],
					],
					'required' => ['type'],
				]
			),
			$this->tool(
				'at_api_docs',
				'List, retrieve, or search ProcessWire API.md documentation available to AgentTools.',
				[
					'type' => 'object',
					'properties' => [
						'action' => [
							'type' => 'string',
							'enum' => ['list', 'get', 'search'],
							'description' => 'Use list for doc names, get for full contents, search for matching lines.',
						],
						'name' => [
							'type' => 'string',
							'description' => 'Documentation name returned by action=list. Required for action=get.',
						],
						'term' => [
							'type' => 'string',
							'description' => 'Search term. Required for action=search.',
						],
					],
					'required' => ['action'],
				]
			),
			$this->tool(
				'at_read_file',
				'Read a file within this ProcessWire installation. Paths outside the ProcessWire root are denied.',
				[
					'type' => 'object',
					'properties' => [
						'path' => [
							'type' => 'string',
							'description' => "File path relative to the ProcessWire root, such as 'site/templates/home.php'.",
						],
					],
					'required' => ['path'],
				]
			),
			$this->tool(
				'at_migrations_list',
				'List AgentTools migrations and their applied/pending status without applying anything.',
				[
					'type' => 'object',
					'properties' => [
						'file' => [
							'type' => 'string',
							'description' => 'Optional exact migration filename to list.',
						],
						'name' => [
							'type' => 'string',
							'description' => 'Optional unique full or partial migration name to list.',
						],
					],
				]
			),
			$this->tool(
				'at_migrations_lint',
				'Check migration filenames, PHP syntax, and AgentTools conventions without executing migration files.',
				[
					'type' => 'object',
					'properties' => [
						'file' => [
							'type' => 'string',
							'description' => 'Optional exact migration filename to lint.',
						],
						'name' => [
							'type' => 'string',
							'description' => 'Optional unique full or partial migration name to lint.',
						],
					],
				]
			),
			$this->tool(
				'at_eval_readonly',
				'Evaluate read-only PHP code with ProcessWire API access. Mutating ProcessWire, database, filesystem, and shell calls are blocked before execution.',
				[
					'type' => 'object',
					'properties' => [
						'code' => [
							'type' => 'string',
							'description' => 'PHP code to evaluate, without an opening <?php tag.',
						],
					],
					'required' => ['code'],
				]
			),
		];
	}

	/**
	 * Call an MCP tool.
	 *
	 * @param string $name
	 * @param array $args
	 * @return array
	 *
	 */
	public function callTool(string $name, array $args): array {
		if($name === 'at_status') return $this->callStatus($args);
		if($name === 'at_site_info') return $this->callSiteInfo($args);
		if($name === 'at_api_docs') return $this->callApiDocs($args);
		if($name === 'at_read_file') return $this->callReadFile($args);
		if($name === 'at_migrations_list') return $this->callMigrationsList($args);
		if($name === 'at_migrations_lint') return $this->callMigrationsLint($args);
		if($name === 'at_eval_readonly') return $this->callEvalReadonly($args);
		return $this->toolError("Unknown tool: $name");
	}

	/**
	 * Call at_status.
	 *
	 * @param array $args
	 * @return array
	 *
	 */
	protected function callStatus(array $args): array {
		$moduleInfo = AgentTools::getModuleInfo();
		$config = $this->wire()->config;
		$filesPath = $this->at->getFilesPath();
		$pagesFile = $filesPath . 'site-map.json';
		$schemaFile = $filesPath . 'site-map-schema.json';
		$tools = array_map(function($tool) {
			return $tool['name'];
		}, $this->getTools());

		return $this->jsonResult([
			'agentTools' => [
				'version' => (string) ($moduleInfo['version'] ?? ''),
			],
			'processWire' => [
				'version' => (string) ($config->version ?? ''),
			],
			'php' => [
				'version' => PHP_VERSION,
			],
			'site' => [
				'rootPath' => $config->paths->root,
				'rootUrl' => $config->urls->root,
			],
			'generatedFiles' => [
				'pages' => [
					'path' => $pagesFile,
					'exists' => is_file($pagesFile),
					'modified' => is_file($pagesFile) ? date('c', filemtime($pagesFile)) : null,
				],
				'schema' => [
					'path' => $schemaFile,
					'exists' => is_file($schemaFile),
					'modified' => is_file($schemaFile) ? date('c', filemtime($schemaFile)) : null,
				],
			],
			'tools' => $tools,
		]);
	}

	/**
	 * Call at_site_info.
	 *
	 * @param array $args
	 * @return array
	 *
	 */
	protected function callSiteInfo(array $args): array {
		$type = (string) ($args['type'] ?? '');
		if(!in_array($type, ['pages', 'schema', 'modules'], true)) {
			return $this->toolError("Invalid type '$type'. Use pages, schema, or modules.");
		}
		return $this->textResult($this->at->engineer()->executeLocalTool('site_info', [
			'type' => $type,
			'refresh' => !empty($args['refresh']),
		]));
	}

	/**
	 * Call at_api_docs.
	 *
	 * @param array $args
	 * @return array
	 *
	 */
	protected function callApiDocs(array $args): array {
		$action = (string) ($args['action'] ?? 'list');
		if($action === 'get' && trim((string) ($args['name'] ?? '')) === '') {
			return $this->toolError('Missing required argument: name.');
		}
		if($action === 'search' && trim((string) ($args['term'] ?? '')) === '') {
			return $this->toolError('Missing required argument: term.');
		}
		if(!in_array($action, ['list', 'get', 'search'], true)) {
			return $this->toolError("Invalid api_docs action '$action'.");
		}
		return $this->textResult($this->at->engineer()->executeLocalTool('api_docs', $args));
	}

	/**
	 * Call at_read_file.
	 *
	 * @param array $args
	 * @return array
	 *
	 */
	protected function callReadFile(array $args): array {
		$path = trim((string) ($args['path'] ?? ''));
		if($path === '') return $this->toolError('Missing required argument: path.');
		return $this->textResult($this->at->engineer()->executeLocalTool('read_file', ['path' => $path]));
	}

	/**
	 * Call at_migrations_list.
	 *
	 * @param array $args
	 * @return array
	 *
	 */
	protected function callMigrationsList(array $args): array {
		$error = '';
		$files = $this->selectMigrationFiles($args, $error);
		if($error !== '') return $this->toolError($error);

		$items = [];
		$applied = 0;
		$pending = 0;
		foreach($files as $file) {
			$isApplied = $this->at->migrations()->isApplied($file);
			$isApplied ? $applied++ : $pending++;
			$info = $this->at->migrations()->getInfo($file);
			$items[] = [
				'file' => basename($file),
				'name' => $this->at->migrations()->getName($file),
				'title' => $info['title'],
				'datetime' => $info['datetime'],
				'status' => $isApplied ? 'applied' : 'pending',
				'summary' => $info['summary'],
			];
		}

		return $this->jsonResult([
			'count' => count($items),
			'applied' => $applied,
			'pending' => $pending,
			'migrations' => $items,
		]);
	}

	/**
	 * Call at_migrations_lint.
	 *
	 * @param array $args
	 * @return array
	 *
	 */
	protected function callMigrationsLint(array $args): array {
		$error = '';
		$files = $this->selectMigrationFiles($args, $error);
		if($error !== '') return $this->toolError($error);

		$items = [];
		$errorCount = 0;
		$warningCount = 0;
		foreach($files as $file) {
			$result = $this->at->migrations()->lintFile($file);
			$errorCount += count($result['errors']);
			$warningCount += count($result['warnings']);
			$items[] = [
				'file' => basename($file),
				'errors' => $result['errors'],
				'warnings' => $result['warnings'],
			];
		}

		return $this->jsonResult([
			'checked' => count($files),
			'errors' => $errorCount,
			'warnings' => $warningCount,
			'files' => $items,
		]);
	}

	/**
	 * Call at_eval_readonly.
	 *
	 * @param array $args
	 * @return array
	 *
	 */
	protected function callEvalReadonly(array $args): array {
		$code = (string) ($args['code'] ?? '');
		if(trim($code) === '') return $this->toolError('Missing required argument: code.');
		return $this->textResult($this->at->engineer()->executeLocalTool('eval_php', ['code' => $code]));
	}

	/**
	 * Select migration files from optional file/name args.
	 *
	 * @param array $args
	 * @param string $error
	 * @return array
	 *
	 */
	protected function selectMigrationFiles(array $args, string &$error): array {
		$error = '';
		$file = trim((string) ($args['file'] ?? ''));
		$name = trim((string) ($args['name'] ?? ''));
		if($file !== '' && $name !== '') {
			$error = 'Use either file or name, not both.';
			return [];
		}

		$dir = $this->at->getFilesPath('migrations');
		$files = $this->at->migrations()->getFiles($dir);
		if($file !== '') {
			$fileName = basename($file);
			$files = array_values(array_filter($files, function($path) use($fileName) {
				return basename($path) === $fileName;
			}));
			if(empty($files)) $error = "Migration file not found: $fileName";
			return $files;
		}

		if($name === '') return $files;

		$needle = strtolower(preg_replace('/\.php$/', '', $name));
		$needle = str_replace('_', '-', $needle);
		$matches = [];
		foreach($files as $path) {
			$base = strtolower(basename($path, '.php'));
			$migrationName = strtolower(str_replace('_', '-', $this->at->migrations()->getName($path)));
			if($base === $needle || $migrationName === $needle || strpos($base, $needle) !== false || strpos($migrationName, $needle) !== false) {
				$matches[] = $path;
			}
		}
		if(empty($matches)) {
			$error = "Migration name not found: $name";
		} else if(count($matches) > 1) {
			$lines = ["Migration name is ambiguous: $name"];
			foreach($matches as $path) $lines[] = '  - ' . basename($path);
			$lines[] = 'Use file to select one exact migration.';
			$error = implode("\n", $lines);
		}
		return $matches;
	}

	/**
	 * Build a MCP tool definition.
	 *
	 * @param string $name
	 * @param string $description
	 * @param array $inputSchema
	 * @return array
	 *
	 */
	protected function tool(string $name, string $description, array $inputSchema): array {
		return [
			'name' => $name,
			'description' => $description,
			'inputSchema' => $inputSchema,
		];
	}

	/**
	 * Build a text tool result.
	 *
	 * @param string $text
	 * @param bool $isError
	 * @return array
	 *
	 */
	protected function textResult(string $text, bool $isError = false): array {
		$result = [
			'content' => [
				[
					'type' => 'text',
					'text' => $text,
				],
			],
		];
		if($isError) $result['isError'] = true;
		return $result;
	}

	/**
	 * Build a JSON-as-text tool result.
	 *
	 * @param array $data
	 * @return array
	 *
	 */
	protected function jsonResult(array $data): array {
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
		return $this->textResult($json === false ? 'null' : $json);
	}

	/**
	 * Build a tool-level error result.
	 *
	 * @param string $message
	 * @return array
	 *
	 */
	protected function toolError(string $message): array {
		return $this->textResult($message, true);
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @param mixed $id
	 * @param mixed $result
	 * @return array
	 *
	 */
	protected function successResponse($id, $result): array {
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result,
		];
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @param mixed $id
	 * @param int $code
	 * @param string $message
	 * @return array
	 *
	 */
	protected function errorResponse($id, int $code, string $message): array {
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		];
	}

	/**
	 * Encode a JSON-RPC message.
	 *
	 * @param array $message
	 * @return string
	 *
	 */
	protected function encode(array $message): string {
		$json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
		return $json === false ? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"JSON encode failed."}}' : $json;
	}
}
