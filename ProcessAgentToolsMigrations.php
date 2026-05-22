<?php namespace ProcessWire;

/**
 * ProcessAgentTools Migrations Helper
 *
 */
class ProcessAgentToolsMigrations extends ProcessAgentToolsHelper {

	/**
	 * Migrations: show status table with apply button if pending
	 *
	 * @return string
	 *
	 */
	public function executeMigrations() {
		$input = $this->wire()->input;

		if($input->requestMethod('post')) {
			$this->wire()->session->CSRF()->validate();
		}

		if($input->post('submit_apply')) {
			return $this->processApply();
		}

		if($input->post('submit_apply_checked')) {
			return $this->processApplyChecked();
		}

		if($input->post('submit_export_checked')) {
			return $this->processExportChecked();
		}

		if($input->post('submit_delete_checked')) {
			$this->processDeleteChecked();
		}

		return $this->renderStatus();
	}

	/**
	 * View migration: display the PHP source of a single migration file
	 *
	 * @return string
	 *
	 */
	public function executeViewMigration() {

		$modules = $this->wire()->modules;
		$session = $this->wire()->session;
		$input = $this->wire()->input;
		$name = basename((string) $input->get('name'));

		// Validate: must match migration filename pattern
		if(!preg_match('/^\d{14}_[\w-]+\.php$/', $name)) {
			$session->error($this->_('Invalid migration name.'));
			$session->location('../migrations/');
		}

		$file = $this->at->getFilesPath('migrations') . $name;

		if(!is_file($file)) {
			$session->error($this->_('Migration file not found.'));
			$session->location('../migrations/');
		}

		$applied = $this->at->migrations->isApplied($file);
		$dateTime = $this->at->migrations->getDatetime($file);
		$title = $this->at->migrations->getTitle($file);

		$this->headline(sprintf($this->_('Migration: %s'), $title));
		$this->breadcrumb('../migrations/', $this->label('migrations'));

		// Handle apply POST
		if($input->post('submit_apply')) {
			$session->CSRF()->validate();
			return $this->runMigrationFiles([$file], '?name=' . urlencode($name));
		}

		// Handle export POST
		if($input->post('submit_export')) {
			$session->CSRF()->validate();
			$this->breadcrumb('../migrations/', $this->label('migrations'));
			$this->breadcrumb('?name=' . urlencode($name), $title);
			$this->headline($this->label('export'));
			return $this->renderBundleOutput([$file], '?name=' . urlencode($name));
		}

		$status = $this->label($applied ? 'applied' : 'pending');

		/** @var MarkupAdminDataTable $table */
		$table = $modules->get('MarkupAdminDataTable');
		$table->addClass('uk-margin-remove-bottom');
		$table->setEncodeEntities(false);
		$table->row([ $this->_('Status'), $status ]);
		$table->row([ $this->_('Date'), $dateTime ]);
		$table->row([ $this->_('File'), htmlspecialchars($file) ]);

		$form = $modules->get('InputfieldForm');
		$form->attr('method', 'post');
		$form->action('./?name=' . urlencode($name));

		$summary = $this->at->migrations->getSummary($file);
		if($summary) {
			$f = $form->InputfieldMarkup;
			$f->label = $this->_('Summary');
			$f->icon = 'commenting';
			$f->val($this->formatEngineerResponse($summary));
			$form->add($f);
		}

		$f = $form->InputfieldMarkup;
		$f->label = $this->_('Migration code');
		$f->icon = 'code';
		$f->val($this->pre(file_get_contents($file), [ 'wordWrap' => false ]));
		$form->add($f);

		$btn = $form->InputfieldButton;
		$btn->href = '../migrations/';
		$btn->icon = $this->iconName('back');
		$btn->val($this->label('back'));
		$btn->setSecondary();
		$form->add($btn);

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_apply');
		$f->showInHeader(true);
		$f->icon = $applied ? 'refresh' : $this->iconName('apply');
		$f->val($applied ?
			$this->_('Re-apply migration') :
			$this->_('Apply migration')
		);
		$form->add($f);

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_export');
		$f->icon = $this->iconName('export');
		$f->val($this->label('export'));
		$f->setSecondary();
		$form->add($f);

		return
			$table->render() .
			$form->render();
	}

