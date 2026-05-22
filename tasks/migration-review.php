<?php namespace ProcessWire;

$migrationOptions = [];
$at = wire('at'); /** @var AgentTools $at */
$migrationFiles = $at->migrations->getFiles($at->getFilesPath('migrations'));
foreach($migrationFiles as $file) {
	if($at->migrations->isApplied($file)) continue;
	$basename = basename($file);
	$migrationOptions[$basename] = $at->migrations->getTitle($file);
}

return [
	'name' => 'migration-review',
	'title' => 'Migration review',
	'summary' => 'Review pending AgentTools migrations before they are applied.',
	'description' => 'Checks pending migrations for safety, ordering, reversibility concerns, and likely runtime problems.',
	'icon' => 'database',
	'mode' => 'review',
	'scheduleable' => 0,
	'inputs' => [
		'migrations' => [
			'type' => 'AsmSelect',
			'label' => 'Pending migrations to review',
			'description' => 'Select one or more pending migrations to review.',
			'notes' => 'If none are selected, the task reviews the first pending migrations up to the maximum below.',
			'options' => $migrationOptions,
		],
		'include_applied_context' => [
			'type' => 'checkbox',
			'label' => 'Include applied migration history',
			'description' => 'Give the agent the list of migrations already marked as applied, for context. It will not inspect every applied migration file.',
			'value' => 1,
		],
		'max_files' => [
			'type' => 'integer',
			'label' => 'Maximum pending migrations to review',
			'min' => 1,
			'max' => 50,
			'value' => 20,
			'showIf' => 'migrations.count=0',
		],
	],
	'prompt' => <<<'TEXT'
Review selected AgentTools migration file(s) in site/assets/at/migrations before they are applied. Selected migrations: {migrations}. If no migrations are selected, review exactly the first {max_files} pending migration file(s). Compare with applied migration history: {include_applied_context}.

This is a code-review style task. Do not apply migrations. Do not try to fully prove every site dependency before reporting.

Try to complete this task with one or two eval_php calls:
1. Use $at->migrations APIs to list migration files and applied status.
2. If selected migrations are provided, review only those selected file(s). If no selected migrations are provided, select only the first {max_files} pending file(s) for code review. You may mention if more pending migrations exist, but do not review their contents.
3. Read only those selected pending migration file contents directly in that same eval_php call with file_get_contents().
4. If include_applied_context is enabled, include the applied migration names/status only as context; do not inspect every applied file.

Only call site_info, api_docs, or read_file if a specific migration line raises a concrete question that cannot be reviewed from the migration code itself. If more investigation is needed, list it as a follow-up rather than continuing to call tools.

Return:
- pending migration summary
- ordering/dependency concerns
- likely runtime errors or missing guards
- data-loss or destructive-operation risks
- idempotency and rollback concerns
- recommended changes before applying

If everything looks safe, say so clearly and mention any remaining assumptions.
TEXT
];
