<?php namespace ProcessWire;

/**
 * Agent Tools Jobs
 *
 * File-backed queue service for background AgentTools work.
 *
 */
class AgentToolsJobs extends AgentToolsHelper {

	const statusPending = 'pending';
	const statusRunning = 'running';
	const statusDone = 'done';
	const statusFailed = 'failed';
	const runnerAgentTools = 'agenttools';

	/**
	 * Get array of CLI help [ 'syntax' => 'description' ]
	 *
	 * @return array
	 *
	 */
	public function cliHelp() {
		return [
			'php index.php --at-cron' => 'Run pending AgentTools background jobs from cron'
		];
	}

	/**
	 * Execute CLI action
	 *
	 * @param string $action
	 * @return bool|null Return true on success, false on fail, null if not applicable
	 *
	 */
	public function cliExecute(string $action): ?bool {
		if($action !== 'cron') return null;
		$result = $this->runCron();
		echo $result['message'] . "\n";
		return $result['success'];
	}

	/**
	 * Run pending jobs from cron
	 *
	 * @param array $options
	 * @return array
	 *
	 */
	public function runCron(array $options = []): array {
		$this->touchHeartbeat();
		$maxJobs = empty($options['maxJobs']) ? 1 : (int) $options['maxJobs'];
		if($maxJobs < 1) $maxJobs = 1;

		$lock = $this->lockCron();
		if(!$lock) {
			return [
				'success' => true,
				'processed' => 0,
				'message' => 'AgentTools cron already running',
			];
		}

		$processed = 0;
		$failed = 0;

		try {
			for($n = 0; $n < $maxJobs; $n++) {
				$file = $this->getNextPendingJobFile();
				if(!$file) break;
				$job = $this->claimJob($file);
				if(empty($job['id'])) continue;
				$result = $this->runJob($job);
				$processed++;
				if(empty($result['success'])) $failed++;
			}
		} finally {
			$this->unlockCron($lock);
		}

		return [
			'success' => true,
			'processed' => $processed,
			'failed' => $failed,
			'message' => "AgentTools cron processed $processed job(s), $failed failed",
		];
	}

	/**
	 * Add a job to the pending queue
	 *
	 * @param array $job
	 * @return array Normalized job data
	 * @throws WireException
	 *
	 */
	public function addJob(array $job): array {
		$now = time();
		$id = empty($job['id']) ? $this->newJobId() : $this->sanitizeJobId($job['id']);
		if($id === '') throw new WireException('Invalid AgentTools job ID');

		$defaults = [
			'id' => $id,
			'runner' => self::runnerAgentTools,
			'type' => 'engineer',
			'status' => self::statusPending,
			'created' => $now,
			'started' => 0,
			'finished' => 0,
			'userId' => 0,
			'userName' => '',
			'notifyEmail' => '',
			'modelIndex' => 0,
			'url' => '',
			'agentToolsUrl' => '',
			'taskName' => '',
			'taskInput' => [],
			'prompt' => '',
			'dryRun' => false,
			'response' => '',
			'error' => '',
			'emailError' => '',
			'migration' => '',
			'attempts' => 0,
		];

		$job = array_merge($defaults, $job);
		$job['id'] = $id;
		$job['status'] = self::statusPending;
		$job['created'] = empty($job['created']) ? $now : (int) $job['created'];
		$job['started'] = 0;
		$job['finished'] = 0;
		$job['attempts'] = (int) $job['attempts'];

		$file = $this->getJobFile($id, self::statusPending);
		if(is_file($file)) throw new WireException("AgentTools job already exists: $id");
		$this->writeJobFile($file, $job);
		return $job;
	}

	/**
	 * Is cron heartbeat recent enough to consider background jobs active?
	 *
	 * @param int $maxAge Seconds since last heartbeat
	 * @return bool
	 *
	 */
	public function isCronHealthy(int $maxAge = 3600): bool {
		$lastRun = $this->getCronLastRun();
		return $lastRun > 0 && $lastRun >= time() - $maxAge;
	}

	/**
	 * Get timestamp of last cron heartbeat
	 *
	 * @return int
	 *
	 */
	public function getCronLastRun(): int {
		$file = $this->getHeartbeatFile(false);
		return is_file($file) ? (int) filemtime($file) : 0;
	}

