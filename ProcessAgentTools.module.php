<?php namespace ProcessWire;

/**
 * ProcessAgentTools
 *
 * Admin UI for AgentTools: view/apply migrations and ask the Engineer
 * (AI assistant) questions about or to make changes to the site.
 *
 * Copyright 2026 Ryan Cramer and Claude (Anthropic) | MIT
 *
 */
class ProcessAgentTools extends Process {

	public static function getModuleInfo() {
		return [
			'title' => 'Agent Tools',
			'summary' => 'Admin interface for AgentTools migrations and AI engineer.',
			'version' => 2,
			'author' => 'Claude (Anthropic) and Ryan Cramer',
			'icon' => 'asterisk',
			'requires' => 'AgentTools',
			'page' => [
				'name' => 'agent-tools',
				'parent' => 'setup',
				'title' => 'Agent Tools',
			],
			'useNavJSON' => true,
			'nav' => [
				['url' => 'migrations/', 'label' => 'Migrations'],
				['url' => 'engineer/', 'label' => 'Engineer'],
			],
		];
	}

	/**
	 * Require superuser for all actions
	 *
	 */
	public function init() {
		if(!$this->wire()->user->isSuperuser()) throw new WirePermissionException("Superuser is required");
		parent::init();
	}

	/**
	 * Landing page: links to Migrations and Engineer
	 *
	 * @return string
	 *
	 */
	public function ___execute() {
		$this->headline($this->_('Agent Tools'));
		$modules = $this->wire()->modules;
		$adminUrl = $this->wire()->page->url;

		/** @var InputfieldButton $btn */
		$btn = $modules->get('InputfieldButton');
		$btn->href = $adminUrl . 'migrations/';
		$btn->icon = 'database';
		$btn->val($this->_('Migrations'));
		$out = $btn->render();

		$btn = $modules->get('InputfieldButton');
		$btn->href = $adminUrl . 'engineer/';
		$btn->icon = 'commenting';
		$btn->val($this->_('Engineer'));
		$out .= $btn->render();

		return $out;
	}

	/**
	 * Migrations: show status table with apply button if pending
	 *
	 * @return string
	 *
	 */
	public function ___executeMigrations() {
		$input = $this->wire()->input;
		$csrf = $this->wire()->session->CSRF();

		if($input->post('submit_apply')) {
			$csrf->validate();
			return $this->processApply();
		}

		return $this->renderStatus();
	}

	/**
	 * Engineer: AI assistant for informational queries and site changes
	 *
	 * @return string
	 *
	 */
	public function ___executeEngineer() {
		$this->headline($this->_('Agent Tools: Engineer'));
		$input = $this->wire()->input;
		$csrf = $this->wire()->session->CSRF();

		if($input->post('submit_engineer')) {
			$csrf->validate();
			return $this->processEngineerRequest();
		}

		return $this->renderEngineerForm();
	}

	/**
	 * Render the Engineer request form
	 *
	 * @param string $prefill Optional text to pre-fill the textarea
	 * @return string
	 *
	 */
	protected function renderEngineerForm(string $prefill = ''): string {
		/** @var AgentTools $at */
		$at = $this->wire('at');
		$apiKey = (string) $at->get('engineer_api_key');

		if(!$apiKey) {
			$settingsUrl = $this->wire()->config->urls->admin . 'module/edit?name=AgentTools';
			$this->error(sprintf(
				$this->_('An API key is required. Please configure it in [AgentTools settings](%s).'),
				$settingsUrl
			), Notice::allowMarkdown);
			return '';
		}

		/** @var InputfieldForm $form */
		$form = $this->wire()->modules->get('InputfieldForm');
		$form->attr('method', 'post');

		/** @var InputfieldTextarea $f */
		$f = $this->wire()->modules->get('InputfieldTextarea');
		$f->attr('name', 'engineer_request');
		$f->label = $this->_('Ask the Engineer');
		$f->description = $this->_('Ask a question about your site, or request a change. Changes are saved as migration files for your review before being applied.');
		$f->attr('rows', 5);
		$f->val($prefill);
		$form->add($f);

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_engineer');
		$f->icon = 'send';
		$f->val($this->_('Send'));
		$form->add($f);

		return $form->render();
	}

	/**
	 * Process an Engineer request and render the response
	 *
	 * @return string
	 *
	 */
	protected function processEngineerRequest(): string {
		$request = trim((string) $this->wire()->input->post('engineer_request'));

		if(!strlen($request)) {
			$this->error($this->_('Please enter a request.'));
			return $this->renderEngineerForm();
		}

		/** @var AgentTools $at */
		$at = $this->wire('at');
		$result = $at->engineer->ask($request);

		$out = '';

		if($result['error']) {
			$this->error($result['error']);
		}

		if($result['response']) {
			$md = $this->wire()->modules->get('TextformatterMarkdownExtra');
			if($md) {
				$out .= $md->markdown($result['response']);
			} else {
				$out .= "<p>" . nl2br(htmlspecialchars($result['response'])) . "</p>";
			}
		}

		$modules = $this->wire()->modules;
		$adminUrl = $this->wire()->page->url;

		if($result['migration']) {
			$filename = basename($result['migration']);
			$this->message(sprintf($this->_('Migration saved: %s'), $filename));
			/** @var InputfieldButton $btn */
			$btn = $modules->get('InputfieldButton');
			$btn->href = $adminUrl . 'migrations/';
			$btn->icon = 'database';
			$btn->val($this->_('Review and apply migration'));
			$out .= $btn->render();
		}

		/** @var InputfieldButton $btn */
		$btn = $modules->get('InputfieldButton');
		$btn->href = $adminUrl . 'engineer/';
		$btn->icon = 'arrow-left';
		$btn->val($this->_('Ask another question'));
		if($result['migration']) $btn->setSecondary();
		$out .= $btn->render();

		return $out;
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
			$this->warning($this->_('No migration files found in:') . " `$migrationsDir`", Notice::allowMarkdown); 
			/** @var InputfieldButton $button */
			$button = $this->wire()->modules->get('InputfieldButton');
			$button->href = '../engineer/?migration=1';
			$button->value = $this->_('Ask the engineer to create a migration'); 
			$button->icon = 'commenting';
			return $button->render();
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
		$this->message(sprintf($this->_('%d applied, %d pending'), $appliedCount, $pendingCount));

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
				$this->warning(sprintf(
					$this->_('%d migration(s) applied. %d remaining migration(s) were NOT applied.'),
					$passCount,
					$remaining
				));
			}
		} else {
			$this->message(sprintf(
				$this->_n('Applied %d migration.', 'Applied %d migrations.', $passCount),
				$passCount
			));
		}

		/** @var InputfieldButton $btn */
		$btn = $this->wire()->modules->get('InputfieldButton');
		$btn->href = './';
		$btn->icon = 'arrow-left';
		$btn->val($this->_('Back to migration status'));
		$btn->setSecondary();
		$out .= $btn->render();

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
