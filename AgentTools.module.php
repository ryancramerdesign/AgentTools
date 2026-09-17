<?php namespace ProcessWire;

/**
 * AgentTools
 *
 * Enables AI coding agents to access ProcessWire's API via CLI, and provides
 * a database migration system for transferring changes across environments.
 *
 * Copyright 2026 Ryan Cramer with help from Claude (Anthropic), GPT 5.5 Codex
 *
 * @property AgentToolsMigrations $migrations
 * @property AgentToolsSitemap $sitemap
 * @property AgentToolsEngineer $engineer
 * @property AgentToolsTasks $tasks
 * @property AgentToolsJobs $jobs
 * @property AgentToolsTraces $traces
 * @property AgentToolsSiteBuilder $siteBuilder
 *
 * @property string $engineer_provider
 * @property string $engineer_api_key
 * @property string $engineer_model
 * @property string $engineer_endpoint
 * @property string $engineer_label
 * @property string $engineer_description
 * @property string $engineer_agent_name
 * @property int|bool $engineer_readonly
 * @property string $engineer_instructions
 * @property string $engineer_memory
 * @property int $engineer_mem_qty
 * @property int $engineer_max_iterations
 * @property int $engineer_request_timeout
 * @property int|bool $engineer_allow_include
 * @property string $engineer_email_from
 * @property int|bool $engineer_debug_mode
 * @property string $engineer_trace_mode
 * @property int $engineer_trace_keep_days
 * @property int|bool $engineer_trace_include_content
 * @property string $engineer_additional_models
 * @property string|int $engineer_user
 *
 * @method AgentToolsEngineer engineer()
 * @method AgentToolsMigrations migrations()
 * @method AgentToolsTasks tasks()
 * @method AgentToolsJobs jobs()
 * @method AgentToolsTraces traces()
 * @method AgentToolsSitemap sitemap()
 * @method AgentToolsSiteBuilder siteBuilder()
 *
 */
