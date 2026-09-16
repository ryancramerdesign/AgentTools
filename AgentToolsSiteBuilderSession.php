<?php namespace ProcessWire;

/**
 * File-backed state, manifest, and progress log for one Site Builder run.
 *
 */
class AgentToolsSiteBuilderSession extends AgentToolsEngineerSession {

	/**
	 * Construct a Site Builder session store.
	 *
	 * @param AgentTools $at
	 * @param string $id Existing build ID, or blank to create one
	 * @throws WireException
	 *
	 */
	public function __construct(AgentTools $at, string $id = '') {
		parent::__construct($at, $id);
		$this->path = $this->at->getFilesPath('builds') . $this->id . '/';
	}

	/**
	 * Get this build's storage path.
	 *
	 * @return string
	 *
	 */
	public function getPath(): string {
		return $this->path;
	}

	/**
	 * Load the build manifest.
	 *
	 * @return array<string,mixed>
	 *
	 */
	public function loadManifest(): array {
		return $this->loadJsonFile('manifest.json');
	}

	/**
	 * Atomically save the build manifest.
	 *
	 * @param array<string,mixed> $manifest
	 * @throws WireException
	 *
	 */
	public function saveManifest(array $manifest): void {
		$this->saveJsonFile('manifest.json', $manifest);
	}

	/**
	 * Save one invalid planning attempt for later review.
	 *
	 * @param int $attempt
	 * @param array<string,mixed> $plan Decoded plan, or an empty array when decoding failed
	 * @param string[] $errors
	 * @param string $response Raw planner response
	 * @return string Saved basename
	 *
	 */
	public function savePlanAttempt(int $attempt, array $plan, array $errors, string $response): string {
		$basename = 'plan-attempt-' . max(1, $attempt) . '.json';
		$this->saveJsonFile($basename, [
			'attempt' => max(1, $attempt),
			'created' => date('c'),
			'errors' => array_values($errors),
			'plan' => $plan,
			'response' => $response,
		]);
		return $basename;
	}

	/**
	 * Append one structured progress entry.
	 *
	 * @param array<string,mixed> $entry
	 * @throws WireException
	 *
	 */
	public function appendLog(array $entry): void {
		$this->ensurePath();
		if($this->lockToken !== '' && !$this->ownsLock()) {
			throw new WireException('AgentTools Site Builder session lock ownership was lost.');
		}
		if($this->lockToken !== '' && !$this->refreshLock()) {
			throw new WireException('Unable to refresh AgentTools Site Builder session lock.');
		}
		$entry = array_merge([
			'time' => date('c'),
			'timestamp' => time(),
		], $entry);
		$json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if($json === false) throw new WireException('Unable to encode AgentTools Site Builder log entry.');
		$result = $this->wire()->files->filePutContents($this->path . 'log.jsonl', $json . "\n", FILE_APPEND | LOCK_EX);
		if($result === false) throw new WireException('Unable to append AgentTools Site Builder progress log.');
	}

	/**
	 * Read progress log entries.
	 *
	 * @param int $afterTimestamp Return entries at or after this timestamp
	 * @return array<int,array<string,mixed>>
	 *
	 */
	public function loadLog(int $afterTimestamp = 0): array {
		$file = $this->path . 'log.jsonl';
		if(!is_file($file) || !is_readable($file)) return [];
		$entries = [];
		foreach(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
			$entry = json_decode($line, true);
			if(!is_array($entry)) continue;
			if($afterTimestamp && (int) ($entry['timestamp'] ?? 0) < $afterTimestamp) continue;
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * Atomically save a JSON file within the build directory.
	 *
	 * @param string $basename
	 * @param array<string,mixed> $data
	 * @throws WireException
	 *
	 */
	protected function saveJsonFile(string $basename, array $data): void {
		$this->ensurePath();
		if($this->lockToken !== '' && !$this->ownsLock()) {
			throw new WireException('AgentTools Site Builder session lock ownership was lost.');
		}
		if($this->lockToken !== '' && !$this->refreshLock()) {
			throw new WireException('Unable to refresh AgentTools Site Builder session lock.');
		}
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if($json === false) throw new WireException('Unable to encode AgentTools Site Builder JSON: ' . json_last_error_msg());
		$file = $this->path . $basename;
		$tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
		$bytes = $this->wire()->files->filePutContents($tmp, $json . "\n", LOCK_EX);
		if($bytes === false || !@rename($tmp, $file)) {
			@unlink($tmp);
			throw new WireException("Unable to save AgentTools Site Builder file: $basename");
		}
	}

	/**
	 * Load a JSON file within the build directory.
	 *
	 * @param string $basename
	 * @return array<string,mixed>
	 *
	 */
	protected function loadJsonFile(string $basename): array {
		$file = $this->path . $basename;
		if(!is_file($file) || !is_readable($file)) return [];
		$data = json_decode((string) file_get_contents($file), true);
		return is_array($data) ? $data : [];
	}
}
