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


}