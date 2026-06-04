<?php namespace ProcessWire;

/**
 * AgentTools trace service.
 *
 * Creates, updates, saves, and summarizes AgentTools run traces.
 *
 */
class AgentToolsTraces extends Wire {

	/**
	 * @var AgentTools
	 *
	 */
	protected $at;

	/**
	 * Microtime start values indexed by trace ID.
	 *
	 * @var array<string,float>
	 *
	 */
	protected $startTimes = [];

	/**
	 * Construct the trace service.
	 *
	 * @param AgentTools $at
	 *
	 */
	public function __construct(AgentTools $at) {
		parent::__construct();
		$this->at = $at;
	}

	/**
	 * Create a new trace and begin timing it.
	 *
	 * @param array<string,mixed> $data Initial trace data.
	 * @return AgentToolsTrace
	 *
	 */
	public function newTrace(array $data = []): AgentToolsTrace {
		$trace = new AgentToolsTrace(array_merge([
			'id' => $this->newTraceId(),
			'started' => time(),
		], $data));
		$this->wire($trace);
		$this->startTimes[$trace->id] = microtime(true);
		return $trace;
	}

	/**
	 * Record a completed tool call in a trace.
	 *
	 * @param AgentToolsTrace $trace
	 * @param string $name Tool name.
	 * @param array<string,mixed> $input Tool input arguments.
	 * @param string $output Tool output text.
	 * @param float $started Tool start time from microtime(true).
	 * @param \Throwable|null $error Optional tool exception.
	 * @return void
	 *
	 */
	public function addToolCall(AgentToolsTrace $trace, string $name, array $input, string $output, float $started, ?\Throwable $error = null): void {
		$durationMs = (int) round((microtime(true) - $started) * 1000);
		$event = [
			'name' => $name,
			'input' => $this->summarizeToolInput($name, $input),
			'outputBytes' => strlen($output),
			'durationMs' => $durationMs,
			'error' => $error ? $error->getMessage() : '',
		];
		$calls = $trace->toolCalls;
		$calls[] = $event;
		$trace->toolCalls = $calls;
		$trace->toolRounds = count($calls);
		$this->indexToolCall($trace, $name, $input, $output);
	}

	/**
	 * Finish a trace and update final status/duration metadata.
	 *
	 * @param AgentToolsTrace $trace
	 * @param array<string,mixed> $result Engineer result array.
	 * @return AgentToolsTrace
	 *
	 */
	public function finish(AgentToolsTrace $trace, array $result = []): AgentToolsTrace {
		$trace->finished = time();
		$started = $this->startTimes[$trace->id] ?? (float) $trace->started;
		$trace->durationMs = max(0, (int) round((microtime(true) - $started) * 1000));
		unset($this->startTimes[$trace->id]);
		$error = (string) ($result['error'] ?? '');
		$response = (string) ($result['response'] ?? '');
		$trace->status = $error === '' ? 'success' : 'error';
		$trace->error = $error;
		$trace->responseLength = strlen($response);
		return $trace;
	}

