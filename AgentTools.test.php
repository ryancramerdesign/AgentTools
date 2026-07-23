<?php namespace ProcessWire;

/**
 * Fast, deterministic tests for AgentTools helper behavior.
 *
 */
class WireTest_AgentTools extends WireTest {

	/**
	 * Temporary files created by tests.
	 *
	 * @var array
	 *
	 */
	protected $tmpFiles = [];

	/**
	 * Temporary directories created by tests.
	 *
	 * @var array
	 *
	 */
	protected $tmpDirs = [];

	/**
	 * Run tests.
	 *
	 */
	public function execute() {
		$at = $this->wire()->modules->get('AgentTools');
		$this->check('AgentTools module is installed', true, $at instanceof AgentTools);

		$this->testEvalValidation($at);
		$this->testCliEvalParsing($at);
		$this->testCliEvalJson($at);
		$this->testReadFileRanges($at);
		$this->testReadFileSymlinks($at);
		$this->testMigrationLint($at);
		$this->testSaveMigrationReportedPath($at);
		$this->testSchemaTemplateFields($at);
		$this->testMcpMessageShapes($at);
		$this->testOpenAIResponsesToolShapes($at);
		$this->testStatusData($at);
		$this->testScheduledTaskIntervals($at);
		$this->testTraceJsonEncoding($at);
	}

	/**
	 * Clean up temporary files.
	 *
	 */
	public function finish() {
		foreach(array_reverse($this->tmpFiles) as $file) {
			if(is_file($file)) unlink($file);
		}
		foreach(array_reverse($this->tmpDirs) as $dir) {
			if(is_dir($dir)) rmdir($dir);
		}
	}