	/**
	 * Render migration status table
	 *
	 * @return string
	 *
	 */
	protected function renderStatus() {
		$modules = $this->wire()->modules;

		$migrationsDir = $this->at->getFilesPath('migrations');
		$migrationFiles = $this->at->migrations->getFiles($migrationsDir);

		$this->headline($this->label('migrations'));

		if(empty($migrationFiles)) {
			$this->warning($this->_('No migration files found in:') . " `$migrationsDir`", Notice::allowMarkdown);
			/** @var InputfieldButton $button */
			$button = $modules->get('InputfieldButton');
			$button->href = '../engineer/?migration=1';
			$button->value = $this->label('ask-create-migration');
			$button->icon = 'commenting';
			return $button->render();
		}

		$pendingCount = 0;
		foreach($migrationFiles as $file) {
			if(!$this->at->migrations->isApplied($file)) $pendingCount++;
		}

		/** @var MarkupAdminDataTable $table */
		$table = $modules->get('MarkupAdminDataTable');
		$table->setEncodeEntities(false);
		$table->setColNotSortable(0);
		$table->headerRow([
			'',
			$this->label('migration'),
			$this->label('date-time'),
			$this->label('status'),
		]);

		foreach($migrationFiles as $file) {
			$applied = $this->at->migrations->isApplied($file);
			$status = $this->label($applied ? 'applied' : 'pending');
			$basename = basename($file);
			$viewUrl = $this->wire()->page->url . 'view-migration/?name=' . urlencode($basename);
			$checkbox = "<input type='checkbox' name='migrations[]' class='uk-checkbox migration-checkbox' value='" . htmlspecialchars($basename) . "'>";
			$table->row([
				$checkbox,
				"<a href='$viewUrl'>" . htmlspecialchars($this->at->migrations->getTitle($file)) . "</a>",
				$this->at->migrations->getDatetime($file),
				$status,
			]);
		}

		$appliedCount = count($migrationFiles) - $pendingCount;
		$this->message(sprintf($this->_('%d applied, %d pending'), $appliedCount, $pendingCount));

		// Pass confirmation message and overlay text to JS
		$settings = $this->wire()->config->js('AgentTools');
		$settings = array_merge($settings, [
			'confirmApply' => $this->_('Apply all pending migrations to this site? This cannot be undone.'),
			'confirmDelete' => $this->_('Are you sure you want to delete the checked migration files? This cannot be undone.'),
			'timeoutText' => $this->_('If you see a server error, reload the page before resubmitting — your changes may already have been applied.'),
		]);
		$this->wire()->config->js('AgentTools', $settings);

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');

		// Wrap table and action buttons in a single form so checkboxes are submitted
		$form->val($table->render());

		if($pendingCount > 0) {
			$label = sprintf(
				$this->_n('Apply %d pending migration', 'Apply %d pending migrations', $pendingCount),
				$pendingCount
			);
			/** @var InputfieldSubmit $f */
			$f = $modules->get('InputfieldSubmit');
			$f->attr('name', 'submit_apply');
			$f->attr('data-confirm', '1');
			$f->icon = $this->iconName('apply');
			$f->val($label);
			$form->add($f);
		}

		/** @var InputfieldSubmit $f */
		$f = $modules->get('InputfieldSubmit');
		$f->attr('name', 'submit_apply_checked');
		$f->attr('id', 'submit_apply_checked');
		$f->icon = 'check';
		$f->val($this->_('Apply checked'));
		$f->setSecondary();
		$f->attr('hidden', 'hidden');
		$form->add($f);

		/** @var InputfieldButton $f */
		$f = $modules->get('InputfieldButton');
		$f->attr('id', 'submit_review_checked');
		$f->icon = 'search';
		$f->val($this->_('Review checked'));
		$f->setSecondary();
		$f->attr('hidden', 'hidden');
		$form->add($f);

		/** @var InputfieldSubmit $f */
		$f = $modules->get('InputfieldSubmit');
		$f->attr('name', 'submit_export_checked');
		$f->attr('id', 'submit_export_checked');
		$f->icon = $this->iconName('export');
		$f->val($this->label('export-checked'));
		$f->setSecondary();
		$f->attr('hidden', 'hidden');
		$form->add($f);

		/** @var InputfieldSubmit $f */
		$f = $modules->get('InputfieldSubmit');
		$f->attr('name', 'submit_delete_checked');
		$f->attr('id', 'submit_delete_checked');
		$f->icon = $this->iconName('delete');
		$f->val($this->_('Delete checked'));
		$f->setSecondary();
		$f->attr('hidden', 'hidden');
		$form->add($f);

		/** @var InputfieldButton $btn */
		$btn = $modules->get('InputfieldButton');
		$btn->href = '../engineer/?migration=1';
		$btn->icon = $this->iconName('engineer');
		$btn->val($this->_('New migration'));
		$btn->showInHeader(true);
		$btn->setSecondary();
		$form->add($btn);

		/** @var InputfieldButton $btn */
		$btn = $modules->get('InputfieldButton');
		$btn->href = $this->wire()->page->url . 'import-migration/';
		$btn->icon = $this->iconName('import');
		$btn->val($this->label('import'));
		$btn->setSecondary();
		$form->add($btn);

		return $form->render();
	}

