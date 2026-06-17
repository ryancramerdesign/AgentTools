<?php namespace ProcessWire;

/***************************************************************************
 * Agent Migrate — ProcessWire migration runner
 *
 * Discovers and applies pending migration files.
 * Safe to run multiple times — already-applied migrations are skipped.
 *
 * Usage (from the ProcessWire root directory):
 *   php index.php --at-migrations-apply [--file=FILE|--name=NAME] [--limit=N] [--dry-run] [--force]
 *   php index.php --at-migrations-list [--file=FILE|--name=NAME]
 *   php index.php --at-migrations-test [--file=FILE|--name=NAME] [--limit=N]
 *   php index.php --at-migrations-rerun --file=FILE|--name=NAME [--dry-run]
 *
 * Migration files live in: site/assets/at/migrations/
 * Applied migrations are tracked in the AgentTools module configuration (database).
 *
 */
if(!defined("PROCESSWIRE")) die();

/** @var AgentTools $at */
/** @var string $atAction Action name: 'list', 'apply', 'test' or 'rerun' */

// ----------------------------------------------------------------
// Parse arguments
// ----------------------------------------------------------------

$listOnly = $atAction === 'list';
$dryRun = $atAction === 'test';
$rerun = $atAction === 'rerun';
$apply = $atAction === 'apply' || $rerun;
$options = [
	'file' => '',
	'name' => '',
	'limit' => 0,
	'force' => false,
	'dryRun' => false,
	'help' => false,
];
$argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
$args = array_slice($argv, 2);
$optionError = '';

for($n = 0; $n < count($args); $n++) {
	$arg = (string) $args[$n];
	if($arg === '--help' || $arg === '-h') {
		$options['help'] = true;
		continue;
	}
	if($arg === '--force') {
		$options['force'] = true;
		continue;
	}
	if($arg === '--dry-run') {
		$options['dryRun'] = true;
		continue;
	}
	if(strpos($arg, '--dry-run=') === 0) {
		$value = strtolower(substr($arg, 10));
		$options['dryRun'] = !in_array($value, ['', '0', 'false', 'no', 'off'], true);
		continue;
	}
	if($arg === '--file' || $arg === '--name' || $arg === '--limit') {
		if(!isset($args[$n + 1]) || strpos((string) $args[$n + 1], '--') === 0) {
			$optionError = "Missing value for $arg.";
			break;
		}
		$options[substr($arg, 2)] = (string) $args[++$n];
		continue;
	}
	if(strpos($arg, '--file=') === 0) {
		$options['file'] = substr($arg, 7);
		continue;
	}
	if(strpos($arg, '--name=') === 0) {
		$options['name'] = substr($arg, 7);
		continue;
	}
	if(strpos($arg, '--limit=') === 0) {
		$options['limit'] = substr($arg, 8);
		continue;
	}
	$optionError = "Unknown migration option: $arg";
	break;
}

if($options['help']) {
	echo $at->renderHelp($at->migrations->cliHelp(), 'Migrations usage');
	return true;
}

if($optionError !== '') {
	echo "ERROR: $optionError\n\n";
	echo $at->renderHelp($at->migrations->cliHelp(), 'Migrations usage');
	return false;
}

$options['file'] = trim((string) $options['file']);
$options['name'] = trim((string) $options['name']);
$hasSelector = $options['file'] !== '' || $options['name'] !== '';
$force = $options['force'] || $rerun;
$dryRun = $dryRun || $options['dryRun'];
if($dryRun) $apply = false;

if($options['file'] !== '' && $options['name'] !== '') {
	echo "ERROR: Use either --file or --name, not both.\n\n";
	return false;
}

if($force && !$hasSelector) {
	echo "ERROR: --force and --at-migrations-rerun require --file=FILE or --name=NAME.\n\n";
	return false;
}

if($options['limit'] !== '' && $options['limit'] !== 0) {
	if(!ctype_digit((string) $options['limit']) || (int) $options['limit'] < 1) {
		echo "ERROR: --limit must be a positive integer.\n\n";
		return false;
	}
	$options['limit'] = (int) $options['limit'];
}

// ----------------------------------------------------------------
// Locate migration files
// ----------------------------------------------------------------

$migrationsDir = $at->getFilesPath('migrations');

if(!is_dir($migrationsDir)) {
	echo "Migrations directory does not exist: $migrationsDir\n";
	return 0;
}

$migrationFiles = glob($migrationsDir . '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.php');

if(empty($migrationFiles)) {
	echo "No migration files found in $migrationsDir\n";
	return 1;
}

sort($migrationFiles); // timestamp prefix ensures chronological order

