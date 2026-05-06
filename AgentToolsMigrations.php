<?php namespace ProcessWire;

/**
 * Agent Tools Migrations
 *
 */
class AgentToolsMigrations extends AgentToolsHelper {
	
	/**
	 * Get array of CLI help [ 'syntax' => 'description' ]
	 *
	 * @return array
	 *
	 */
	public function cliHelp() {
		return array_merge(parent::cliHelp(), [
			"php index.php --at-migrations-apply" => "Apply all pending migrations",
			"php index.php --at-migrations-list" => "List migrations and their status",
			"php index.php --at-migrations-test" => "Preview pending without applying",
		]);
	}
	
	/**
	 * Execute CLI action
	 *
	 * @param string $atAction
	 * @return bool|null Return true on success, false on fail, null if not applicable
	 *
	 */
	public function cliExecute(string $atAction): ?bool {
		if($atAction === 'help') {
			echo $this->at->renderHelp($this->cliHelp(), 'Migrations usage');
			return true;
		}
		$at = $this->at;
		$fuel = $this->wire()->fuel->getArray();
		extract($fuel);
		$success = include(__DIR__ . '/agent_migrate.php');
		return $success;
	}

	/**
	 * Get the migration name from its filename
	 *
	 * @param string $file Full path or basename of migration file
	 * @return string e.g. "add-blog-post-template"
	 *
	 */
	public function getName($file) {
		[, $name] = explode('_', basename($file, '.php'), 2);
		return $name;
	}

	/**
	 * Get applied migrations registry from module config
	 *
	 * @return array Array of applied migration basenames
	 *
	 */
	public function getApplied() {
		$applied = $this->wire()->modules->getConfig($this->at, 'appliedMigrations');
		return is_array($applied) ? $applied : [];
	}

	/**
	 * Is the given migration already applied?
	 *
	 * @param string $file Full path or basename of migration file
	 * @return bool
	 *
	 */
	public function isApplied($file) {
		return in_array(basename($file), $this->getApplied());
	}

	/**
	 * Record a migration as applied in the registry
	 *
	 * @param string $file Full path or basename of migration file
	 *
	 */
	public function addApplied($file) {
		$applied = $this->getApplied();
		$basename = basename($file);
		if(!in_array($basename, $applied)) {
			$applied[] = $basename;
			$this->wire()->modules->saveConfig($this->at, 'appliedMigrations', $applied);
		}
	}

	/**
	 * Remove a migration from the applied registry
	 *
	 * @param string $file Full path or basename of migration file
	 *
	 */
	public function removeApplied($file) {
		$applied = $this->getApplied();
		$basename = basename($file);
		$applied = array_values(array_filter($applied, function($v) use($basename) { return $v !== $basename; }));
		$this->wire()->modules->saveConfig($this->at, 'appliedMigrations', $applied);
	}

	/**
	 * Get migration files in a directory, sorted chronologically by timestamp prefix
	 *
	 * @param string $dir Path to migrations directory
	 * @return array Array of full file paths
	 *
	 */
	public function getFiles(string $dir): array {
		if(!is_dir($dir)) return [];
		$pattern = '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.php';
		$files = glob($dir . $pattern);
		if(!$files) return [];
		sort($files);
		return $files;
	}

	/**
	 * Extract ISO-8601 datetime string from migration filename
	 *
	 * @param string $file Full path or basename of migration file
	 * @return string e.g. "2026-04-03 15:51:46" or empty string if not parseable
	 *
	 */
	public function getDatetime(string $file): string {
		$ts = substr(basename($file), 0, 14);
		if(!ctype_digit($ts)) return '';
		return
			substr($ts, 0, 4) . '-' . substr($ts, 4, 2) . '-' . substr($ts, 6, 2) . ' ' .
			substr($ts, 8, 2) . ':' . substr($ts, 10, 2) . ':' . substr($ts, 12, 2);
	}
	
	/**
	 * Get migration title from filename
	 * 
	 * @param string $file
	 * @return string
	 * 
	 */
	public function getTitle(string $file): string {
		[, $title] = explode('_', basename($file, '.php'), 2);
		$title = str_replace('_', ' ', $title);
		return ucfirst($title);
	}

	/**
	 * Extract the embedded markdown summary from a migration file's docblock
	 *
	 * Returns the content of the first /** docblock immediately after the opening
	 * <?php tag, with leading ' * ' stripped from each line.
	 *
	 * @param string $file Full path to migration file
	 * @return string Markdown summary, or empty string if none found
	 *
	 */
	public function getSummary(string $file): string {
		$content = file_get_contents($file);
		if(!preg_match('/^<\?php[^\n]*\n\/\*\*(.*?)\*\//s', $content, $matches)) return '';
		$lines = explode("\n", trim($matches[1]));
		$lines = array_map(function($line) { return preg_replace('/^\s*\*\s?/', '', $line); }, $lines);
		return trim(implode("\n", $lines));
	}
	
