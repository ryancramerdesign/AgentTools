<?php namespace ProcessWire;

/**
 * AgentTools
 *
 * Enables AI coding agents (e.g. Claude Code) to access ProcessWire's API
 * via CLI, and provides a database migration system for transferring changes
 * across environments.
 *
 * Copyright 2026 Ryan Cramer and Claude (Anthropic) | MIT
 *
 */
class AgentTools extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return [
			'title' => 'Agent Tools',
			'summary' => 'Enables AI coding agents to access ProcessWire’s API and provides a database migration system.',
			'icon' => 'asterisk',
			'version' => 2,
			'author' => 'Ryan Cramer and Claude (Anthropic)',
			'requires' => 'ProcessWire>=3.0.255',
			'installs' => 'ProcessAgentTools',
			'autoload' => true,
			'singular' => true,
		];
	}

	/**
	 * Name used in this module's assets directory and CLI prefix
	 *
	 */
	const name = 'at';

	/**
	 * Called when module is wired to API
	 *
	 * Creates an `$at` ProcessWire API variable
	 *
	 */
	public function wired() {
		$this->wire()->wire('at', $this);
		parent::wired();
	}

	/**
	 * ProcessWire API ready
	 *
	 */
	public function ready() {
		if(PHP_SAPI === 'cli') {
			$argv = $GLOBALS['argv'];
			$prefix = '--' . self::name . '-';
			if(!empty($argv[1]) && strpos($argv[1], $prefix) === 0) {
				$atAction = str_replace($prefix, '', $argv[1]);
				$this->cliReady($atAction);
			}
		}
	}

	/**
	 * Command line interface (CLI) ready
	 *
	 * Please note that this method halts execution when it's done, rather than return.
	 *
	 */
	protected function cliReady($atAction) {

		$originalDir = getcwd();
		chdir($this->wire()->config->paths->root);

		$at = $this;
		$success = false;
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

		} else if($atAction === 'eval' && !empty($GLOBALS['argv'][2])) {
			$success = $this->cliEval($GLOBALS['argv'][2], $fuel);

		} else if($atAction === 'stdin') {
			$code = file_get_contents('php://stdin');
			if(strlen(trim($code))) $success = $this->cliEval($code, $fuel);

		} else if(strpos($atAction, 'migrations-') === 0) {
			$atAction = str_replace('migrations-', '', $atAction);
			$success = include(__DIR__ . '/agent_migrate.php');

		} else {
			echo "Unrecognized AgentTools action: $atAction\n";
			$success = false;
		}

		chdir($originalDir);
		$this->wire()->finished();

		if(!$success) {
			echo $this->renderHelp();
			exit(1);
		}

		exit(0);
	}

	/**
	 * Evaluate PHP code string in the context of PW API variables
	 *
	 * @param string $code PHP code to evaluate (without opening <?php tag)
	 * @param array $fuel ProcessWire API variables
	 * @return bool
	 *
	 */
	protected function cliEval($code, array $fuel) {
		$at = $this;
		extract($fuel);
		$code = '?>' . '<?php namespace ProcessWire; ' . $code;
		try {
			eval($code);
			return true;
		} catch(\Throwable $e) {
			echo "ERROR: " . $e->getMessage() . "\n";
			echo "  Line: " . $e->getLine() . "\n";
			return false;
		}
	}

	/**
	 * Render CLI summary of available commands
	 *
	 * @return string
	 *
	 */
	protected function renderHelp() {
		return
			"\nProcessWire AgentTools:\n" .
			str_repeat('=', 60) . "\n\n" .
			"Usage:\n" .
			"  php index.php --at-cli                    Used by AI agents to work with the ProcessWire API\n" .
			"  php index.php --at-eval 'CODE'            Evaluate a PHP expression\n" .
			"  echo 'CODE' | php index.php --at-stdin    Evaluate PHP code from stdin\n" .
			"  php index.php --at-migrations-apply       Apply all pending migrations\n" .
			"  php index.php --at-migrations-list        List migrations and their status\n" .
			"  php index.php --at-migrations-test        Preview pending without applying\n" .
			"\n";
	}

	/**
	 * Get the migration name from its filename
	 *
	 * @param string $file Full path or basename of migration file
	 * @return string e.g. "add-blog-post-template"
	 *
	 */
	public function getMigrationName($file) {
		[, $name] = explode('_', basename($file, '.php'), 2);
		return $name;
	}

	/**
	 * Get applied migrations registry from module config
	 *
	 * @return array Array of applied migration basenames
	 *
	 */
	public function getAppliedMigrations() {
		$applied = $this->wire()->modules->getConfig($this, 'appliedMigrations');
		return is_array($applied) ? $applied : [];
	}

	/**
	 * Is the given migration already applied?
	 *
	 * @param string $file Full path or basename of migration file
	 * @return bool
	 *
	 */
	public function isMigrationApplied($file) {
		return in_array(basename($file), $this->getAppliedMigrations());
	}

	/**
	 * Record a migration as applied in the registry
	 *
	 * @param string $file Full path or basename of migration file
	 *
	 */
	public function addAppliedMigration($file) {
		$applied = $this->getAppliedMigrations();
		$basename = basename($file);
		if(!in_array($basename, $applied)) {
			$applied[] = $basename;
			$this->wire()->modules->saveConfig($this, 'appliedMigrations', $applied);
		}
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
	 * Module config
	 *
	 * @param InputfieldWrapper $inputfields
	 *
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {

		// Handle _install_skill action on config save
		if($this->wire()->input->post('_install_skill')) {
			$this->doInstallSkill();
		}

		$skillPath = $this->getSkillPath();
		$f = $inputfields->InputfieldCheckbox;
		$f->attr('name', '_install_skill');
		$f->label = $this->_('Install agent skill to project?');
		$f->description = sprintf($this->_('Copies the AgentTools skill files to: %s'), $skillPath);
		$f->val(0);
		$f->themeOffset = 1; 
		if(is_dir($skillPath)) {
			$f->collapsed = Inputfield::collapsedYes; 
			$f->notes = $this->_('Note that the skill files are already installed. This would re-install it.');
			$f->label2 = $f->label;
			$f->label = $this->_('Skill files are installed!'); 
			$f->icon = 'check';
		} else {
			$f->notes = $this->_('Installation recommended in dev environments.'); 
		}
		$inputfields->add($f);

		$f = $inputfields->InputfieldToggle;
		$f->attr('name', '_uninstall_files');
		$f->label = $this->_('Also delete AgentTools files in /site/assets/at/');
		$f->description = $this->_('If you intend to re-install this module at some point, you may want to leave the files in place.');
		$f->showIf = 'uninstall=AgentTools';
		$f->val(0);
		$inputfields->add($f);
	}

	/**
	 * Get the path for AgentTools skill
	 * 
	 * @param bool $getSrc Get source path for skill rather than destination?
	 * @return string
	 * 
	 */
	protected function getSkillPath($getSrc = false) {
		$dir = 'agents/skills/processwire-agenttools/';
		if($getSrc) {
			$path = __DIR__ . "/$dir";
		} else {
			$path = $this->wire()->config->paths->root . ".$dir";
		}
		return $path;
	}
	
	/**
	 * Copy agent skill files to the project root
	 *
	 */
	protected function doInstallSkill() {
		$srcDir = $this->getSkillPath(true); 
		if(!is_dir($srcDir)) {
			$this->error($this->_('Skill source directory not found in module:') . " $srcDir");
			return;
		}

		$destDir = $this->getSkillPath();
		$files = $this->wire()->files;

		if(!is_dir($destDir)) {
			$writable = $files->mkdir(dirname($destDir), true);
		} else {
			$writable = is_writable($destDir);
		}
		
		$howTo = sprintf(
			$this->_('To install agent skill, please manually copy %1$s to %2$s'),
			$srcDir, $destDir
		);
		
		if(!$writable) {
			$this->error($this->_('ProcessWire root directory is not writable.') . " $howTo");

		} else if($files->copy($srcDir, $destDir)) {
			$this->wire()->session->message(sprintf(
				$this->_('Agent skill installed to: %s'), 
				$destDir
			));
		} else {
			$this->error($this->_('Failed to copy agent skill files.') . " $howTo"); 
		}
	}

	/**
	 * Upgrade module
	 *
	 * @param int|string $fromVersion
	 * @param int|string $toVersion
	 *
	 */
	public function ___upgrade($fromVersion, $toVersion) {
		$destDir = $this->getSkillPath();
		if(is_dir($destDir)) $this->doInstallSkill();
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
}