	/**
	 * Apply pending migrations and render output
	 *
	 * @return string
	 *
	 */
	protected function processApply() {
		$migrationsDir = $this->at->getFilesPath('migrations');
		$migrationFiles = $this->at->migrations->getFiles($migrationsDir);

		$this->headline($this->_('Apply Migrations'));

		$pending = [];
		foreach($migrationFiles as $file) {
			if(!$this->at->migrations->isApplied($file)) $pending[] = $file;
		}

		if(empty($pending)) {
			$session = $this->wire()->session;
			$session->message($this->_('All migrations are already applied.'));
			$session->location('./');
		}

		return $this->runMigrationFiles($pending, './');
	}

	/**
	 * Get and validate checked migration files from POST, returning full paths
	 *
	 * @return array|null Array of full file paths, or null if none checked
	 *
	 */
	protected function getCheckedMigrations(): ?array {
		$names = $this->wire()->input->post('migrations');
		if(empty($names) || !is_array($names)) return null;

		$migrationsDir = $this->at->getFilesPath('migrations');
		$files = [];

		foreach($names as $name) {
			$name = basename((string) $name);
			if(!preg_match('/^\d{14}_[\w-]+\.php$/', $name)) continue;
			$file = $migrationsDir . $name;
			if(is_file($file)) $files[] = $file;
		}

		return empty($files) ? null : $files;
	}

	/**
	 * Apply checked migrations (pending or already applied)
	 *
	 * @return string
	 *
	 */
	protected function processApplyChecked(): string {
		$this->headline($this->_('Apply checked migrations'));
		$this->breadcrumb('../migrations/', $this->label('migrations'));

		$checked = $this->getCheckedMigrations();
		if(!$checked) {
			$session = $this->wire()->session;
			$session->warning($this->_('No migrations were checked.'));
			$session->location('./');
		}

		// Sort chronologically by filename timestamp
		sort($checked);

		return $this->runMigrationFiles($checked, './');
	}

