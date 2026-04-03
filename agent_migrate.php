<?php namespace ProcessWire;

/***************************************************************************
 * Agent Migrate — ProcessWire migration runner
 *
 * Discovers and applies pending migration files.
 * Safe to run multiple times — already-applied migrations are skipped.
 *
 * Usage (from the ProcessWire root directory):
 *   php index.php --at-migrations-apply   — apply all pending migrations
 *   php index.php --at-migrations-list    — list migrations and their status
 *   php index.php --at-migrations-test    — show pending migrations without applying
 *
 * Migration files live in: site/assets/at/migrations/
 * Applied migrations are tracked in the AgentTools module configuration (database).
 *
 */
if(!defined("PROCESSWIRE")) die();

/** @var AgentTools $at */
/** @var string $atAction Action name: 'list', 'apply' or 'test' */

// ----------------------------------------------------------------
// Parse arguments
// ----------------------------------------------------------------

$listOnly = $atAction === 'list';
$dryRun = $atAction === 'test';
$apply = $atAction === 'apply';

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
// Categorize migrations
// ----------------------------------------------------------------

$pending = [];

foreach($migrationFiles as $file) {
	if(!$at->isMigrationApplied($file)) $pending[] = $file;
}

// ----------------------------------------------------------------
// --list mode: show status of all migrations and exit
// ----------------------------------------------------------------

if($listOnly) {
	echo "\nMigration status\n";
	echo str_repeat('-', 60) . "\n";
	foreach($migrationFiles as $file) {
		$status = $at->isMigrationApplied($file) ? '[applied]' : '[pending]';
		echo "  $status  " . basename($file) . "\n";
	}
	echo "\n" . (count($migrationFiles) - count($pending)) . " applied, " . count($pending) . " pending.\n\n";
	return 1;
}

// ----------------------------------------------------------------
// Report pending (--apply or --dry-run)
// ----------------------------------------------------------------

echo "\nAgent Migrate\n";
echo str_repeat('=', 60) . "\n";

if(empty($pending)) {
	echo "All migrations have already been applied.\n\n";
	return true;
}

echo count($pending) . " pending migration(s):\n";
foreach($pending as $file) {
	echo "  - " . basename($file) . "\n";
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

$passCount = 0;
$failFile = null;

foreach($pending as $file) {
	echo str_repeat('-', 60) . "\n";

	ob_start();
	try {
		include($file);
		$output = ob_get_clean();
		if(strlen(trim($output))) echo $output;

		$at->addAppliedMigration($file);
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
