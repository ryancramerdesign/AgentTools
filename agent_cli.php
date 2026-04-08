<?php namespace ProcessWire;

/***************************************************************************
 * Agent CLI file
 *
 * This file is for AI coding agents (e.g. Claude Code) to edit.
 * Write code after the AGENT marker below. All PW API variables are available.
 *
 */

if(!defined("PROCESSWIRE")) die();
if(PHP_SAPI !== 'cli') die('CLI required');
error_reporting(E_ALL);
ini_set('display_errors', 1);

/** @var AdminTheme|AdminThemeFramework|AdminThemeUikit|null $adminTheme */
/** @var WireCache $cache */
/** @var WireClassLoader $classLoader */
/** @var Config $config */
/** @var WireDatabasePDO $database */
/** @var WireDateTime $datetime */
/** @var Fieldgroups $fieldgroups */
/** @var Fields $fields */
/** @var Fieldtypes $fieldtypes */
/** @var WireFileTools $files */
/** @var Fuel $fuel */
/** @var WireHooks $hooks */
/** @var WireInput $input */
/** @var Languages|null $languages (null if LanguageSupport not installed) */
/** @var WireLog $log */
/** @var WireMailTools $mail */
/** @var Modules $modules */
/** @var Notices $notices */
/** @var Page $page */
/** @var Pages $pages */
/** @var Permissions $permissions */
/** @var Process|ProcessPageView $process */
/** @var WireProfilerInterface|null $profiler (null if ProfilerPro not installed) */
/** @var Roles $roles */
/** @var Sanitizer $sanitizer */
/** @var Session $session */
/** @var Templates $templates */
/** @var Paths $urls */
/** @var User $user */
/** @var Users $users */
/** @var ProcessWire $wire */
/** @var WireShutdown $shutdown */
/** @var PagesVersions|null $pagesVersions (null if PagesVersions not installed) */
/** @var AgentTools $at */

$config->debug = 'dev';
$config->advanced = true;
echo "AgentTools CLI ready\n";

// Agent may modify anything after the comment below:
/* ~~~ AGENT ~~~ */
