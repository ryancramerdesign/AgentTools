<?php namespace ProcessWire;

$logOptions = [];
$log = wire()->log;
$datetime = wire()->datetime;
foreach($log->getLogs(true) as $name => $info) {
	$size = isset($info['size']) ? wireBytesStr((int) $info['size']) : '';
	$modified = !empty($info['modified']) ? $datetime->date('Y-m-d H:i', (int) $info['modified']) : '';
	$label = "$modified - " . ucfirst($name) .  ($size ? " ($size)" : '');
	$logOptions[$name] = $label;
}

return [
	'name' => 'log-review',
	'title' => 'Recent log review',
	'summary' => 'Scan ProcessWire logs for recent errors, suspicious activity, and operational issues.',
	'description' => 'Reviews recent entries in site/assets/logs and summarizes issues that may need attention.',
	'icon' => 'file-text-o',
	'mode' => 'review',
	'scheduleable' => 1,
	'inputs' => [
		'logs' => [
			'type' => 'AsmSelect',
			'label' => 'Logs to review',
			'description' => 'Select one or more logs to review. If none are selected, the agent reviews recently modified logs.',
			'options' => $logOptions,
			'style' => "font-family:monospace"
		],
		'days' => [
			'type' => 'select',
			'label' => 'Days to review',
			'options' => [
				1 => '1 day',
				2 => '2 days',
				3 => '3 days',
				7 => '7 days',
			],
			'value' => 1,
		],
		'include_examples' => [
			'type' => 'checkbox',
			'label' => 'Include example log entries',
			'description' => 'Include a few representative log entries for each notable finding. Disable for a shorter summary.',
			'value' => 1,
		],
	],
	'prompt' => <<<'TEXT'
Review this ProcessWire site's logs for the last {days} day(s). Selected logs: {logs}. Include example log entries: {include_examples}.

Use the ProcessWire $log API variable rather than manually reading files. If present, the $log API is documented in wire/core/Log/API.md. On older ProcessWire versions where that file is not present, inspect wire/core/WireLog.php and, if needed, wire/core/FileLog.php for available methods. In eval_php, use $log->getLogs(true) to discover available logs, and use $log->getEntries($name, [ 'dateFrom' => strtotime('-{days} days'), 'limit' => 500, 'reverse' => true ]) to read recent structured entries.

If selected logs are provided, review only those log names. If no logs are selected, focus on logs modified within or near the requested date range before reading older logs.

Try to complete this task with one or two eval_php calls:
1. Discover available logs and summarize counts/sizes/modified times for the selected or recently modified logs.
2. Read recent entries from the selected or most relevant logs and aggregate patterns.

Look for errors, warnings, repeated 404s, authentication/login patterns that may indicate dictionary attacks, database connection issues, image/file processing failures, mail errors, module errors, and unusual bursts of repeated messages.

When using eval_php, avoid declaring named helper functions because eval_php may be called more than once in the same ProcessWire namespace. Use inline code, closures assigned to variables, or uniquely prefixed classless logic instead.

Return only an administrator-friendly report with:
- executive summary
- notable findings grouped by severity
- likely cause when inferable
- recommended next action
- any patterns that should be monitored

Do not modify files or database content. Do not keep calling tools after you have enough information for a useful report.
TEXT
];