	/**
	 * Test eval PHP validation, including read-only mutation blocking.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testEvalValidation(AgentTools $at) {
		$engineer = $at->engineer();

		$this->check('Read-only validation allows read snippets', '', $engineer->validateEvalPhp('echo $pages->count();', true, 'Read-only mode'));
		$this->check('Read-only validation blocks ProcessWire save()', 'Read-only mode blocked mutating eval_php method: save().', $engineer->validateEvalPhp('$page->save();', true, 'Read-only mode'));
		$this->check('Read-only validation blocks filesystem writes', 'Read-only mode blocked mutating eval_php function: file_put_contents().', $engineer->validateEvalPhp('file_put_contents("/tmp/at-test", "x");', true, 'Read-only mode'));
		$this->check('Read-only validation blocks WireFileTools writes', 'Read-only mode blocked mutating eval_php method: fileputcontents().', $engineer->validateEvalPhp('$files->filePutContents("/tmp/at-test", "x");', true, 'Read-only mode'));
		$this->check('Read-only validation blocks module config writes', 'Read-only mode blocked mutating eval_php method: saveconfig().', $engineer->validateEvalPhp('$modules->saveConfig("AgentTools", []);', true, 'Read-only mode'));
		$this->check('Read-only validation blocks array callback writes', 'Read-only mode blocked mutating eval_php callback: save().', $engineer->validateEvalPhp('call_user_func([$page, "save"]);', true, 'Read-only mode'));
		$this->check('Read-only validation blocks dynamic method calls', 'Read-only mode blocked dynamic eval_php method call.', $engineer->validateEvalPhp('$method = "save"; $page->$method();', true, 'Read-only mode'));
		$this->check('Normal eval validation allows ProcessWire save() syntax', '', $engineer->validateEvalPhp('$page->save();'));
	}

	/**
	 * Test CLI eval argument parsing and source normalization.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testCliEvalParsing(AgentTools $at) {
		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ '--readonly', 'echo 1;' ]]);
		$this->check('Eval parser detects --readonly', true, $options['readOnly']);
		$this->check('Eval parser keeps code after --readonly', 'echo 1;', $options['code']);
		$this->check('Eval parser reports no error for valid readonly code', '', $options['error']);

		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ 'echo', '$pages->count();', '--readonly=false' ]]);
		$this->check('Eval parser supports --readonly=false', false, $options['readOnly']);
		$this->check('Eval parser joins code arguments', 'echo $pages->count();', $options['code']);

		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ '--json', 'return 123;' ]]);
		$this->check('Eval parser detects --json', true, $options['json']);
		$this->check('Eval parser keeps code after --json', 'return 123;', $options['code']);

		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ '--json=false', 'return 123;' ]]);
		$this->check('Eval parser supports --json=false', false, $options['json']);

		$options = $this->invokeProtected($at, 'parseCliEvalArgs', [[ '--unknown', 'echo 1;' ]]);
		$this->check('Eval parser rejects unknown options', 'Unknown eval option: --unknown', $options['error']);

		$normalized = $this->invokeProtected($at, 'normalizeCliEvalCode', [ "\xEF\xBB\xBF<?php echo \"ok\";\n" ]);
		$this->check('Eval normalizer removes BOM and opening PHP tag', "echo \"ok\";\n", $normalized);

		$normalized = $this->invokeProtected($at, 'normalizeCliEvalCode', [ "  echo \"ok\";\n" ]);
		$this->check('Eval normalizer trims leading whitespace', "echo \"ok\";\n", $normalized);
	}

	/**
	 * Test JSON output from CLI eval.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testCliEvalJson(AgentTools $at) {
		$fuel = $this->wire()->fuel->getArray();

		ob_start();
		$success = $this->invokeProtected($at, 'cliEval', [ 'return ["count" => 123, "page" => $pages->get(1)];', $fuel, [ 'json' => true ] ]);
		$data = $this->decodeJson((string) ob_get_clean());
		$this->check('CLI eval JSON succeeds', true, $success);
		$this->check('CLI eval JSON reports ok', true, $data['ok']);
		$this->check('CLI eval JSON returns array values', 123, $data['return']['count']);
		$this->check('CLI eval JSON summarizes Page objects', 1, $data['return']['page']['id']);

		ob_start();
		$success = $this->invokeProtected($at, 'cliEval', [ 'throw new WireException("Nope");', $fuel, [ 'json' => true ] ]);
		$data = $this->decodeJson((string) ob_get_clean());
		$this->check('CLI eval JSON error returns false', false, $success);
		$this->check('CLI eval JSON error reports not ok', false, $data['ok']);
		$this->check('CLI eval JSON error includes message', 'Nope', $data['error']['message']);
	}

	/**
	 * Test read_file offset/limit ranges.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testReadFileRanges(AgentTools $at) {
		$file = $this->wire()->config->paths->assets . 'at-read-file-test.txt';
		$this->writeTempFile($file, '0123456789abcdefghijklmnopqrstuvwxyz');

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $file ]);
		$this->check('read_file returns full file', '0123456789abcdefghijklmnopqrstuvwxyz', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $file, 'offset' => 10, 'limit' => 5 ]);
		$this->check('read_file returns byte range', 'abcde', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $file, 'limit' => 5 ]);
		$this->check('read_file honors limit without offset', '01234', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $file, 'offset' => 40, 'limit' => 5 ]);
		$this->check('read_file range beyond EOF returns blank', '', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => 'wire/core/Functions.php', 'offset' => 0, 'limit' => 5 ]);
		$this->check('read_file allows configured wire path', '<?php', $result);
	}

	/**
	 * Test read_file symlink handling.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testReadFileSymlinks(AgentTools $at) {
		if(!function_exists('symlink')) return;

		$outsideDir = $this->makeTempDir();
		$outsideFile = $outsideDir . 'outside.txt';
		$this->writeTempFile($outsideFile, 'outside-module');

		$moduleLink = $this->wire()->config->paths->siteModules . 'at-read-file-test.txt';
		if(!@symlink($outsideFile, $moduleLink)) return;
		$this->tmpFiles[] = $moduleLink;

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $moduleLink ]);
		$this->check('read_file allows site/modules symlinks', 'outside-module', $result);

		$targetFile = $this->wire()->config->paths->assets . 'at-read-file-target.txt';
		$this->writeTempFile($targetFile, 'target');
		$assetLink = $this->wire()->config->paths->assets . 'at-read-file-link.txt';
		if(!@symlink($targetFile, $assetLink)) return;
		$this->tmpFiles[] = $assetLink;

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => $assetLink ]);
		$this->check('read_file denies non-module symlinks', 'Access denied: symlinks are not allowed for this file.', $result);

		$result = $at->engineer->executeLocalTool('read_file', [ 'path' => 'site/modules/../config.php' ]);
		$this->check('read_file denies traversal paths', 'Access denied: invalid file path.', $result);
	}

	/**
	 * Test migration option parsing and static lint checks.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testMigrationLint(AgentTools $at) {
		$migrations = $at->migrations();

		$options = $this->invokeProtected($migrations, 'parseSelectionOptions', [[ '--file', '20260101000000_test.php' ]]);
		$this->check('Migration parser accepts --file value', '20260101000000_test.php', $options['file']);
		$this->check('Migration parser leaves --file parse error blank', '', $options['error']);

		$options = $this->invokeProtected($migrations, 'parseSelectionOptions', [[ '--file=a.php', '--name=a' ]]);
		$this->check('Migration parser rejects --file with --name', 'Use either --file or --name, not both.', $options['error']);

		$dir = $this->makeTempDir();
		$good = $dir . '20260101000000_add_subtitle.php';
		$this->writeTempFile($good, "<?php namespace ProcessWire;\n\n\$name = wire('at')->migrations->getName(__FILE__);\necho \"# \$name\\n\\n\";\nif(\$fields->get('subtitle')) {\n\techo \"- Skipped existing field: subtitle\\n\";\n\treturn;\n}\necho \"- \$name has been applied\\n\";\n");

		$result = $this->invokeProtected($migrations, 'lintFile', [ $good ]);
		$this->check('Migration lint accepts standard migration fixture errors', [], $result['errors']);
		$this->check('Migration lint accepts standard migration fixture warnings', [], $result['warnings']);

		$bad = $dir . 'bad.php';
		$this->writeTempFile($bad, "<?php echo \"not namespaced\";\n");
		$result = $this->invokeProtected($migrations, 'lintFile', [ $bad ]);
		$this->check('Migration lint catches bad filename', true, in_array('Filename should match YYYYMMDDhhmmss_description.php.', $result['errors'], true));
		$this->check('Migration lint catches missing namespace', true, in_array('File should begin with "<?php namespace ProcessWire;".', $result['errors'], true));
		$this->check('Migration lint warns about missing getName()', true, in_array('Missing standard $name assignment with wire(\'at\')->migrations->getName(__FILE__).', $result['warnings'], true));
	}

	/**
	 * Test save_migration reports the canonical AgentTools migrations path.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testSaveMigrationReportedPath(AgentTools $at) {
		$engineer = $at->engineer();
		$result = $this->invokeProtected($engineer, 'executeSaveMigration', [
			"<?php namespace ProcessWire;\n\necho \"test migration\\n\";\n",
			'agenttools_selftest_path',
			'Self-test migration path report.',
		]);
		$this->check('save_migration reports AgentTools migrations path', true, strpos($result, 'Migration saved: site/assets/at/migrations/') === 0);
		$filename = basename($result);
		$file = $at->getFilesPath('migrations') . $filename;
		$this->check('save_migration wrote reported file', true, is_file($file));
		$this->tmpFiles[] = $file;
	}

	/**
	 * Test schema template data includes ordered template fields.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testSchemaTemplateFields(AgentTools $at) {
		$sitemap = $at->sitemap();
		$templates = $this->invokeProtected($sitemap, 'getSchemaTemplatesData');
		$this->check('Schema template data is an array', true, is_array($templates));
		$this->check('Schema contains home template', true, isset($templates['home']));
		$this->check('Home template includes fields array', true, isset($templates['home']['fields']) && is_array($templates['home']['fields']));
		$this->check('Home template fields include title', true, in_array('title', $templates['home']['fields'], true));
	}

	/**
	 * Test MCP JSON-RPC message shapes without starting a stdio server.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testMcpMessageShapes(AgentTools $at) {
		$mcp = $at->mcp();

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 1,
			'method' => 'initialize',
			'params' => ['protocolVersion' => '2025-06-18'],
		])));
		$this->check('MCP initialize returns protocol version', '2025-06-18', $response['result']['protocolVersion']);
		$this->check('MCP initialize exposes tools capability', true, isset($response['result']['capabilities']['tools']));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 2,
			'method' => 'tools/list',
		])));
		$tools = $response['result']['tools'];
		$names = array_column($tools, 'name');
		$this->check('MCP tools/list includes at_status', true, in_array('at_status', $names, true));
		$this->check('MCP tools/list includes at_site_info', true, in_array('at_site_info', $names, true));
		$this->check('MCP tools/list includes at_eval_readonly', true, in_array('at_eval_readonly', $names, true));
		$this->check('MCP tool definitions use inputSchema', true, isset($tools[0]['inputSchema']));

		$readFileTool = null;
		foreach($tools as $tool) {
			if($tool['name'] === 'at_read_file') {
				$readFileTool = $tool;
				break;
			}
		}
		$this->check('MCP read_file tool supports offset', true, isset($readFileTool['inputSchema']['properties']['offset']));
		$this->check('MCP read_file tool supports limit', true, isset($readFileTool['inputSchema']['properties']['limit']));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 3,
			'method' => 'tools/call',
			'params' => [
				'name' => 'at_eval_readonly',
				'arguments' => ['code' => 'echo $pages->count();'],
			],
		])));
		$this->check('MCP tool call returns text content', 'text', $response['result']['content'][0]['type']);
		$this->check('MCP readonly eval returns numeric output', true, ctype_digit(trim($response['result']['content'][0]['text'])));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 4,
			'method' => 'tools/call',
			'params' => [
				'name' => 'at_eval_readonly',
				'arguments' => ['code' => '$page->save();'],
			],
		])));
		$this->check('MCP readonly eval blocks save()', 'ERROR: Preview-only mode blocked mutating eval_php method: save().', $response['result']['content'][0]['text']);

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 5,
			'method' => 'tools/call',
			'params' => [
				'name' => 'at_migrations_list',
				'arguments' => [],
			],
		])));
		$list = $this->decodeJson($response['result']['content'][0]['text']);
		$this->check('MCP migrations list returns count', true, isset($list['count']));
		$this->check('MCP migrations list returns migrations array', true, isset($list['migrations']) && is_array($list['migrations']));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 6,
			'method' => 'tools/call',
			'params' => [
				'name' => 'at_status',
				'arguments' => [],
			],
		])));
		$status = $this->decodeJson($response['result']['content'][0]['text']);
		$this->check('MCP status returns AgentTools version', true, isset($status['agentTools']['version']));
		$this->check('MCP status includes tool names', true, in_array('at_eval_readonly', $status['tools'], true));

		$response = $this->decodeJson($mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 7,
			'method' => 'tools/call',
			'params' => [
				'name' => 'not_a_tool',
				'arguments' => [],
			],
		])));
		$this->check('MCP unknown tool returns tool error', true, !empty($response['result']['isError']));

		$response = $this->decodeJson($mcp->handleJson('{'));
		$this->check('MCP invalid JSON returns parse error', -32700, $response['error']['code']);

		$this->check('MCP initialized notification returns no response', '', $mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'method' => 'notifications/initialized',
		])));

		$this->check('MCP JSON-RPC responses return no response', '', $mcp->handleJson(json_encode([
			'jsonrpc' => '2.0',
			'id' => 'heartbeat-1',
			'result' => new \stdClass(),
		])));
	}

	/**
	 * Test OpenAI Responses API tool definition and tool-call message shapes.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testOpenAIResponsesToolShapes(AgentTools $at) {
		$engineer = $at->engineer();
		$chatTools = $engineer->getToolDefinitions(AgentToolsEngineer::providerOpenAI);
		$responsesTools = $this->invokeProtected($engineer, 'buildOpenAIResponsesTools', [ $chatTools ]);
		$this->check('Responses tools use top-level name', 'eval_php', $responsesTools[0]['name'] ?? '');
		$this->check('Responses tools omit chat function wrapper', false, isset($responsesTools[0]['function']));

		$response = [
			'output' => [[
				'type' => 'function_call',
				'id' => 'fc_123',
				'call_id' => 'call_123',
				'name' => 'site_info',
				'arguments' => '{"type":"pages"}',
			]],
		];
		$calls = $this->invokeProtected($engineer, 'extractToolCalls', [ AgentToolsEngineer::providerOpenAI, $response ]);
		$this->check('Responses function calls are extracted', 'site_info', $calls[0]['name'] ?? '');
		$this->check('Responses function call id is retained', 'call_123', $calls[0]['call_id'] ?? '');
		$this->check('Responses function call arguments decode', 'pages', $calls[0]['input']['type'] ?? '');

		$messages = [];
		$args = [ AgentToolsEngineer::providerOpenAI, &$messages, $response ];
		$this->invokeProtected($engineer, 'appendAssistantMessage', $args);
		$args = [ AgentToolsEngineer::providerOpenAI, &$messages, $calls[0], 'ok' ];
		$this->invokeProtected($engineer, 'appendToolResult', $args);
		$input = $this->invokeProtected($engineer, 'buildOpenAIResponsesInput', [ $messages ]);
		$this->check('Responses input retains function call item', 'function_call', $input[0]['type'] ?? '');
		$this->check('Responses input appends function_call_output item', 'function_call_output', $input[1]['type'] ?? '');
		$this->check('Responses input output references call id', 'call_123', $input[1]['call_id'] ?? '');

		$text = $engineer->extractText(AgentToolsEngineer::providerOpenAI, [
			'output' => [[
				'type' => 'message',
				'content' => [[ 'type' => 'output_text', 'text' => 'done' ]],
			]],
		]);
		$this->check('Responses output text is extracted', 'done', $text);
	}

	/**
	 * Test shared AgentTools status data.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testStatusData(AgentTools $at) {
		$status = $at->getStatusData();
		$this->check('Status data includes AgentTools version', true, isset($status['agentTools']['version']));
		$this->check('Status data includes AgentTools files path', true, isset($status['agentTools']['filesPath']));
		$this->check('Status data includes AgentTools htaccess file', true, isset($status['agentTools']['htaccessFile']['file']));
		$this->check('Status data includes ProcessWire version', true, isset($status['processWire']['version']));
		$this->check('Status data includes ProcessWire URLs', true, isset($status['processWire']['rootUrl'], $status['processWire']['httpRootUrl']));
		$this->check('Status data includes sitemap files', true, isset($status['sitemaps']['pages']['file'], $status['sitemaps']['schema']['file']));
		$this->check('Status data includes migration counts', true, isset($status['migrations']['count'], $status['migrations']['applied'], $status['migrations']['pending']));
		$this->check('Status data includes job counts', true, isset($status['jobs']['counts']['pending'], $status['jobs']['counts']['failed']));
		$this->check('Status data includes cron health', true, isset($status['jobs']['cron']['healthy']));
		$this->check('Status data includes status command', true, in_array('php index.php --at-status [--json]', $status['cliCommands'], true));
		$this->check('Status data includes self-test command', true, in_array('php index.php --at-test [--json]', $status['cliCommands'], true));
	}

	/**
	 * Test scheduled task interval next-run calculations.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testScheduledTaskIntervals(AgentTools $at) {
		$schedules = $at->getScheduledTasks();
		$task = $schedules->makeBlankItem();
		$after = strtotime('2026-07-03 12:00:00');
		$intervals = [
			'2-minutes' => 2 * 60,
			'5-minutes' => 5 * 60,
			'10-minutes' => 10 * 60,
			'15-minutes' => 15 * 60,
		];

		foreach($intervals as $frequency => $seconds) {
			$task->frequency = $frequency;
			$this->check("Scheduled task interval $frequency", $after + $seconds, $schedules->calculateNextRun($task, $after));
		}
	}

	/**
	 * Test trace JSON encoding tolerates malformed UTF-8 from provider/tool output.
	 *
	 * @param AgentTools $at
	 *
	 */
	protected function testTraceJsonEncoding(AgentTools $at) {
		$traces = $at->getTraces();
		$trace = $traces->newTrace([
			'type' => 'task',
			'provider' => 'test',
			'model' => 'test-model',
		]);
		$trace->response = "bad byte: \xB1";
		$file = $traces->save($trace);
		$this->tmpFiles[] = $file;
		$json = file_get_contents($file);
		$data = json_decode((string) $json, true);
		$this->check('Trace JSON with malformed UTF-8 saves valid JSON', true, is_array($data));
		$this->check('Trace JSON substitutes malformed UTF-8', JSON_ERROR_NONE, json_last_error());
	}