// ----------------------------------------------------------------
// Filter selected migration files
// ----------------------------------------------------------------

$selectedFiles = $migrationFiles;

if($options['file'] !== '') {
	$fileName = basename($options['file']);
	$selectedFiles = array_values(array_filter($migrationFiles, function($file) use($fileName) {
		return basename($file) === $fileName;
	}));
	if(empty($selectedFiles)) {
		echo "ERROR: Migration file not found: $fileName\n\n";
		return false;
	}
} else if($options['name'] !== '') {
	$name = strtolower($options['name']);
	$name = preg_replace('/\.php$/', '', $name);
	$name = str_replace('_', '-', $name);
	$selectedFiles = [];
	foreach($migrationFiles as $file) {
		$base = strtolower(basename($file, '.php'));
		$migrationName = strtolower(str_replace('_', '-', $at->migrations->getName($file)));
		if($base === $name || $migrationName === $name || strpos($base, $name) !== false || strpos($migrationName, $name) !== false) {
			$selectedFiles[] = $file;
		}
	}
	if(empty($selectedFiles)) {
		echo "ERROR: Migration name not found: {$options['name']}\n\n";
		return false;
	}
	if(count($selectedFiles) > 1) {
		echo "ERROR: Migration name is ambiguous: {$options['name']}\n";
		foreach($selectedFiles as $file) echo "  - " . basename($file) . "\n";
		echo "\nUse --file=FILE to select one exact migration.\n\n";
		return false;
	}
}

// ----------------------------------------------------------------
// Categorize migrations
// ----------------------------------------------------------------

$pending = [];

foreach($selectedFiles as $file) {
	if(!$at->migrations->isApplied($file)) $pending[] = $file;
}

$runnable = $force ? $selectedFiles : $pending;
if($options['limit']) $runnable = array_slice($runnable, 0, (int) $options['limit']);

// ----------------------------------------------------------------
// --list mode: show status of all migrations and exit
// ----------------------------------------------------------------

if($listOnly) {
	echo "\nMigration status\n";
	echo str_repeat('-', 60) . "\n";
	foreach($selectedFiles as $file) {
		$status = $at->migrations->isApplied($file) ? '[applied]' : '[pending]';
		echo "  $status  " . basename($file) . "\n";
	}
	echo "\n" . (count($selectedFiles) - count($pending)) . " applied, " . count($pending) . " pending.\n\n";
	return 1;
}

// ----------------------------------------------------------------
// Report pending (--apply or --dry-run)
// ----------------------------------------------------------------

echo "\nAgent Migrate\n";
echo str_repeat('=', 60) . "\n";

if(empty($runnable)) {
	echo $hasSelector ?
		"Selected migration has already been applied. Use --force or --at-migrations-rerun to run it again.\n\n" :
		"All migrations have already been applied.\n\n";
	return true;
}

$modeLabel = $force ? "selected migration(s)" : "pending migration(s)";
echo count($runnable) . " $modeLabel:\n";
foreach($runnable as $file) {
	$status = $at->migrations->isApplied($file) ? 'applied' : 'pending';
	echo "  - " . basename($file) . "\n";
	if($force) echo "    status: $status, force enabled\n";
}
echo "\n";

if($dryRun) {
	echo "test/dryrun: no migrations applied.\n\n";
	return 1;
}

if(!$apply) {
	return 1;
}

// ----------------------------------------------------------------
// Apply pending migrations
// ----------------------------------------------------------------

$lockFp = $at->migrations->lockApply();
if($lockFp === false) {
	echo "Another migration apply process is already running.\n\n";
	return 0;
}

$passCount = 0;
$failFile = null;

try {
	foreach($runnable as $file) {
		echo str_repeat('-', 60) . "\n";

		ob_start();
		try {
			include($file);
			$output = ob_get_clean();
			if(strlen(trim($output))) echo $output;

			$at->migrations->addApplied($file);
			$passCount++;

		} catch(\Throwable $e) {
			$output = ob_get_clean();
			if(strlen(trim($output))) echo $output;
			echo "- ERROR: " . $e->getMessage() . "\n";
			echo "  File: " . $e->getFile() . " line " . $e->getLine() . "\n";
			$failFile = basename($file);
			break;
		}
	}
} finally {
	$at->migrations->unlockApply($lockFp);
}

// ----------------------------------------------------------------
// Summary
// ----------------------------------------------------------------

echo str_repeat('=', 60) . "\n";

if($failFile) {
	echo "Stopped at: $failFile\n";
	echo "Applied: $passCount migration(s). Remaining migrations were NOT applied.\n\n";
	return 0;
} else {
	echo "Applied: $passCount migration(s). All up to date.\n\n";
	return 1;
}