	/**
	 * Touch cron heartbeat file
	 *
	 */
	public function touchHeartbeat(): void {
		$file = $this->getHeartbeatFile(true);
		$this->wire()->files->filePutContents($file, date('c') . "\n");
		@touch($file);
	}

	/**
	 * Get all jobs in a status directory
	 *
	 * @param string $status
	 * @return array
	 *
	 */
	public function getJobs(string $status): array {
		$jobs = [];
		$dir = $this->getStatusPath($status, true);
		foreach($this->getJobFiles($dir) as $file) {
			$job = $this->readJobFile($file);
			if($job) $jobs[] = $job;
		}
		return $jobs;
	}

	/**
	 * Run one claimed job
	 *
	 * @param array $job
	 * @return array
	 *
	 */
	protected function runJob(array $job): array {
		$job['attempts'] = ((int) ($job['attempts'] ?? 0)) + 1;
		$type = (string) ($job['type'] ?? '');

		try {
			if(($job['runner'] ?? self::runnerAgentTools) !== self::runnerAgentTools) {
				throw new WireException("Unsupported AgentTools job runner: " . ($job['runner'] ?? ''));
			}
			if($type === 'engineer') {
				$job = $this->runEngineerJob($job);
			} else if($type === 'task') {
				$job = $this->runTaskJob($job);
			} else if($type === 'page-engineer') {
				$job = $this->runPageEngineerJob($job);
			} else {
				throw new WireException("Unsupported AgentTools job type: $type");
			}
			$job['finished'] = time();
			$job['status'] = self::statusDone;
			$job = $this->sendJobEmail($job);
			$this->finishJob($job, self::statusDone);
			return [ 'success' => true, 'job' => $job ];
		} catch(\Throwable $e) {
			$job['error'] = $e->getMessage();
			$job['finished'] = time();
			$job['status'] = self::statusFailed;
			$job = $this->sendJobEmail($job);
			$this->finishJob($job, self::statusFailed);
			return [ 'success' => false, 'job' => $job ];
		}
	}

	/**
	 * Run an Engineer job
	 *
	 * @param array $job
	 * @return array
	 * @throws WireException
	 *
	 */
	protected function runEngineerJob(array $job): array {
		$prompt = trim((string) ($job['prompt'] ?? ''));
		if($prompt === '') throw new WireException('Engineer job has no prompt.');
		$options = $this->getAgentOptions($job);
		$options['backgroundJob'] = true;
		if(isset($job['readOnly'])) $options['readOnly'] = (bool) $job['readOnly'];
		if(isset($job['dryRun'])) $options['dryRun'] = (bool) $job['dryRun'];
		if(!empty($job['history']) && is_array($job['history'])) $options['history'] = $job['history'];
		$result = $this->at->engineer->ask($prompt, $options);
		if(!empty($result['error'])) throw new WireException($result['error']);
		$job['response'] = (string) ($result['response'] ?? '');
		$job['migration'] = (string) ($result['migration'] ?? '');
		$job['history'] = is_array($result['history'] ?? null) ? $result['history'] : [];
		return $job;
	}

	/**
	 * Run a Task job
	 *
	 * @param array $job
	 * @return array
	 * @throws WireException
	 *
	 */
	protected function runTaskJob(array $job): array {
		$taskName = (string) ($job['taskName'] ?? '');
		if($taskName === '') throw new WireException('Task job has no task name.');
		$input = $job['taskInput'] ?? [];
		if(!is_array($input)) $input = [];
		$options = $this->getAgentOptions($job);
		$options['backgroundJob'] = true;
		if(isset($job['readOnly'])) $options['readOnly'] = (bool) $job['readOnly'];
		if(isset($job['dryRun'])) $options['dryRun'] = (bool) $job['dryRun'];
		if(isset($job['maxIterations'])) $options['maxIterations'] = (int) $job['maxIterations'];
		$result = $this->at->getTasks()->run($taskName, $input, $options);
		if(!empty($result['error'])) throw new WireException($result['error']);
		$job['response'] = (string) ($result['response'] ?? '');
		$job['migration'] = (string) ($result['migration'] ?? '');
		$job['request'] = (string) ($result['request'] ?? '');
		$job['readOnly'] = (bool) ($result['readOnly'] ?? false);
		$job['history'] = is_array($result['history'] ?? null) ? $result['history'] : [];
		return $job;
	}