	/**
	 * Get all available info about a migration
	 *
	 * @param string $file
	 * @return array
	 *
	 */
	public function getInfo(string $file): array {
		return [
			'file' => $file,
			'name' => basename($file),
			'title' => $this->getTitle($file),
			'datetime' => $this->getDatetime($file),
			'summary' => $this->getSummary($file),
		];
	}

	/**
	 * Get the signing key for migration bundles
	 *
	 * Uses $config->atMigrationSecret if set, otherwise $config->tableSalt.
	 * Both values are available on dev and production because they share the
	 * same site/config.php. For transfers between truly different installations,
	 * set $config->atMigrationSecret to the same value on both sites.
	 *
	 * @return string
	 *
	 */
	protected function getSigningKey(): string {
		$config = $this->wire()->config;
		$secret = (string) $config->get('atMigrationSecret');
		if(strlen($secret)) return $secret;
		$salt = (string) $config->tableSalt;
		if(strlen($salt)) return $salt;
		return (string) $config->userAuthSalt;
	}

	/**
	 * Export migration files as a signed, portable bundle string
	 *
	 * The bundle is: atbundle1.{base64url_payload}.{hmac_sha256}
	 * The HMAC covers the first two segments (prefix + payload) using the signing key.
	 *
	 * @param array $files Full file paths to migration files
	 * @return string Signed bundle string suitable for copy/paste
	 *
	 */
	public function exportBundle(array $files): string {
		$migrations = [];
		$titles = [];
		foreach($files as $file) {
			$migrations[] = [
				'filename' => basename($file),
				'content' => file_get_contents($file),
			];
			$titles[] = $this->getTitle($file);
		}
		$json = json_encode(['version' => 1, 'migrations' => $migrations]);
		$encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
		$prefix = 'atbundle1';
		$message = "$prefix.$encoded";
		$hmac = hash_hmac('sha256', $message, $this->getSigningKey());
		$label = implode(', ', $titles);
		return "$label\n$message.$hmac";
	}

	/**
	 * Verify and decode a migration bundle string
	 *
	 * @param string $bundle Bundle string from exportBundle()
	 * @return array [ 'migrations' => [ ['filename' => '', 'content' => ''], ... ], 'error' => '' ]
	 *
	 */
	public function importBundle(string $bundle): array {
		$empty = ['migrations' => [], 'error' => ''];

		// Strip optional human-readable label line(s) preceding the bundle line
		foreach(explode("\n", trim($bundle)) as $line) {
			$line = trim($line);
			if(strpos($line, 'atbundle1.') === 0) {
				$bundle = $line;
				break;
			}
		}

		// Bundle is: atbundle1.{base64url}.{hmac} — exactly 3 dot-separated segments
		$parts = explode('.', $bundle, 4);
		if(count($parts) !== 3 || $parts[0] !== 'atbundle1') {
			return array_merge($empty, ['error' => 'Invalid bundle format.']);
		}

		[$prefix, $encoded, $hmac] = $parts;
		$message = "$prefix.$encoded";

		// Constant-time HMAC comparison to prevent timing attacks
		$expected = hash_hmac('sha256', $message, $this->getSigningKey());
		if(!hash_equals($expected, $hmac)) {
			return array_merge($empty, ['error' =>
				'Bundle signature verification failed. ' .
				'The bundle may have been created with a different signing key, or may have been tampered with. ' .
				'If transferring between different installations, set $config->atMigrationSecret to the same value on both sites.'
			]);
		}

		// Decode payload
		$json = base64_decode(strtr($encoded, '-_', '+/'));
		if($json === false) return array_merge($empty, ['error' => 'Invalid bundle payload.']);

		$data = json_decode($json, true);
		if(!is_array($data) || empty($data['migrations']) || !is_array($data['migrations'])) {
			return array_merge($empty, ['error' => 'Invalid bundle payload.']);
		}

		$migrations = [];
		foreach($data['migrations'] as $item) {
			if(!is_array($item)) continue;
			$filename = basename((string) ($item['filename'] ?? ''));
			$content = (string) ($item['content'] ?? '');
			// Filename must match migration pattern: 14-digit timestamp _ name .php
			if(!preg_match('/^\d{14}_[\w-]+\.php$/', $filename)) continue;
			if(!strlen(trim($content))) continue;
			$migrations[] = ['filename' => $filename, 'content' => $content];
		}

		if(empty($migrations)) {
			return array_merge($empty, ['error' => 'Bundle contained no valid migration files.']);
		}

		return ['migrations' => $migrations, 'error' => ''];
	}


}