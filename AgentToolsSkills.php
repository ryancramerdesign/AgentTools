<?php namespace ProcessWire;

/**
 * Agent Tools Skills
 *
 */
class AgentToolsSkills extends AgentToolsHelper {

	/**
	 * Get array of CLI help [ 'syntax' => 'description' ]
	 *
	 * @return array
	 *
	 */
	public function cliHelp() {
		return [
			'php index.php --at-skills-install' => 'Install AgentTools skills'
		];
	}

	/**
	 * Execute CLI action
	 *
	 * @param string $action
	 * @return bool|null Return true on success, false on fail, null if not applicable
	 *
	 */
	public function cliExecute($action) {
		if($action === 'install') {
			$this->doInstallSkill();
			echo $this->wire()->notices->renderText();
			return true;
		} else {
			return null;
		}
	}
	
	/**
	 * Module config
	 *
	 * @param InputfieldWrapper $inputfields
	 *
	 */
	public function getConfigInputfields(InputfieldWrapper $inputfields) {

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
			$writable = $files->mkdir($destDir, true);
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
			$message = sprintf($this->_('Agent skill installed to: %s'), $destDir);
			if(PHP_SAPI === 'cli') {
				$this->message($message);
			} else {
				// populating to session ensures message displayed on request
				// after ProcessModule does a redirect to itself after save
				$this->wire()->session->message($message);
			}
		} else {
			$this->error($this->_('Failed to copy agent skill files.') . " $howTo");
		}
	}

	/**
	 * Upgrade 
	 * 
	 * @param string $fromVersion
	 * @param string $toVersion
	 * 
	 */
	public function upgrade($fromVersion, $toVersion) {
		$destDir = $this->getSkillPath();
		if(is_dir($destDir)) $this->doInstallSkill();
	}
}