	/**
	 * Run a Page Engineer job
	 *
	 * @param array $job
	 * @return array
	 * @throws WireException
	 *
	 */
	protected function runPageEngineerJob(array $job): array {
		/** @var FieldtypePageEngineer|null $fieldtype */
		$fieldtype = $this->wire()->modules->get('FieldtypePageEngineer');
		if(!$fieldtype instanceof FieldtypePageEngineer) {
			throw new WireException('FieldtypePageEngineer is not installed.');
		}
		return $fieldtype->runBackgroundJob($job);
	}

	/**
	 * Get a job by ID from any status directory
	 *
	 * @param string $id
	 * @param array $statuses Statuses to search
	 * @return array
	 *
	 */
	public function getJob(string $id, array $statuses = []): array {
		$id = $this->sanitizeJobId($id);
		if($id === '') return [];
		if(!count($statuses)) {
			$statuses = [ self::statusDone, self::statusFailed, self::statusRunning, self::statusPending ];
		}
		foreach($statuses as $status) {
			if(!in_array($status, [ self::statusDone, self::statusFailed, self::statusRunning, self::statusPending ], true)) continue;
			$file = $this->getJobFile($id, $status);
			$job = $this->readJobFile($file);
			if($job) return $job;
		}
		return [];
	}

	/**
	 * Get AgentTools Engineer options for a job
	 *
	 * @param array $job
	 * @return array
	 * @throws WireException
	 *
	 */
	protected function getAgentOptions(array $job): array {
		$modelIndex = isset($job['modelIndex']) ? (int) $job['modelIndex'] : 0;
		if($modelIndex < 0) $modelIndex = 0;
		$agents = $this->at->getAgents();
		$agent = $agents->eq($modelIndex);
		if(!$agent) $agent = $agents->first();
		if(!$agent || !$agent->apiKey) {
			throw new WireException('No agent configured. Add API credentials in AgentTools module settings.');
		}
		$options = [
			'provider' => $agent->provider,
			'apiKey' => $agent->apiKey,
			'model' => $agent->model,
			'endpoint' => $agent->endpointUrl,
			'readOnly' => (bool) $this->at->get('engineer_readonly'),
			'verbose' => false,
		];
		if(isset($job['maxIterations'])) $options['maxIterations'] = (int) $job['maxIterations'];
		return $options;
	}

	/**
	 * Send job completion email when a notification address is present
	 *
	 * @param array $job
	 * @return array
	 *
	 */
	protected function sendJobEmail(array $job): array {
		$email = trim((string) ($job['notifyEmail'] ?? ''));
		if($email === '') return $job;
		try {
			$status = (string) ($job['status'] ?? '');
			$failed = !empty($job['error']);
			$config = $this->wire()->config;
			$subject = $failed ? 'AgentTools background job failed' : 'AgentTools background job complete';
			if($config->httpHost) $subject .= " on {$config->httpHost}";
			$mail = wireMail();
			$mail->to($email);
			$mail->subject($subject);
			$mail->body($this->renderJobEmailBody($job));
			$mail->bodyHTML($this->renderJobEmailBody($job, true));
			$sent = $mail->send();
			if(!$sent) $job['emailError'] = 'Email send returned no recipients sent.';
			$job['emailStatus'] = $status;
		} catch(\Throwable $e) {
			$job['emailError'] = $e->getMessage();
		}
		return $job;
	}

