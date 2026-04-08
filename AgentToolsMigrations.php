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
	public function cliExecute($atAction) {
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


}