	/**
	 * Invoke a protected method for focused helper testing.
	 *
	 * @param object $object
	 * @param string $method
	 * @param array $args
	 * @return mixed
	 *
	 */
	protected function invokeProtected($object, string $method, array $args = []) {
		$reflection = new \ReflectionMethod($object, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($object, $args);
	}

	/**
	 * Make a temporary directory.
	 *
	 * @return string
	 *
	 */
	protected function makeTempDir(): string {
		$base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$dir = $base . 'agenttools-test-' . uniqid('', true) . DIRECTORY_SEPARATOR;
		if(!mkdir($dir, 0700, true) && !is_dir($dir)) {
			$this->fail("Unable to create temp dir: $dir");
		}
		$this->tmpDirs[] = $dir;
		return $dir;
	}

	/**
	 * Write a temporary file and remember it for cleanup.
	 *
	 * @param string $file
	 * @param string $content
	 *
	 */
	protected function writeTempFile(string $file, string $content) {
		if(file_put_contents($file, $content) === false) {
			$this->fail("Unable to write temp file: $file");
		}
		$this->tmpFiles[] = $file;
	}

	/**
	 * Decode JSON or fail the test.
	 *
	 * @param string $json
	 * @return array
	 *
	 */
	protected function decodeJson(string $json): array {
		$data = json_decode($json, true);
		if(!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
			$this->fail('Invalid JSON in test response: ' . json_last_error_msg());
		}
		return $data;
	}
}
