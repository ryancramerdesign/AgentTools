<?php namespace ProcessWire;

/**
 * File-backed state and locking for a resumable Engineer request.
 *
 * API credentials are runtime-only and must never be included in state passed
 * to save().
 *
 */
class AgentToolsEngineerSession extends Wire {

	/** @var AgentTools */
	protected $at;

	/** @var string */
	protected $id = '';

	/** @var string */
	protected $path = '';

	/** @var string */
	protected $lockToken = '';

	/**
	 * Construct a session store.
	 *
	 * @param AgentTools $at
	 * @param string $id Existing session ID, or blank to create one
	 * @throws WireException
	 *
	 */
	public function __construct(AgentTools $at, string $id = '') {
		parent::__construct();
		$this->at = $at;
		$this->id = $id === '' ? $this->newId() : $this->sanitizeId($id);
		if($this->id === '') throw new WireException('Invalid AgentTools Engineer session ID.');
		$this->path = $this->at->getFilesPath('engineer-sessions') . $this->id . '/';
	}

	/**
	 * Get the session ID.
	 *
	 * @return string
	 *
	 */
	public function getId(): string {
		return $this->id;
	}

	/**
	 * Does this session have saved state?
	 *
	 * @return bool
	 *
	 */
	public function exists(): bool {
		return is_file($this->getStateFile());
	}

	/**
	 * Load session state.
	 *
	 * @return array<string,mixed>
	 *
	 */
	public function load(): array {
		$file = $this->getStateFile();
		if(!is_file($file) || !is_readable($file)) return [];
		for($n = 0; $n < 3; $n++) {
			$json = file_get_contents($file);
			$state = json_decode((string) $json, true);
			if(is_array($state)) return $state;
			if($n < 2) usleep(50000);
		}
		return [];
	}

	/**
	 * Atomically save session state.
	 *
	 * @param array<string,mixed> $state
	 * @throws WireException
	 *
	 */
	public function save(array $state): void {
		$this->ensurePath();
		if($this->lockToken !== '' && !$this->ownsLock()) {
			throw new WireException('AgentTools Engineer session lock ownership was lost.');
		}
		if($this->lockToken !== '' && !$this->refreshLock()) {
			throw new WireException('Unable to refresh AgentTools Engineer session lock.');
		}
		$json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if($json === false) throw new WireException('Unable to encode AgentTools Engineer session JSON: ' . json_last_error_msg());
		$file = $this->getStateFile();
		$tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
		$bytes = $this->wire()->files->filePutContents($tmp, $json . "\n", LOCK_EX);
		if($bytes === false || !@rename($tmp, $file)) {
			@unlink($tmp);
			throw new WireException("Unable to save AgentTools Engineer session: $this->id");
		}
	}

	/**
	 * Acquire this session's non-blocking lock.
	 *
	 * A stale lock is reclaimed after the caller-supplied maximum age. Lock
	 * ownership is token-based so an older request cannot release a newer lock.
	 *
	 * @param int $staleAfter Maximum lock age in seconds
	 * @return bool
	 *
	 */
	public function lock(int $staleAfter): bool {
		$this->ensurePath();
		if($this->lockToken !== '') return $this->ownsLock();
		if($staleAfter < 60) $staleAfter = 60;

		for($attempt = 0; $attempt < 2; $attempt++) {
			$token = getmypid() . '-' . bin2hex(random_bytes(8));
			$fp = @fopen($this->getLockFile(), 'x');
			if($fp !== false) {
				fwrite($fp, $token . "\n" . time() . "\n");
				fclose($fp);
				$this->lockToken = $token;
				return true;
			}

			if(!$this->reclaimStaleLock($staleAfter)) return false;
		}

		return false;
	}

	/**
	 * Release a lock owned by this process/request.
	 *
	 */
	public function unlock(): void {
		if($this->lockToken === '') return;
		if($this->ownsLock()) @unlink($this->getLockFile());
		$this->lockToken = '';
	}

	/**
	 * Refresh the modified time of a lock owned by this request.
	 *
	 * @return bool
	 *
	 */
	public function refreshLock(): bool {
		if(!$this->ownsLock()) return false;
		return @touch($this->getLockFile());
	}

	/**
	 * Delete this session's files and directory.
	 *
	 * @return bool
	 *
	 */
	public function delete(int $staleAfter = 0): bool {
		if($this->lockToken !== '') return false;
		$lockFile = $this->getLockFile();
		if(is_file($lockFile)) {
			if($staleAfter < 1 || !$this->reclaimStaleLock($staleAfter)) return false;
		}
		if(!is_dir($this->path)) return true;
		$files = glob($this->path . '*');
		foreach($files ?: [] as $file) {
			if(is_file($file) && !@unlink($file)) return false;
		}
		return @rmdir($this->path);
	}

	/**
	 * Does the current instance own the on-disk lock?
	 *
	 * @return bool
	 *
	 */
	protected function ownsLock(): bool {
		if($this->lockToken === '') return false;
		$contents = @file_get_contents($this->getLockFile());
		$token = strtok((string) $contents, "\r\n");
		return is_string($token) && hash_equals($this->lockToken, $token);
	}

	/**
	 * Atomically move and remove a lock only if it is still the stale file seen.
	 *
	 * @param int $staleAfter Maximum lock age in seconds
	 * @return bool
	 *
	 */
	protected function reclaimStaleLock(int $staleAfter): bool {
		$lockFile = $this->getLockFile();
		clearstatcache(true, $lockFile);
		$modified = @filemtime($lockFile);
		if(!$modified || $modified >= time() - $staleAfter) return false;
		$observed = @file_get_contents($lockFile);
		$stale = $lockFile . '.stale.' . getmypid() . '.' . bin2hex(random_bytes(4));
		if(!@rename($lockFile, $stale)) return false;
		clearstatcache(true, $stale);
		$renamedModified = @filemtime($stale);
		$renamed = @file_get_contents($stale);
		if($renamed !== $observed || !$renamedModified || $renamedModified >= time() - $staleAfter) {
			if(!is_file($lockFile)) @rename($stale, $lockFile);
			return false;
		}
		return @unlink($stale);
	}

	/**
	 * Ensure the session directory exists.
	 *
	 * @throws WireException
	 *
	 */
	protected function ensurePath(): void {
		if(is_dir($this->path)) return;
		if(!$this->wire()->files->mkdir($this->path, true) && !is_dir($this->path)) {
			throw new WireException("Unable to create AgentTools Engineer session directory: $this->id");
		}
	}

	/** @return string */
	protected function getStateFile(): string {
		return $this->path . 'state.json';
	}

	/** @return string */
	protected function getLockFile(): string {
		return $this->path . 'lock';
	}

	/**
	 * Sanitize a supplied session ID.
	 *
	 * @param string $id
	 * @return string
	 *
	 */
	protected function sanitizeId(string $id): string {
		$id = strtolower(trim($id));
		return preg_match('/^[a-z0-9][a-z0-9_-]{5,80}$/', $id) ? $id : '';
	}

	/**
	 * Create a unique session ID.
	 *
	 * @return string
	 *
	 */
	protected function newId(): string {
		return date('YmdHis') . '-' . bin2hex(random_bytes(8));
	}
}