	/**
	 * Save a trace to a JSON file.
	 *
	 * @param AgentToolsTrace $trace
	 * @return string Absolute path to saved trace file.
	 * @throws WireException
	 *
	 */
	public function save(AgentToolsTrace $trace): string {
		$this->prune();
		$file = $this->getPath() . $trace->id . '.json';
		$trace->traceFile = $this->getRelativePath($file);
		$json = json_encode($trace->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if($json === false) throw new WireException('Unable to encode AgentTools trace JSON');
		$this->wire()->files->filePutContents($file, $json . "\n", LOCK_EX);
		return $file;
	}

	/**
	 * Build compact trace data for admin notice output.
	 *
	 * @param AgentToolsTrace $trace
	 * @return array<string,array<string,mixed>>
	 *
	 */
	public function getNoticeData(AgentToolsTrace $trace): array {
		$tools = [];
		foreach($trace->toolCalls as $event) {
			$label = $event['name'];
			$input = $event['input'] ?? '';
			if($input) $label .= ': ' . $input;
			$label .= ' (' . $this->formatDurationMs((int) ($event['durationMs'] ?? 0)) . ')';
			if(!empty($event['error'])) $label .= ' ERROR';
			$tools[] = $label;
		}
		return [
			'Agent run trace' => [
				'agent' => $trace->agentLabel ?: $trace->model,
				'provider' => $trace->provider,
				'status' => $trace->status,
				'duration' => $this->formatDurationMs($trace->durationMs),
				'tools' => $tools,
				'apiDocs' => $trace->apiDocs,
				'migrations' => $trace->migrations,
				'traceFile' => $trace->traceFile,
			],
		];
	}

	/**
	 * Summarize tool input for trace display/storage.
	 *
	 * @param string $name Tool name.
	 * @param array<string,mixed> $input Tool input arguments.
	 * @return string
	 *
	 */
	protected function summarizeToolInput(string $name, array $input): string {
		if($name === 'api_docs') {
			$action = (string) ($input['action'] ?? 'list');
			$doc = (string) ($input['name'] ?? '');
			return $doc ? "$action $doc" : $action;
		}
		if($name === 'site_info') {
			$type = (string) ($input['type'] ?? '');
			return $type . (!empty($input['refresh']) ? ' refresh' : '');
		}
		if($name === 'read_file') {
			return $this->rootRelativePath((string) ($input['path'] ?? ''));
		}
		if($name === 'save_migration') {
			return (string) ($input['description'] ?? 'migration');
		}
		if($name === 'eval_php') {
			$code = trim((string) ($input['code'] ?? ''));
			return $this->truncateText($code, 160);
		}
		return '';
	}

	/**
	 * Truncate trace text without HTML/tag filtering.
	 *
	 * @param string $text
	 * @param int $maxLength
	 * @return string
	 *
	 */
	protected function truncateText(string $text, int $maxLength): string {
		$text = preg_replace('/\s+/', ' ', trim($text));
		if(strlen($text) <= $maxLength) return $text;
		return rtrim(substr($text, 0, $maxLength - 1)) . '…';
	}

	/**
	 * Add high-level indexes for selected tool calls.
	 *
	 * @param AgentToolsTrace $trace
	 * @param string $name Tool name.
	 * @param array<string,mixed> $input Tool input arguments.
	 * @param string $output Tool output text.
	 * @return void
	 *
	 */
	protected function indexToolCall(AgentToolsTrace $trace, string $name, array $input, string $output): void {
		if($name === 'api_docs') {
			$docs = $trace->apiDocs;
			$doc = (string) ($input['name'] ?? '');
			$value = $doc ?: (string) ($input['action'] ?? 'list');
			if($value && !in_array($value, $docs, true)) $docs[] = $value;
			$trace->apiDocs = $docs;
		} else if($name === 'read_file') {
			$files = $trace->filesRead;
			$file = $this->rootRelativePath((string) ($input['path'] ?? ''));
			if($file && !in_array($file, $files, true)) $files[] = $file;
			$trace->filesRead = $files;
		} else if($name === 'site_info') {
			$items = $trace->siteInfo;
			$type = (string) ($input['type'] ?? '');
			if($type) {
				$item = $type . (!empty($input['refresh']) ? ':refresh' : '');
				if(!in_array($item, $items, true)) $items[] = $item;
			}
			$trace->siteInfo = $items;
		} else if($name === 'save_migration') {
			$migrations = $trace->migrations;
			if(preg_match('/Migration saved:\s*(.+)$/m', $output, $m)) {
				$migration = trim($m[1]);
			} else {
				$migration = (string) ($input['description'] ?? 'migration');
			}
			if($migration && !in_array($migration, $migrations, true)) $migrations[] = $migration;
			$trace->migrations = $migrations;
		}
	}

	/**
	 * Convert an absolute or site-root path to a root-relative path.
	 *
	 * @param string $path
	 * @return string
	 *
	 */
	protected function rootRelativePath(string $path): string {
		$path = trim($path);
		$root = $this->wire()->config->paths->root;
		if($path === '') return '';
		if(strpos($path, $root) === 0) return substr($path, strlen($root));
		return ltrim($path, '/');
	}

	/**
	 * Convert an absolute file path to a root-relative path when possible.
	 *
	 * @param string $file
	 * @return string
	 *
	 */
	protected function getRelativePath(string $file): string {
		$root = $this->wire()->config->paths->root;
		if(strpos($file, $root) === 0) return substr($file, strlen($root));
		return $file;
	}

	/**
	 * Format a duration in milliseconds for display.
	 *
	 * @param int $ms
	 * @return string
	 *
	 */
	protected function formatDurationMs(int $ms): string {
		if($ms < 1000) return $ms . 'ms';
		return round($ms / 1000, 2) . 's';
	}

	/**
	 * Create a new trace ID.
	 *
	 * @return string
	 * @throws \Exception
	 *
	 */
	protected function newTraceId(): string {
		return date('YmdHis') . '-' . bin2hex(random_bytes(4));
	}

	/**
	 * Get/create the trace storage path.
	 *
	 * @return string
	 *
	 */
	protected function getPath(): string {
		return $this->at->getFilesPath('traces');
	}

	/**
	 * Delete old trace files according to module retention settings.
	 *
	 * @return void
	 *
	 */
	protected function prune(): void {
		$days = (int) $this->at->get('engineer_trace_keep_days');
		if($days < 1) return;
		$cutoff = time() - ($days * 86400);
		foreach(glob($this->getPath() . '*.json') ?: [] as $file) {
			if(is_file($file) && filemtime($file) < $cutoff) $this->wire()->files->unlink($file);
		}
	}
}