class AgentTools extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return [
			'title' => 'Agent Tools',
			'summary' => "Enables AI coding agents to access ProcessWire's API and provides a database migration system.",
			'icon' => 'at',
			'version' => 31,
			'author' => 'Ryan Cramer, Claude (Anthropic), GPT 5.5 Codex',
			'requires' => 'ProcessWire>=3.0.255, PHP>=8.0.0',
			'installs' => 'ProcessAgentTools, FieldtypePageEngineer',
			'autoload' => true,
			'singular' => true,
			'cli' => 'at', // cli name recognized by ProcessWire 3.0.259+
		];
	}

	/**
	 * Name used in this module's assets directory and CLI prefix
	 *
	 */
	const name = 'at';

	/**
	 * Helpers indexed by name
	 *
	 * @var array|AgentToolsHelper[]
	 *
	 */
	protected $helpers = [
		'migrations' => null,
		'sitemap' => null,
		'engineer' => null,
		'mcp' => null,
		'jobs' => null,
		'skills' => null,
		'siteBuilder' => null,
	];

	/**
	 * @var AgentToolsAgents|null
	 *
	 */
	protected $agents = null;

	/**
	 * @var AgentToolsTasks|null
	 *
	 */
	protected $tasks = null;

	/**
	 * @var AgentToolsScheduledTasks|null
	 *
	 */
	protected $scheduledTasks = null;

	/**
	 * @var AgentToolsTraces|null
	 *
	 */
	protected $traces = null;

	protected $action = '';

	/**
	 * Construct
	 *
	 */
	public function __construct() {
		parent::__construct();
		// establish config variables with defaults
		$keys = [
			'provider', 'api_key', 'model', 'endpoint',
			'label', 'readonly', 'additional_models',
			'agent_name', 'instructions', 'memory', 'mem_qty', 'max_iterations', 'request_timeout',
			'allow_include', 'email_from', 'debug_mode', 'trace_mode', 'trace_keep_days', 'trace_include_content',
			'suspicious', 'suspicious_email', 'suspicious_log', 'user',
		];
		foreach($keys as $key) {
			$this->set("engineer_$key", "");
		}
	}

	/**
	 * Called when module is wired to API
	 *
	 * Creates an `$at` ProcessWire API variable
	 *
	 */
	public function wired() {
		$this->wire()->wire(self::name, $this);
		parent::wired();
	}

	/**
	 * ProcessWire API ready
	 *
	 */
	public function ready() {
		$at = $this;
		$methods = 'WireSaveableItems::saved, WireSaveableItems::added, WireSaveableItems::deleted';

		$this->addHookAfter($methods, function(HookEvent $e) use($at) {
			$item = $e->arguments(0); /** @var Template|Fieldgroup $template */
			$name = strtolower($item->className());
			if(in_array($name, [ 'template', 'fieldgroup', 'field' ])) {
				$path = $at->getFilesPath();
				if($name === 'fieldgroup') $name = 'template';
				$method = $e->method;
				$fp = fopen($path . "{$name}s.txt", 'a');
				if($fp !== false) {
					fwrite($fp, "$method\t$item->name\t" . date('Y-m-d H:i:s') . "\n");
					fclose($fp);
				}
			}
		});

		if(php_sapi_name() !== 'cli') return;

		$prefix = '--' . self::name . '-';
		$argv = $_SERVER['argv'];
		$action = empty($argv[1]) ? '' : $argv[1];

		if(count($argv) < 2) return;
		if(strpos($action, $prefix) !== 0) return;

		$action = str_replace($prefix, '', $action);

		if(version_compare($this->wire()->config->version, '3.0.260', '<')) {
			// for PW versions prior to CliModule interface (3.0.259 and prior)
			$this->cliReady($action);
			return;
		}

		$this->action = $action;

		$this->addHookBefore('ProcessWireCli::ready', function(HookEvent $e) use($action, $argv) {
			/** @var ProcessWireCli $pwCli */
			$e->arguments(0, self::name);
			$e->arguments(1, [ $action ]);
		});
	}

	/**
	 * Execute CLI action (used by ProcessWire 3.0.260+)
	 *
	 * @param array $args
	 *
	 */
	public function executeCli(array $args) {
		if($this->action) $this->cliReady($this->action);
	}

	/**
	 * Command line interface (CLI) ready
	 *
	 * Please note that this method halts execution when it's done, rather than return.
	 *
	 * @param string $action
	 *
	 */
	protected function cliReady($action) {

		$atAction = $action;
		$originalDir = getcwd();
		chdir($this->wire()->config->paths->root);

		$at = $this;
		$success = false;
		$showHelpOnFailure = true;
		$fuel = $this->wire()->fuel->getArray();
		extract($fuel);

		if($atAction === 'cli') {
			$name = 'agent_cli.php';
			$srcFile = __DIR__ . "/$name";
			if(is_writable($srcFile)) {
				// site/modules/AgentTools/agent_cli.php
				$file = $srcFile;
			} else {
				// site/assets/at/agent_cli.php
				$file = $this->getFilesPath() . $name;
				if(!file_exists($file)) {
					$this->wire()->files->copy($srcFile, $file);
				}
			}
			if(file_exists($file)) {
				echo "// agent_cli.php: $file\n";
				$success = include($file);
			} else {
				echo "ERROR: Unable to locate agent_cli.php file\n";
			}

		} else if($atAction === 'eval' && count($GLOBALS['argv']) > 2) {
			$showHelpOnFailure = false;
			$evalOptions = $this->parseCliEvalArgs(array_slice($GLOBALS['argv'], 2));
			if($evalOptions['error']) {
				echo "ERROR: {$evalOptions['error']}\n";
				$success = false;
			} else {
				$success = $this->cliEval($evalOptions['code'], $fuel, [
					'readOnly' => $evalOptions['readOnly'],
					'json' => $evalOptions['json'],
				]);
			}

		} else if($atAction === 'stdin') {
			$showHelpOnFailure = false;
			$code = file_get_contents('php://stdin');
			$evalOptions = $this->parseCliEvalArgs(array_slice($GLOBALS['argv'], 2), false);
			if($evalOptions['error']) {
				echo "ERROR: {$evalOptions['error']}\n";
				$success = false;
			} else if(strlen(trim($code))) {
				$success = $this->cliEval($code, $fuel, [
					'readOnly' => $evalOptions['readOnly'],
					'json' => $evalOptions['json'],
				]);
			}

		} else if($atAction === 'cron') {
			$showHelpOnFailure = false;
			$success = $this->jobs()->cliExecute('cron');

		} else if($atAction === 'help') {
			echo $this->renderHelp();
			$success = true;

		} else if($atAction === 'status') {
			$showHelpOnFailure = false;
			$success = $this->cliStatus(array_slice($GLOBALS['argv'], 2));

		} else if($atAction === 'test' || $atAction === 'selftest') {
			$showHelpOnFailure = false;
			$success = $this->cliTest(array_slice($GLOBALS['argv'], 2));

		} else {
			$found = false;
			foreach(array_keys($this->helpers) as $name) {
				if($atAction === $name) {
					$act = '';
				} else if(strpos($atAction, "$name-") === 0) {
					$act = substr($atAction, strlen($name) + 1);
				} else {
					continue;
				}
				$helper = $this->getHelper($name);
				if(!$helper) continue;
				$success = $helper->cliExecute($act);
				$showHelpOnFailure = ($success === null);
				$found = true;
				break;
			}
			if(!$found) {
				echo "Unrecognized AgentTools action: $atAction\n";
				$success = false;
			}
		}

		chdir($originalDir);
		$this->wire()->finished();

		if(!$success && $showHelpOnFailure) {
			echo $this->renderHelp();
		}

		if(!$success) {
			exit(1);
		}

		exit(0);
	}

	/**
	 * Evaluate PHP code string in the context of PW API variables
	 *
	 * @param string $code PHP code to evaluate, optionally with opening <?php tag
	 * @param array $fuel ProcessWire API variables
	 * @param array $options
	 * @return bool
	 *
	 */
	protected function cliEval($code, array $fuel, array $options = []) {
		$at = $this;
		extract($fuel);
		$code = $this->normalizeCliEvalCode($code);
		$declare = '';
		while(preg_match('/^\s*(declare\s*\([^)]*\)\s*;)\s*/i', $code, $matches)) {
			$declare .= $matches[1] . ' ';
			$code = substr($code, strlen($matches[0]));
		}
		if(!preg_match('/^\s*namespace\s+/i', $code)) {
			$code = 'namespace ProcessWire; ' . $code;
		}
		$code = $declare . $code;
		$readOnly = !empty($options['readOnly']);
		$json = !empty($options['json']);
		$validationError = $this->engineer->validateEvalPhp($code, $readOnly, 'Read-only mode');
		if($validationError !== '') {
			if($json) {
				$this->echoCliEvalJson(false, '', null, $validationError);
			} else {
				echo "ERROR: $validationError\n";
			}
			return false;
		}

		ob_start();
		try {
			$returnValue = eval($code);
			$output = (string) ob_get_clean();
			if($json) {
				$this->echoCliEvalJson(true, $output, $returnValue);
			} else {
				echo $output;
			}
			return true;
		} catch(\Throwable $e) {
			$output = (string) ob_get_clean();
			if($json) {
				$this->echoCliEvalJson(false, $output, null, $e->getMessage(), $e->getLine());
			} else {
				echo $output;
				echo "ERROR: " . $e->getMessage() . "\n";
				echo "  Line: " . $e->getLine() . "\n";
			}
			return false;
		}
	}

	/**
	 * Echo a JSON result envelope for --at-eval/--at-stdin --json
	 *
	 * @param bool $success
	 * @param string $output Captured echo output from the evaluated code
	 * @param mixed $returnValue Value returned by the evaluated code, if any
	 * @param string $error Error message, or blank when none
	 * @param int $errorLine Error line number, or 0 when not applicable
	 *
	 */
	protected function echoCliEvalJson(bool $success, string $output, $returnValue = null, string $error = '', int $errorLine = 0): void {
		$result = [
			'ok' => $success,
			'output' => $output,
			'return' => $this->normalizeCliEvalValue($returnValue),
			'error' => null,
		];
		if($error !== '') {
			$result['error'] = [
				'message' => $error,
				'line' => $errorLine > 0 ? $errorLine : null,
			];
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;
		$json = json_encode($result, $flags);
		if($json === false) {
			$result['output'] = '';
			$result['return'] = null;
			$json = json_encode($result, $flags);
		}
		echo ($json === false ? '{"ok":false,"output":"","return":null,"error":{"message":"JSON encode failed.","line":null}}' : $json) . "\n";
	}

	/**
	 * Normalize a PHP value for JSON output from --at-eval/--at-stdin --json
	 *
	 * ProcessWire objects are represented by concise identity fields rather than
	 * attempting to serialize their full object graphs.
	 *
	 * @param mixed $value
	 * @param int $depth
	 * @return mixed
	 *
	 */
	protected function normalizeCliEvalValue($value, int $depth = 0) {
		if($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) return $value;
		if(is_resource($value)) return [ '_type' => 'resource', 'type' => get_resource_type($value) ];

		if(is_array($value)) {
			if($depth >= 4) return [ '_type' => 'array', 'count' => count($value), 'truncated' => true ];
			$out = [];
			$qty = 0;
			foreach($value as $key => $item) {
				if($qty >= 100) {
					$out['_truncated'] = true;
					break;
				}
				$out[$key] = $this->normalizeCliEvalValue($item, $depth + 1);
				$qty++;
			}
			return $out;
		}

		if($value instanceof Page) {
			return [
				'_type' => $value->className(),
				'id' => (int) $value->id,
				'name' => (string) $value->name,
				'title' => (string) $value->title,
				'path' => (string) $value->path,
				'template' => $value->template ? (string) $value->template->name : '',
				'status' => (int) $value->status,
			];
		}

		if($value instanceof WireArray) {
			$items = [];
			foreach($value as $item) {
				if(count($items) >= 100) break;
				$items[] = $this->normalizeCliEvalValue($item, $depth + 1);
			}
			return [
				'_type' => $value->className(),
				'count' => count($value),
				'items' => $items,
				'truncated' => count($value) > count($items),
			];
		}

		if($value instanceof Field || $value instanceof Template || $value instanceof Fieldgroup || $value instanceof Role || $value instanceof Permission) {
			return [
				'_type' => $value->className(),
				'id' => (int) $value->id,
				'name' => (string) $value->name,
				'label' => (string) $value->get('label|title'),
			];
		}

		if($value instanceof \JsonSerializable) {
			return $this->normalizeCliEvalValue($value->jsonSerialize(), $depth + 1);
		}

		if($value instanceof WireData) {
			if($depth >= 4) return [ '_type' => $value->className() ];
			return [
				'_type' => $value->className(),
				'data' => $this->normalizeCliEvalValue($value->getArray(), $depth + 1),
			];
		}

		if(is_object($value)) {
			if(method_exists($value, '__toString')) return (string) $value;
			return [ '_type' => get_class($value) ];
		}

		return (string) $value;
	}

	/**
	 * Parse --at-eval/--at-stdin arguments.
	 *
	 * @param array $args
	 * @param bool $expectCode
	 * @return array
	 *
	 */
	protected function parseCliEvalArgs(array $args, bool $expectCode = true): array {
		$options = [
			'code' => '',
			'readOnly' => false,
			'json' => false,
			'error' => '',
		];
		$codeParts = [];
		foreach($args as $arg) {
			$arg = (string) $arg;
			if($arg === '--readonly' || $arg === '--read-only') {
				$options['readOnly'] = true;
				continue;
			}
			if(strpos($arg, '--readonly=') === 0 || strpos($arg, '--read-only=') === 0) {
				$value = strtolower(substr($arg, strpos($arg, '=') + 1));
				$options['readOnly'] = !in_array($value, ['', '0', 'false', 'no', 'off'], true);
				continue;
			}
			if($arg === '--json') {
				$options['json'] = true;
				continue;
			}
			if(strpos($arg, '--json=') === 0) {
				$value = strtolower(substr($arg, 7));
				$options['json'] = !in_array($value, ['', '0', 'false', 'no', 'off'], true);
				continue;
			}
			if(strpos($arg, '--') === 0) {
				$options['error'] = "Unknown eval option: $arg";
				return $options;
			}
			$codeParts[] = $arg;
		}
		if($expectCode) {
			$options['code'] = trim(implode(' ', $codeParts));
			if($options['code'] === '') $options['error'] = 'No code provided.';
		}
		return $options;
	}

	/**
	 * Normalize CLI eval code from inline snippets, stdin, or whole PHP files
	 *
	 * @param string $code
	 * @return string
	 *
	 */
	protected function normalizeCliEvalCode($code) {
		$code = (string) $code;
		$code = preg_replace('/^\xEF\xBB\xBF/', '', $code);
		$code = ltrim($code);
		if(strpos($code, '<?') === 0) {
			$code = preg_replace('/^<\?(?:php)?(?:\s+|$)/i', '', $code, 1);
		}
		return $code;
	}

	/**
	 * Get CLI commands for ProcessWire 3.0.259 CliModule interface
	 *
	 * @return array
	 *
	 */
	public function getCliCommands() {
		return $this->cliHelp();
	}

	/**
	 * Get array of CLI help [ 'syntax' => 'description' ]
	 *
	 * @return array
	 *
	 */
	protected function cliHelp() {
		$help = [
			"php index.php --at-cli" => "Used by AI agents to work with the ProcessWire API",
			"php index.php --at-eval [--readonly] [--json] 'CODE'" => "Evaluate a PHP expression",
			"echo 'CODE' | php index.php --at-stdin [--readonly] [--json]" => "Evaluate PHP code from stdin",
			"php index.php --at-status [--json]" => "Print AgentTools and site status JSON (JSON is the default output)",
			"php index.php --at-test [--json]" => "Run the AgentTools self-test suite",
			"php index.php --at-selftest [--json]" => "Alias of --at-test",
		];
		foreach($this->getHelpers() as $helper) {
			$help += $helper->cliHelp();
		}
		return $help;
	}

	/**
	 * Render CLI summary of available commands
	 *
	 * @return string
	 *
	 */
	public function renderHelp(array $help = [], $label = 'Usage') {
		if(empty($help)) $help = $this->cliHelp();
		$maxCodeLength = 0;
		$description = [];
		$note = [];

		foreach($help as $code => $desc) {
			if($code === ':description') {
				$description = is_array($desc) ? $desc : [ $desc ];
				continue;
			}
			if($code === ':note') {
				$note = is_array($desc) ? $desc : [ $desc ];
				continue;
			}
			$length = strlen($code);
			if($length > $maxCodeLength) $maxCodeLength = $length;
		}

		$maxCodeLength += 3;

		$out =
			"\nProcessWire AgentTools" .
			"\n======================" .
			"\n$label:\n";

		if(count($description)) {
			foreach($description as $line) $out .= "  $line\n";
			$out .= "\n";
		}

		foreach($help as $code => $desc) {
			if($code === ':description' || $code === ':note') continue;
			while(strlen($code) < $maxCodeLength) $code .= ' ';
			$out .= "  $code $desc\n";
		}

		if(count($note)) {
			$out .= "\n";
			foreach($note as $line) $out .= "  $line\n";
		}

		return $out;
	}

	/**
	 * Execute --at-status CLI command
	 *
	 * Status output is JSON by default. The --json flag is accepted for
	 * explicitness and consistency with other agent-oriented commands.
	 *
	 * @param array $args
	 * @return bool
	 *
	 */
	protected function cliStatus(array $args): bool {
		foreach($args as $arg) {
			$arg = (string) $arg;
			if($arg === '--json') continue;
			if(strpos($arg, '--') === 0) {
				fwrite(STDERR, "ERROR: Unknown status option: $arg\n");
				return false;
			}
		}

		$json = json_encode(
			$this->getStatusData(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
		);

		if($json === false) {
			fwrite(STDERR, "ERROR: Unable to encode status JSON: " . json_last_error_msg() . "\n");
			return false;
		}

		echo $json . "\n";
		return true;
	}

	/**
	 * Execute --at-test / --at-selftest CLI command
	 *
	 * Runs the AgentTools WireTest suite. This intentionally defaults to the
	 * AgentTools test only; use ProcessWire's native `php index.php test NAME`
	 * command for general site/core tests.
	 *
	 * @param array $args
	 * @return bool
	 *
	 */
	protected function cliTest(array $args): bool {
		$json = false;
		foreach($args as $arg) {
			$arg = (string) $arg;
			if($arg === '--json') {
				$json = true;
				continue;
			}
			if($arg === '--help' || $arg === '-h') {
				echo "Usage: php index.php --at-test [--json]\n";
				echo "       php index.php --at-selftest [--json]\n";
				return true;
			}
			fwrite(STDERR, "ERROR: Unknown test option: $arg\n");
			return false;
		}

		$tests = $this->wire()->modules->get('WireTests');
		if(!$tests || !$tests instanceof Wire) {
			fwrite(STDERR, "ERROR: WireTests module is not available on this ProcessWire installation.\n");
			return false;
		}

		$testArgs = [ 'AgentTools' ];
		if($json) $testArgs[] = '--json';

		$tests->executeCli($testArgs);
		return $this->getWireTestsFailedCount($tests) === 0;
	}

	/**
	 * Get failed test count from WireTests after cli execution
	 *
	 * @param Wire $tests
	 * @return int
	 *
	 */
	protected function getWireTestsFailedCount(Wire $tests): int {
		try {
			$property = new \ReflectionProperty($tests, 'failed');
			$property->setAccessible(true);
			return (int) $property->getValue($tests);
		} catch(\Throwable $e) {
			return 0;
		}
	}

	/**
	 * Get AgentTools and site status data
	 *
	 * This is shared by the CLI --at-status command and the MCP at_status tool.
	 * It intentionally excludes API keys and other secret configuration values.
	 *
	 * @return array
	 *
	 */
	public function getStatusData(): array {
		$config = $this->wire()->config;
		$moduleInfo = self::getModuleInfo();
		$filesPath = $this->getFilesPath();
		$migrationsPath = $this->getFilesPath('migrations');
		$jobsPath = $filesPath . 'jobs/';

		$sitemap = $this->sitemap;
		$migrations = $this->migrations;
		$migrationFiles = is_dir($migrationsPath) ? $migrations->getFiles($migrationsPath) : [];
		$appliedMigrations = 0;
		foreach($migrationFiles as $file) {
			if($migrations->isApplied($file)) $appliedMigrations++;
		}

		$jobs = $this->jobs;
		$jobCounts = [];
		foreach([ AgentToolsJobs::statusPending, AgentToolsJobs::statusRunning, AgentToolsJobs::statusDone, AgentToolsJobs::statusFailed ] as $status) {
			$jobCounts[$status] = count($jobs->getJobs($status));
		}
		$cronLastRun = $jobs->getCronLastRun();

		$agents = $this->getAgents();
		$primaryAgent = $agents->first();
		$primaryAgentData = null;
		if($primaryAgent) {
			$primaryAgentData = [
				'id' => (string) $primaryAgent->id,
				'label' => (string) ($primaryAgent->label ?: $primaryAgent->model),
				'model' => (string) $primaryAgent->model,
				'provider' => (string) $primaryAgent->provider,
				'agentName' => (string) $primaryAgent->agentName,
			];
		}

		return [
			'generated' => date('c'),
			'php' => [
				'version' => PHP_VERSION,
			],
			'agentTools' => [
				'version' => (string) ($moduleInfo['version'] ?? ''),
				'apiVariable' => '$' . self::name,
				'filesPath' => $filesPath,
				'filesPathWritable' => is_writable($filesPath),
				'htaccessFile' => $this->getStatusFileData($filesPath . '.htaccess'),
			],
			'processWire' => [
				'version' => (string) $config->version,
				'apiVariable' => '$wire',
				'rootUrl' => $config->urls->root,
				'httpRootUrl' => $config->urls->httpRoot,
				'rootPath' => $config->paths->root,
			],
			'sitemaps' => [
				'pages' => $this->getStatusFileData($sitemap->getOutputFile()),
				'schema' => $this->getStatusFileData($sitemap->getSchemaOutputFile()),
			],
			'migrations' => [
				'path' => $migrationsPath,
				'count' => count($migrationFiles),
				'applied' => $appliedMigrations,
				'pending' => count($migrationFiles) - $appliedMigrations,
			],
			'jobs' => [
				'path' => $jobsPath,
				'counts' => $jobCounts,
				'cron' => [
					'healthy' => $jobs->isCronHealthy(),
					'lastRun' => $cronLastRun ? date('c', $cronLastRun) : null,
					'lastRunTimestamp' => $cronLastRun,
				],
			],
			'tasks' => [
				'scheduled' => count($this->getScheduledTasks()),
			],
			'agents' => [
				'count' => count($agents),
				'primary' => $primaryAgentData,
			],
			'cliCommands' => array_values(array_filter(array_keys($this->cliHelp()), function($command) {
				return strpos((string) $command, ':') !== 0;
			})),
			'notes' => [
				'Status output is JSON by default; --json is accepted for explicitness.',
				'The .htaccess file in site/assets/at/ applies to Apache. Verify equivalent protection on nginx or other web servers.',
			],
		];
	}

	/**
	 * Get file status data for --at-status and MCP at_status
	 *
	 * @param string $file
	 * @return array
	 *
	 */
	protected function getStatusFileData(string $file): array {
		$exists = is_file($file);
		return [
			'file' => $file,
			'exists' => $exists,
			'readable' => is_readable($file),
			'writable' => is_writable($file),
			'size' => $exists ? filesize($file) : null,
			'modified' => $exists ? date('c', filemtime($file)) : null,
		];
	}

	/**
	 * Get all AgentTools helpers
	 *
	 * @return AgentToolsHelper[] Indexed by helper name
	 *
	 */
	protected function getHelpers() {
		foreach($this->helpers as $name => $helper) {
			if($helper === null) $this->getHelper($name);
		}
		return $this->helpers;
	}

	/**
	 * Get helper by name
	 *
	 * @param string $name
	 * @return AgentToolsHelper|null
	 *
	 */
	protected function getHelper($name) {
		if(isset($this->helpers[$name])) return $this->helpers[$name];
		if(!array_key_exists($name, $this->helpers)) return null;
		$class = 'AgentTools' . ucfirst($name);
		$file = __DIR__ . "/$class.php";
		include_once(__DIR__ . '/AgentToolsHelper.php');
		include_once($file);
		$class = wireClassName($class, true);
		$this->helpers[$name] = new $class($this);
		return $this->helpers[$name];
	}

	/**
	 * Allow helpers to be called as methods, e.g. $at->sitemap()->generate()
	 *
	 * @param string $method
	 * @param array $arguments
	 * @return AgentToolsHelper|mixed|null
	 *
	 */
	public function ___callUnknown($method, $arguments) {
		$helper = $this->getHelper($method);
		if($helper) return $helper;
		return parent::___callUnknown($method, $arguments);
	}

	public function set($key, $value) {
		if($key === 'engineer_user') {
			$value = $this->wire()->sanitizer->pageName($value);
			if(ctype_digit($value)) $value = (int) $value;
		}
		return parent::set($key, $value);
	}

	/**
	 * Convert markdown to AgentTools-friendly HTML
	 *
	 * @param string $markdownText
	 * @param array $options
	 *  - `safe` (bool): Use markdownSafe() rather than markdown() when available (default=true)
	 *  - `email` (bool): Add simple email-friendly table attributes and inline styles (default=false)
	 * @return string
	 *
	 */
	public function markdownToHtml($markdownText, array $options = []) {
		$defaults = [ 'safe' => true, 'email' => false ];
		$options = array_merge($defaults, $options);
		$text = (string) $markdownText;

		/** @var TextformatterMarkdownExtra|null $markdown */
		$markdown = $this->wire()->modules->get('TextformatterMarkdownExtra');
		if(!$markdown) return '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';

		$html = $markdown->markdown($text);

		if($options['safe']) $html = $this->wire()->sanitizer->purify($html);

		return "<div class='agent-tools-response'>" . $this->formatMarkdownHtml($html, $options) . "</div>";
	}

	/**
	 * Apply shared AgentTools HTML tweaks to markdown output
	 *
	 * @param string $html
	 * @param array $options
	 * @return string
	 *
	 */
	protected function formatMarkdownHtml($html, array $options = []) {

		if(!empty($options['email'])) {
			$border = 'border: 1px solid #dddddd;';
			$tableAttr = "border='1' cellspacing='0' cellpadding='10'";
			$findReplace = [
				'<table>' => "<table $tableAttr style='border-collapse:collapse;$border'>",
				'<th>' => "<th style='$border background-color: #f2f2f2; text-align: left;'>",
				'<td>' => "<td style='$border'>",
			];
		} else {
			$findReplace = [
				'<table>' => '<table class="uk-table uk-table-divider uk-table-small">',
			];
		}

		return str_replace(array_keys($findReplace), array_values($findReplace), $html);
	}

	/**
	 * Get main files path for AgentTools assets
	 *
	 * @param string $subdir Optional subdirectory to get/create
	 * @return string
	 *
	 */
	public function getFilesPath($subdir = '') {
		$path = $this->wire()->config->paths->assets . self::name . '/';
		if(!is_dir($path)) $this->wire()->files->mkdir($path);
		if($subdir) {
			$subdir = str_replace('\\', '/', (string) $subdir);
			$isAbsolute = isset($subdir[0]) && $subdir[0] === '/';
			$isWindowsAbsolute = preg_match('/^[a-zA-Z]:\//', $subdir);
			$hasTraversal = preg_match('!(^|/)\.\.?(/|$)!', $subdir);
			if(strpos($subdir, "\0") !== false || $isAbsolute || $isWindowsAbsolute || $hasTraversal) {
				throw new WireException("Invalid AgentTools files subdirectory");
			}
			$subdir = trim($subdir, '/');
			$path .= $subdir . '/';
			if(!is_dir($path)) $this->wire()->files->mkdir($path);
		}
		$this->checkHtaccessFile($path);
		return $path;
	}

	/**
	 * Check that .htaccess file exists in AgentTools assets path
	 *
	 * @param string $path
	 * @throws WireException
	 *
	 */
	protected function checkHtaccessFile($path) {
		$file = $path . '.htaccess';
		if(is_file($file)) return;
		$this->wire()->files->filePutContents($file,
			"<IfModule mod_authz_core.c>\n" .
			"  Require all denied\n" .
			"</IfModule>\n" .
			"<IfModule !mod_authz_core.c>\n" .
			"  Order allow,deny\n" .
			"  Deny from all\n" .
			"</IfModule>\n"
		);
	}

	/**
	 * Report a questionable prompt submitted by the current user
	 *
	 * Appends an entry to the engineer_suspicious_log module config, saves it,
	 * and emails the configured engineer_suspicious_email address (if set).
	 * The current user will be blocked from Engineer requests for 1 hour.
	 *
	 * @param string $prompt The suspicious prompt text
	 *
	 */
	public function reportQuestionablePrompt(string $prompt): void {
		$user = $this->wire()->user;
		$prompt = str_replace(["\n", "|"], ' ', $prompt);
		if(mb_strlen($prompt) > 200) $prompt = mb_substr($prompt, 0, 200) . '…';
		$timestamp = date('Y-m-d\TH:i:s');
		$line = "{$user->name} | $timestamp | $prompt";
		$log = trim((string) $this->get('engineer_suspicious_log'));
		$log = $log ? "$log\n$line" : $line;
		$this->set('engineer_suspicious_log', $log);
		$this->wire()->modules->saveConfig($this, 'engineer_suspicious_log', $log);
		$email = trim((string) $this->get('engineer_suspicious_email'));
		if($email) {
			$config = $this->wire()->config;
			$mail = wireMail();
			$mail->to($email);
			$mail->subject("Suspicious AI prompt on {$config->httpHost}");
			$mail->body(
				"User '{$user->name}' submitted a suspicious prompt:\n\n" .
				"$prompt\n\n" .
				"Time: $timestamp\n" .
				"Site: " . rtrim($config->urls->httpRoot, '/')
			);
			$mail->send();
		}
	}

	/**
	 * Is the given user currently flagged as suspicious?
	 *
	 * Returns true if the user has an entry in engineer_suspicious_log dated
	 * within the last hour. Expired entries are ignored but not removed.
	 *
	 * @param User|null $user User to check, or null for the current user
	 * @return bool
	 *
	 */
	public function isUserSuspicious(?User $user = null): bool {
		if($user === null) $user = $this->wire()->user;
		$log = trim((string) $this->get('engineer_suspicious_log'));
		if(!$log) return false;
		$oneHourAgo = time() - 3600;
		foreach(explode("\n", $log) as $line) {
			$parts = explode(' | ', $line, 3);
			if(count($parts) < 2) continue;
			if(trim($parts[0]) !== $user->name) continue;
			$ts = strtotime(trim($parts[1]));
			if($ts && $ts > $oneHourAgo) return true;
		}
		return false;
	}

	/**
	 * Get primary agent
	 *
	 * @return AgentToolsAgent|false
	 *
	 */
	public function getPrimaryAgent() {
		return $this->getAgents()->first();
	}

	/**
	 * Get all defined agents
	 *
	 * First agent is the primary
	 *
	 * @return AgentToolsAgents
	 *
	 */
	public function getAgents() {
		if($this->agents) return $this->agents;

		$lines = [];
		$removeDuplicates = false;
		if($this->engineer_model || $this->engineer_api_key) {
			// convert old settings to new setting
			$models = explode(',', $this->engineer_model);
			foreach($models as $model) {
				$a = [
					$model,
					$this->engineer_api_key,
					$this->engineer_endpoint,
					$this->engineer_label,
					$this->engineer_description,
					'',
					$this->engineer_agent_name,
				];
				$lines[] = trim(implode(' | ', $a), '| ');
			}
			$removeDuplicates = true;
		}
		// we are indexing by $line so we can auto-remove duplicate entries
		// which is likely when converting legacy settings to new settings
		foreach(explode("\n", trim($this->engineer_additional_models, '| ')) as $line) {
			if(strlen($line)) $lines[] = $line;
		}

		$this->agents = new AgentToolsAgents(array_values($lines));
		if($removeDuplicates) $this->agents->removeDuplicates();
		if($this->agents->ensureIds()) {
			$data = $this->wire()->modules->getConfig('AgentTools');
			$data['engineer_additional_models'] = $this->agents->getString();
			$this->wire()->modules->saveConfig($this, $data);
		}

		return $this->agents;
	}

	/**
	 * Get all built-in tasks
	 *
	 * @return AgentToolsTasks
	 *
	 */
	public function getTasks(): AgentToolsTasks {
		if($this->tasks) return $this->tasks;
		$this->tasks = new AgentToolsTasks($this);
		$this->wire($this->tasks);
		return $this->tasks;
	}

	/**
	 * Get scheduled tasks service
	 *
	 * @return AgentToolsScheduledTasks
	 *
	 */
	public function getScheduledTasks(): AgentToolsScheduledTasks {
		if($this->scheduledTasks) return $this->scheduledTasks;
		$this->scheduledTasks = new AgentToolsScheduledTasks($this);
		$this->wire($this->scheduledTasks);
		return $this->scheduledTasks;
	}

	/**
	 * Get traces service
	 *
	 * @return AgentToolsTraces
	 *
	 */
	public function getTraces(): AgentToolsTraces {
		if($this->traces) return $this->traces;
		$this->traces = new AgentToolsTraces($this);
		$this->wire($this->traces);
		return $this->traces;
	}

	/**
	 * Module config
	 *
	 * @param InputfieldWrapper $inputfields
	 *
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {

		$this->jobs()->initJobDirs();

		if(!$this->jobs()->isCronHealthy()) {
			$sanitizer = $this->wire()->sanitizer;
			$phpBin = dirname(PHP_BINARY) . '/php';
			if(!is_executable($phpBin)) $phpBin = 'php';
			$php = escapeshellarg($phpBin);
			$path = escapeshellarg($this->wire()->config->paths->root);
			$f = $inputfields->InputfieldMarkup;
			$f->attr('name', '_cron_job_warning');
			$f->label = $this->_('No AgentTools cron job detected');
			$f->description = $this->_('While optional, we recommend adding the following to your crontab:');
			$f->value =
				'<pre class="uk-margin-small">' .
					$sanitizer->entities("* * * * * cd $path && $php index.php --at-cron") .
				'</pre>' .
				'<p class="description uk-margin-small">' .
					$this->_('Optionally append the following to the above to log the cron jobs:') .
				'</p>' .
				'<pre class="uk-margin-small">' .
					$sanitizer->entities(">> site/assets/logs/at-cron.log 2>&1") .
				'</pre>';
			$f->notes = $this->_('See the README.md section on "Background jobs with cron" for more details.');
			$f->themeOffset = 1;
			$f->icon = 'warning';
			$inputfields->add($f);
		}

		foreach($this->getHelpers() as $helper) {
			$helper->getConfigInputfields($inputfields);
		}

		$f = $inputfields->InputfieldToggle;
		$f->attr('name', '_uninstall_files');
		$f->label = $this->_('Also delete AgentTools files in /site/assets/at/');
		$f->description = $this->_('If you intend to re-install this module at some point, you may want to leave the files in place.');
		$f->showIf = 'uninstall=AgentTools';
		$f->val(0);
		$inputfields->add($f);

	}

	/**
	 * Get property
	 *
	 * @param string $key
	 * @return mixed
	 *
	 */
	public function get($key) {
		$helper = $this->getHelper($key);
		if($helper) return $helper;
		return parent::get($key);
	}

	/**
	 * Upgrade module
	 *
	 * @param int|string $fromVersion
	 * @param int|string $toVersion
	 *
	 */
	public function ___upgrade($fromVersion, $toVersion) {
		foreach($this->getHelpers() as $helper) {
			$helper->upgrade($fromVersion, $toVersion);
		}
	}

	/**
	 * Install module
	 *
	 */
	public function install() {
		$this->getFilesPath(); // creates site/assets/at/
		$this->getFilesPath('migrations'); // creates site/assets/at/migrations/
	}

	/**
	 * Uninstall module
	 *
	 */
	public function uninstall() {
		if($this->wire()->input->post('_uninstall_files')) {
			$path = $this->getFilesPath();
			$this->wire()->files->rmdir($path, true);
		}
	}

	/**
	 * MIGRATIONS METHODS (deprecated/moved to AgentToolsMigrations)
	 *
	 */

	/**
	 * Get the migration name from its filename
	 *
	 * @param string $file Full path or basename of migration file
	 * @return string e.g. "add-blog-post-template"
	 * @deprecated use $at->migrations->getName() instead
	 *
	 */
	public function getMigrationName($file) {
		return $this->migrations->getName($file);
	}

	/**
	 * Get applied migrations registry from module config
	 *
	 * @return array Array of applied migration basenames
	 * @deprecated use $at->migrations->getApplied() instead
	 *
	 */
	public function getAppliedMigrations() {
		return $this->migrations->getApplied();
	}

	/**
	 * Is the given migration already applied?
	 *
	 * @param string $file Full path or basename of migration file
	 *
	 * @return bool
	 * @deprecated use $at->migrations->isApplied() instead
	 *
	 */
	public function isMigrationApplied($file) {
		return $this->migrations->isApplied($file);
	}

	/**
	 * Record a migration as applied in the registry
	 *
	 * @param string $file Full path or basename of migration file
	 *
	 * @deprecated use $at->migrations->addApplied() instead
	 *
	 */
	public function addAppliedMigration($file) {
		$this->migrations->addApplied($file);
	}
}

include_once(__DIR__ . '/AgentToolsAgent.php');
include_once(__DIR__ . '/AgentToolsAgents.php');
include_once(__DIR__ . '/AgentToolsRequest.php');
include_once(__DIR__ . '/AgentToolsEngineerSession.php');
include_once(__DIR__ . '/AgentToolsSiteBuilderSession.php');
include_once(__DIR__ . '/AgentToolsTask.php');
include_once(__DIR__ . '/AgentToolsTasks.php');
include_once(__DIR__ . '/AgentToolsScheduledTask.php');
include_once(__DIR__ . '/AgentToolsScheduledTasks.php');
include_once(__DIR__ . '/AgentToolsTrace.php');
include_once(__DIR__ . '/AgentToolsTraces.php');