	/**
	 * Execute migration files and render results
	 *
	 * Shared by processApply(), processApplyChecked(), and the single-migration
	 * apply on the view migration screen.
	 *
	 * @param array $migrationFiles Full file paths to run, in order
	 * @param string $backUrl URL for the "back" button (relative or absolute)
	 * @return string
	 *
	 */
	protected function runMigrationFiles(array $migrationFiles, string $backUrl): string {
		extract($this->wire()->fuel->getArray()); // note: this overwrites $files, if used

		$results = [];
		$passCount = 0;
		$failFile = null;
		$lockFp = $this->at->migrations->lockApply();
		if($lockFp === false) {
			$this->error($this->_('Another migration apply process is already running.'));
			$btn = $this->wire()->modules->get('InputfieldButton');
			$btn->href = $backUrl;
			$btn->icon = 'arrow-left';
			$btn->val($this->_('Back'));
			$btn->setSecondary();
			return $btn->render();
		}

		try {
			foreach($migrationFiles as $file) {
				ob_start();
				try {
					include($file);
					$fileOutput = ob_get_clean();
					$this->at->migrations->addApplied($file);
					$passCount++;
					$results[] = [
						'file' => basename($file),
						'output' => $fileOutput,
						'success' => true
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

			// Regenerate site-maps so they reflect the changes for future Engineer requests
			if($passCount > 0) {
				try {
					$this->at->sitemap->generate();
					$this->at->sitemap->generateSchema();
				} catch(\Throwable $e) {
					// Non-fatal: migration applied successfully even if sitemap update fails
				}
			}
		} finally {
			$this->at->migrations->unlockApply($lockFp);
		}

		$out = '';

		foreach($results as $result) {
			if($result['success']) {
				$icon = wireIconMarkup($this->iconName('applied'), 'fw');
				$label = $this->label('applied');
			} else {
				$icon = wireIconMarkup($this->iconName('failed'), 'fw');
				$label = $this->label('failed');
			}
			$file = htmlspecialchars($result['file']);
			$out .= "<h3>$icon $file $label</h3>";
			if(strlen(trim($result['output']))) {
				$out .= $this->pre($result['output']);
			}
		}

		if($failFile) {
			$this->error(sprintf($this->_('Stopped at: %s'), $failFile));
			$remaining = count($migrationFiles) - $passCount - 1;
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
		$btn->href = $backUrl;
		$btn->icon = 'arrow-left';
		$btn->val($this->_('Back'));
		$btn->setSecondary();
		$out .= $btn->render();

		return $out;
	}

	/**
	 * Delete checked migration files and remove from applied registry
	 *
	 */
	protected function processDeleteChecked() {
		$session = $this->wire()->session;

		$checked = $this->getCheckedMigrations();
		if(!$checked) {
			$session->warning($this->_('No migrations were checked.'));
			$session->location('./');
		}

		$deleteCount = 0;
		foreach($checked as $file) {
			$this->at->migrations->removeApplied($file);
			if($this->wire()->files->unlink($file)) $deleteCount++;
		}

		$session->message(sprintf(
			$this->_n('Deleted %d migration file.', 'Deleted %d migration files.', $deleteCount),
			$deleteCount
		));

		$session->location('./');
	}

	/**
	 * Export checked migrations as a signed bundle and render a copyable textarea
	 *
	 * @return string
	 *
	 */
	protected function processExportChecked(): string {
		$this->headline($this->label('export'));
		$this->breadcrumb('../migrations/', $this->label('migrations'));

		$checked = $this->getCheckedMigrations();
		if(!$checked) {
			$session = $this->wire()->session;
			$session->warning($this->_('No migrations were checked.'));
			$session->location('./');
		}

		sort($checked); // chronological order by timestamp prefix
		return $this->renderBundleOutput($checked, './');
	}

	/**
	 * Render the export bundle textarea and back button
	 *
	 * @param array $files Full file paths to export
	 * @param string $backUrl URL for the back button
	 * @return string
	 *
	 */
	protected function renderBundleOutput(array $files, string $backUrl): string {
		$bundle = $this->at->migrations->exportBundle($files);
		$count = count($files);

		$this->message(sprintf(
			$this->_n('%d migration exported.', '%d migrations exported.', $count),
			$count
		));

		$modules = $this->wire()->modules;
		$form = $modules->get('InputfieldForm');

		$f = $form->InputfieldTextarea;
		$f->label = $this->_('Migration bundle');
		$f->description = $this->_('Copy this bundle and paste it into the Import form on the destination installation.');
		$f->val($bundle);
		$f->attr('rows', 8);
		$f->attr('readonly', 'readonly');
		$f->attr('onclick', 'this.select()');
		$f->attr('style', 'font-family: monospace; font-size: 0.8em; word-break: break-all;');
		$form->add($f);

		$btn = $modules->get('InputfieldButton');
		$btn->href = $backUrl;
		$btn->icon = $this->iconName('back');
		$btn->val($this->label('back'));
		$btn->setSecondary();
		$form->add($btn);

		return $form->render();
	}

	/**
	 * Import migration bundle: show form (GET) or process paste (POST)
	 *
	 * @return string
	 *
	 */
	public function executeImportMigration(): string {
		$this->headline($this->label('import'));
		$this->breadcrumb('../migrations/', $this->label('migrations'));

		$input = $this->wire()->input;

		if($input->requestMethod('post')) {
			$this->wire()->session->CSRF()->validate();
			if($input->post('submit_import')) {
				return $this->processImportMigration();
			}
		}

		return $this->renderImportForm();
	}

	/**
	 * Render the import bundle form
	 *
	 * @param string $prefill Optional bundle text to pre-fill the textarea
	 * @return string
	 *
	 */
	protected function renderImportForm(string $prefill = ''): string {
		$modules = $this->wire()->modules;

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->attr('method', 'post');

		$f = $form->InputfieldTextarea;
		$f->attr('name', 'migration_bundle');
		$f->label = $this->_('Migration bundle');
		$f->description = $this->_('Paste the migration bundle copied from the source installation.');
		$f->attr('rows', 8);
		$f->attr('style', 'font-family: monospace; font-size: 0.8em;');
		$f->val($prefill);
		$form->add($f);

		$f = $form->InputfieldSubmit;
		$f->attr('name', 'submit_import');
		$f->icon = $this->iconName('import');
		$f->val($this->label('import'));
		$form->add($f);

		return $form->render();
	}

	/**
	 * Process a pasted migration bundle: verify, save files, redirect to migrations list
	 *
	 * @return string
	 *
	 */
	protected function processImportMigration(): string {
		$bundle = trim((string) $this->wire()->input->post('migration_bundle'));

		if(!strlen($bundle)) {
			$this->error($this->_('Please paste a migration bundle.'));
			return $this->renderImportForm();
		}

		$result = $this->at->migrations->importBundle($bundle);

		if($result['error']) {
			$this->error($result['error']);
			return $this->renderImportForm($bundle);
		}

		$migrationsDir = $this->at->getFilesPath('migrations');
		if(!is_dir($migrationsDir)) $this->wire()->files->mkdir($migrationsDir);

		$saved = [];
		$skipped = [];

		foreach($result['migrations'] as $item) {
			$file = $migrationsDir . $item['filename'];
			if(is_file($file)) {
				$skipped[] = $item['filename'];
				continue;
			}
			$this->wire()->files->filePutContents($file, $item['content']);
			$saved[] = $item['filename'];
		}

		$session = $this->wire()->session;

		if(empty($saved) && !empty($skipped)) {
			$session->warning($this->_('All migrations in the bundle already exist — nothing new was imported.'));
			$session->location('../migrations/');
		}

		foreach($saved as $name) {
			$session->message(sprintf($this->_('Imported: %s'), $name));
		}
		foreach($skipped as $name) {
			$session->warning(sprintf($this->_('Already exists (skipped): %s'), $name));
		}

		$session->location('../migrations/');
		return ''; // unreachable; session->location() exits
	}

}