	/**
	 * Render plain text job email body
	 *
	 * @param array $job
	 * @param bool $useHtml
	 * @return string
	 *
	 */
	protected function renderJobEmailBody(array $job, $useHtml = false): string {
		$lines = [];
		$lines[] = '## AgentTools background job';
		$lines[] = '';
		$lines[] = '- **Job:** ' . ($job['id'] ?? '');
		$lines[] = '- **Type:** ' . ($job['type'] ?? '');
		$lines[] = '- **Status:** ' . (!empty($job['error']) ? self::statusFailed : self::statusDone);
		if(!empty($job['dryRun'])) $lines[] = '- **Mode:** Preview only';
		if(!empty($job['url'])) $lines[] = '- **Submitted from:** ' . $job['url'];
		if(!empty($job['taskName'])) $lines[] = '- **Task:** ' . $job['taskName'];
		if(!empty($job['pageEditUrl'])) {
			$pageTitle = trim((string) ($job['pageTitle'] ?? ''));
			if($pageTitle === '') $pageTitle = 'Edit page';
			$lines[] = '- **Page:** [' . $pageTitle . '](' . $job['pageEditUrl'] . ')';
		}
		if(!empty($job['fieldName'])) $lines[] = '- **Field:** ' . $job['fieldName'];
		if(!empty($job['migration'])) $lines[] = '- **Migration:** ' . $job['migration'];
		$lines[] = '';
		if(!empty($job['error'])) {
			$lines[] = '- **Error:**';
			$lines[] = $job['error'];
		} else {
			$lines[] = '';
			$lines[] = '## Response:';
			$lines[] = '';
			$lines[] = trim((string) ($job['response'] ?? ''));
		}
		$replyUrl = $this->getJobReplyUrl($job);
		$viewUrl = $this->getJobViewUrl($job);
		if($replyUrl) {
			$lines[] = '';
			$lines[] = '[Reply to this job](' . $replyUrl . ')';
		}
		if($viewUrl) {
			$lines[] = '';
			$lines[] = '[View this job in AgentTools](' . $viewUrl . ')';
		}

		$body = implode("\n", $lines) . "\n";

		if($useHtml) {
			$body = $this->at->markdownToHtml($body, [ 'email' => true ]);
		}

		return $body;
	}

	/**
	 * Get admin reply URL for a completed job
	 *
	 * @param array $job
	 * @return string
	 *
	 */
	protected function getJobReplyUrl(array $job): string {
		if(($job['type'] ?? '') === 'page-engineer') return '';
		if(empty($job['history']) || empty($job['id'])) return '';
		$baseUrl = $this->getJobAdminBaseUrl($job);
		if($baseUrl === '') return '';
		return rtrim($baseUrl, '/') . '/reply-job/?id=' . rawurlencode($job['id']);
	}

	/**
	 * Get admin view URL for a job
	 *
	 * @param array $job
	 * @return string
	 *
	 */
	protected function getJobViewUrl(array $job): string {
		if(empty($job['id'])) return '';
		$baseUrl = $this->getJobAdminBaseUrl($job);
		if($baseUrl === '') return '';
		return rtrim($baseUrl, '/') . '/view-job/?id=' . rawurlencode($job['id']);
	}

	/**
	 * Get AgentTools admin base URL for a job
	 *
	 * @param array $job
	 * @return string
	 *
	 */
	protected function getJobAdminBaseUrl(array $job): string {
		$baseUrl = trim((string) ($job['agentToolsUrl'] ?? ''));
		if($baseUrl === '') {
			$baseUrl = trim((string) ($job['url'] ?? ''));
			$baseUrl = preg_replace('!/(engineer|tasks|run-task/[^/]+|edit-task/[^/]+|reply-task/[^/]+|view-job|reply-job)/?$!', '/', $baseUrl);
		}
		return $baseUrl;
	}

	/**
	 * Claim a pending job and move it to running
	 *
	 * @param string $file
	 * @return array
	 *
	 */
	protected function claimJob(string $file): array {
		$job = $this->readJobFile($file);
		if(!$job) return [];
		$id = $this->sanitizeJobId($job['id'] ?? basename($file, '.json'));
		if($id === '') return [];
		$runningFile = $this->getJobFile($id, self::statusRunning);
		$job['id'] = $id;
		$job['status'] = self::statusRunning;
		$job['started'] = time();
		$this->writeJobFile($runningFile, $job);
		@unlink($file);
		return $job;
	}

	/**
	 * Finish a job and move it out of running
	 *
	 * @param array $job
	 * @param string $status
	 *
	 */
	protected function finishJob(array $job, string $status): void {
		$id = $this->sanitizeJobId($job['id'] ?? '');
		if($id === '') return;
		if(!in_array($status, [ self::statusDone, self::statusFailed ], true)) $status = self::statusFailed;
		$job['status'] = $status;
		if(empty($job['finished'])) $job['finished'] = time();
		$this->writeJobFile($this->getJobFile($id, $status), $job);
		$runningFile = $this->getJobFile($id, self::statusRunning);
		if(is_file($runningFile)) @unlink($runningFile);
	}

