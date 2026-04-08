<?php namespace ProcessWire;

/**
 * ProcessAgentTools
 *
 * Admin UI for viewing and applying AgentTools migrations.
 * Shows migration status (applied/pending) and allows applying pending
 * migrations via button, capturing and rendering migration output as HTML.
 *
 * Copyright 2026 Ryan Cramer and Claude (Anthropic) | MIT
 *
 */
class ProcessAgentTools extends Process {

	public static function getModuleInfo() {
		return [
			'title' => 'Agent Tools',
			'summary' => 'Admin interface for viewing and applying AgentTools migrations.',
			'version' => 1,
			'author' => 'Claude (Anthropic) and Ryan Cramer',
			'icon' => 'asterisk',
			'requires' => 'AgentTools',
			'page' => [
				'name' => 'agent-tools',
				'parent' => 'setup',
				'title' => 'Agent Tools',
			],
		];
	}

	/**
	 * Main execute: show migration status with apply button if pending
	 *
	 * @return string
	 *
	 */
	public function ___execute() {
		$user = $this->wire()->user; 
		$input = $this->wire()->input;
		$csrf = $this->wire()->session->CSRF();
		
		if(!$user->isSuperuser()) {
			throw new WirePermissionException("Superuser is required");
		}

		if($input->post('submit_apply')) {
			$csrf->validate();
			return $this->processApply();
		}

		return $this->renderStatus();
	}

	/**
	 * Render migration status table
	 *
	 * @return string
	 *
	 */
	protected function renderStatus() {
		/** @var AgentTools $at */
		$at = $this->wire('at');
		$migrationsDir = $at->getFilesPath('migrations');
		$migrationFiles = $this->getMigrationFiles($migrationsDir);

		$this->headline($this->_('Agent Tools: Migrations'));
		$out = '';

		if(empty($migrationFiles)) {
			$out .= "<p class='notes'>" .
				$this->_('No migration files found in:') . " <code>" . htmlspecialchars($migrationsDir) . "</code>" .
				"</p>";
			return $out;
		}

		$pendingCount = 0;
		foreach($migrationFiles as $file) {
			if(!$at->migrations->isApplied($file)) $pendingCount++;
		}

		/** @var MarkupAdminDataTable $table */
		$table = $this->wire()->modules->get('MarkupAdminDataTable');
		$table->setEncodeEntities(false);
		$table->headerRow([
			$this->_('Migration file'),
			$this->_('Date/time'),
			$this->_('Status'),
		]);

		$labelApplied = $this->_('Applied');
		$labelPending = $this->_('Pending');

		foreach($migrationFiles as $file) {
			$applied = $at->migrations->isApplied($file);
			$status = $applied
				? "<span class='ui-priority-secondary'>$labelApplied</span>"
				: "<strong class='ui-priority-primary'>$labelPending</strong>";
			$table->row([
				htmlspecialchars(basename($file)),
				$this->getMigrationDatetime($file),
				$status,
			]);
		}

		$out .= $table->render();

		$appliedCount = count($migrationFiles) - $pendingCount;
		$out .= "<p class='detail'>" .
			sprintf($this->_('%d applied, %d pending'), $appliedCount, $pendingCount) .
			"</p>";

		if($pendingCount > 0) {
			$label = sprintf(
				$this->_n('Apply %d pending migration', 'Apply %d pending migrations', $pendingCount),
				$pendingCount
			);
			/** @var InputfieldForm $form */
			$form = $this->wire()->modules->get('InputfieldForm');
			$f = $form->InputfieldSubmit;
			$f->attr('name', 'submit_apply');
			$f->icon = 'play';
			$f->val($label);
			$form->add($f);
			$out .= $form->render();
		}

		return $out;
	}

	/**
	 * Apply pending migrations and render output
	 *
	 * @return string
	 *
	 */
	protected function processApply() {
		/** @var AgentTools $at */
		$at = $this->wire('at');
		$migrationsDir = $at->getFilesPath('migrations');
		$migrationFiles = $this->getMigrationFiles($migrationsDir);

		$this->headline($this->_('Apply Migrations'));

		$pending = [];
		foreach($migrationFiles as $file) {
			if(!$at->migrations->isApplied($file)) $pending[] = $file;
		}

		if(empty($pending)) {
			$this->message($this->_('All migrations are already applied.'));
			$this->wire()->session->location('./');
			return '';
		}

		extract($this->wire()->fuel->getArray());

		$results = [];
		$passCount = 0;
		$failFile = null;

		foreach($pending as $file) {
			ob_start();
			try {
				include($file);
				$fileOutput = ob_get_clean();
				$at->migrations->addApplied($file);
				$passCount++;
				$results[] = [
					'file' => basename($file),
					'output' => $fileOutput,
					'success' => true,
				];
			} catch(\Throwable $e) {
				$fileOutput = ob_get_clean();
				$results[] = [
					'file' => basename($file),
					'output' => trim($fileOutput) .
						"\nERROR: " . $e->getMessage() .
						"\n  File: " . $e->getFile() . " line " . $e->getLine(),
					'success' => false,
				];
				$failFile = basename($file);
				break;
			}
		}

		$out = '';

		foreach($results as $result) {
			if($result['success']) {
				$icon = wireIconMarkup('check', 'fw');
				$headingClass = '';
			} else {
				$icon = wireIconMarkup('times', 'fw');
				$headingClass = " style='color: red;'";
			}
			$out .= "<h3$headingClass>$icon " . htmlspecialchars($result['file']) . "</h3>";
			if(strlen(trim($result['output']))) {
				$out .= "<pre class='notes' style='white-space: pre-wrap;'>" .
					htmlspecialchars(trim($result['output'])) .
					"</pre>";
			}
		}

		if($failFile) {
			$this->error(sprintf($this->_('Stopped at: %s'), $failFile));
			$remaining = count($pending) - $passCount - 1;
			if($remaining > 0) {
				$out .= "<p class='notes'>" .
					sprintf($this->_('%d migration(s) applied. %d remaining migration(s) were NOT applied.'), $passCount, $remaining) .
					"</p>";
			}
		} else {
			$this->message(sprintf(
				$this->_n('Applied %d migration.', 'Applied %d migrations.', $passCount),
				$passCount
			));
		}

		$out .= "<p><a href='./' class='ui-button ui-widget ui-corner-all'>" .
			wireIconMarkup('arrow-left') . ' ' .
			$this->_('Back to migration status') .
			"</a></p>";

		return $out;
	}

	/**
	 * Extract ISO-8601 datetime string from migration filename
	 *
	 * @param string $file Full path or basename of migration file
	 * @return string e.g. "2026-04-03 15:51:46" or empty string if not parseable
	 *
	 */
	protected function getMigrationDatetime($file) {
		$ts = substr(basename($file), 0, 14);
		if(!ctype_digit($ts)) return '';
		return substr($ts, 0, 4) . '-' . substr($ts, 4, 2) . '-' . substr($ts, 6, 2) . ' ' .
			substr($ts, 8, 2) . ':' . substr($ts, 10, 2) . ':' . substr($ts, 12, 2);
	}

	/**
	 * Get migration files sorted chronologically by timestamp prefix
	 *
	 * @param string $dir Path to migrations directory
	 * @return array Array of full file paths
	 *
	 */
	protected function getMigrationFiles($dir) {
		if(!is_dir($dir)) return [];
		$pattern = '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.php';
		$files = glob($dir . $pattern);
		if(!$files) return [];
		sort($files);
		return $files;
	}
}
