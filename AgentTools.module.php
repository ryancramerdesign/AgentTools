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
 * @property AgentToolsMigrations $migrations
 * @property AgentToolsSitemap $sitemap
 * @property AgentToolsEngineer $engineer
 *
 */
class AgentTools extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return [
			'title' => 'Agent Tools',
			'summary' => 'Enables AI coding agents to access ProcessWire’s API and provides a database migration system.',
			'icon' => 'asterisk',
			'version' => 3,
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
	 * Helpers indexed by name
	 * 
	 * @var array|AgentToolsHelper[] 
	 * 
	 */
	protected $helpers = [
		'migrations' => null,
		'skills' => null,
		'sitemap' => null,
		'engineer' => null,
	];

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

		} else {
			$found = false;
			foreach(array_keys($this->helpers) as $name) {
				if(strpos($atAction, "$name-") !== 0) continue;
				$act = str_replace("$name-", '', $atAction);
				$helper = $this->getHelper($name);
				if(!$helper) continue;
				$success = $helper->cliExecute($act); 
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
	 * Get array of CLI help [ 'syntax' => 'description' ]
	 *
	 * @return array
	 *
	 */
	protected function cliHelp() {
		$help = [
			"php index.php --at-cli" => "Used by AI agents to work with the ProcessWire API",
			"php index.php --at-eval 'CODE'" => "Evaluate a PHP expression",
			"echo 'CODE' | php index.php --at-stdin" => "Evaluate PHP code from stdin",
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
		
		foreach($help as $code => $desc) {
			$length = strlen($code);
			if($length > $maxCodeLength) $maxCodeLength = $length;
		}
		
		$maxCodeLength += 3; 
		
		$out = 
			"\nProcessWire AgentTools" . 
			"\n======================" . 
			"\n$label:\n";
		
		foreach($help as $code => $desc) {
			while(strlen($code) < $maxCodeLength) $code .= ' ';
			$out .= "  $code $desc\n";
		}
	
		return $out;
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