	/**
	 * Get next pending job file
	 *
	 * @return string
	 *
	 */
	protected function getNextPendingJobFile(): string {
		$files = $this->getJobFiles($this->getStatusPath(self::statusPending, true));
		return empty($files) ? '' : reset($files);
	}

	/**
	 * Get JSON job files in a directory
	 *
	 * @param string $dir
	 * @return array
	 *
	 */
	protected function getJobFiles(string $dir): array {
		$files = glob($dir . '*.json');
		if(!$files) return [];
		sort($files, SORT_STRING);
		return $files;
	}

	/**
	 * Read a job JSON file
	 *
	 * @param string $file
	 * @return array
	 *
	 */
	protected function readJobFile(string $file): array {
		if(!is_file($file) || !is_readable($file)) return [];
		$json = file_get_contents($file);
		$job = json_decode((string) $json, true);
		return is_array($job) ? $job : [];
	}

	/**
	 * Write a job JSON file
	 *
	 * @param string $file
	 * @param array $job
	 * @throws WireException
	 *
	 */
	protected function writeJobFile(string $file, array $job): void {
		$json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if($json === false) throw new WireException('Unable to encode AgentTools job JSON');
		$this->wire()->files->filePutContents($file, $json . "\n", LOCK_EX);
	}

	/**
	 * Acquire cron lock
	 *
	 * @return resource|false
	 *
	 */
	protected function lockCron() {
		$file = $this->getJobsPath() . '.cron.lock';
		$fp = fopen($file, 'c');
		if($fp === false) return false;
		if(flock($fp, LOCK_EX | LOCK_NB)) return $fp;
		fclose($fp);
		return false;
	}

	/**
	 * Release cron lock
	 *
	 * @param resource|false|null $fp
	 *
	 */
	protected function unlockCron($fp): void {
		if(!is_resource($fp)) return;
		flock($fp, LOCK_UN);
		fclose($fp);
	}

	/**
	 * Get jobs path
	 *
	 * @return string
	 *
	 */
	protected function getJobsPath(): string {
		return $this->at->getFilesPath('jobs');
	}

	/**
	 * Get path for a job status directory
	 *
	 * @param string $status
	 * @param bool $create
	 * @return string
	 * @throws WireException
	 *
	 */
	protected function getStatusPath(string $status, bool $create = true): string {
		if(!in_array($status, [ self::statusPending, self::statusRunning, self::statusDone, self::statusFailed ], true)) {
			throw new WireException("Invalid AgentTools job status: $status");
		}
		return $create ? $this->at->getFilesPath("jobs/$status") : $this->getJobsPath() . "$status/";
	}

	/**
	 * Get file for a job ID and status
	 *
	 * @param string $id
	 * @param string $status
	 * @return string
	 *
	 */
	protected function getJobFile(string $id, string $status): string {
		$id = $this->sanitizeJobId($id);
		return $this->getStatusPath($status, true) . "$id.json";
	}

	/**
	 * Get heartbeat file
	 *
	 * @param bool $create
	 * @return string
	 *
	 */
	protected function getHeartbeatFile(bool $create): string {
		return ($create ? $this->getJobsPath() : $this->at->getFilesPath('jobs')) . 'cron-last-run.txt';
	}

	/**
	 * Create a job ID
	 *
	 * @return string
	 *
	 */
	protected function newJobId(): string {
		try {
			$random = bin2hex(random_bytes(4));
		} catch(\Exception $e) {
			$random = substr(sha1(uniqid('', true)), 0, 8);
		}
		return date('YmdHis') . "-$random";
	}

	/**
	 * Sanitize job ID
	 *
	 * @param string $id
	 * @return string
	 *
	 */
	protected function sanitizeJobId(string $id): string {
		$id = strtolower(trim($id));
		$id = preg_replace('/[^a-z0-9_.-]+/', '-', $id);
		return trim($id, '.-');
	}
}
