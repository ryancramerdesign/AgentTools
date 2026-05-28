<?php namespace ProcessWire;

$languageOptions = [];
$languages = wire('languages');
if($languages) {
	foreach($languages as $language) {
		/** @var Language $language */
		if($language->isDefault()) continue;
		$label = trim($language->title);
		if($label === '') $label = $language->name;
		$languageOptions[$language->name] = "$label ($language->name)";
	}
}
$defaultLanguage = count($languageOptions) ? array_key_first($languageOptions) : '';

$sourceOptions = [
	'site/templates' => 'site/templates',
	'site/modules' => 'site/modules',
];
$siteModulesPath = wire()->config->paths->siteModules;
if(is_dir($siteModulesPath)) {
	foreach(new \DirectoryIterator($siteModulesPath) as $dir) {
		if($dir->isDot() || !$dir->isDir()) continue;
		$name = $dir->getBasename();
		if(strpos($name, '.') === 0 || strpos($name, '-') === 0) continue;
		$modulePath = $dir->getPathname();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($modulePath, \FilesystemIterator::SKIP_DOTS)
		);
		foreach($iterator as $file) {
			/** @var \SplFileInfo $file */
			if(!$file->isFile()) continue;
			if(!in_array($file->getExtension(), [ 'php', 'module', 'inc' ], true)) continue;
			$source = "site/modules/$name";
			$sourceOptions[$source] = $source;
			break;
		}
	}
}
$sourceOptions += [
	'site' => 'site',
	'wire/modules' => 'wire/modules',
	'wire' => 'wire',
];

ksort($sourceOptions);

return [
	'name' => 'static-phrase-translation',
	'title' => 'Static phrase translation',
	'summary' => 'Translate PHP file phrases wrapped in ProcessWire translation functions.',
	'description' => 'Exports static translation phrases with LanguagePorter, translates them, and optionally imports them for a selected language.',
	'icon' => 'language',
	'mode' => 'review_then_fix',
	'scheduleable' => 0,
	'maxIterations' => 50,
	'requires' => [
		'processWireVersion' => '3.0.264',
		'languages' => true,
	],
	'readOnlyWhen' => [
		'translation_action' => 'review',
	],
	'inputs' => [
		'target_language' => [
			'type' => 'select',
			'label' => 'Target language',
			'description' => 'Choose the installed language that should receive the translations.',
			'options' => $languageOptions,
			'value' => $defaultLanguage,
			'required' => true,
		],
		'source' => [
			'type' => 'select',
			'label' => 'Source directory',
			'description' => 'Choose the root-relative directory to scan for translation function calls.',
			'notes' => 'Choose the narrowest source that matches the translation goal. Broad sources like site or wire may produce large CSV exports.',
			'options' => $sourceOptions,
			'value' => 'site/templates',
			'required' => true,
		],
		'translation_action' => [
			'type' => 'radios',
			'label' => 'Action',
			'options' => [
				'review' => 'Review phrases only',
				'translate_import' => 'Translate and import',
			],
			'value' => 'review',
			'optionColumns' => 1,
		],
		'max_phrases' => [
			'type' => 'integer',
			'label' => 'Maximum phrases to translate',
			'description' => 'Limit each translate/import run to this many untranslated phrases. Run the task again to continue larger translation jobs.',
			'notes' => 'Smaller batches are more reliable for preserving CSV structure, placeholders, and context-specific translations.',
			'min' => 1,
			'max' => 250,
			'value' => 50,
			'showIf' => 'translation_action=translate_import',
		],
		'translation_guidance' => [
			'type' => 'textarea',
			'label' => 'Translation guidance',
			'description' => 'Optional tone, locale, terminology, or brand guidance for the translation.',
			'notes' => 'Example: Use formal German. Preserve product names. Prefer concise UI labels.',
			'rows' => 4,
			'collapsed' => Inputfield::collapsedBlank,
		],
	],
	'prompt' => <<<'TEXT'
Translate ProcessWire static phrases for target language "{target_language}" from source directory "{source}". Action: {translation_action}. Maximum phrases to translate/import in this run: {max_phrases}. Translation guidance: {translation_guidance}.

This task is only for static PHP file phrases wrapped in ProcessWire translation functions such as __(), _x(), _n(), $this->_(), and related APIs. It is not for translating multi-language page fields or page content.

Use the ProcessWire LanguageSupport API. If needed, retrieve the LanguageSupport API docs and read the "Creating and managing language translations" section, especially the LanguagePorter export/import examples.

For review actions, use LanguagePorter with scope "all" so all eligible phrases in the selected source are discovered:

$language = $languages->getLanguage('{target_language}');
$porter = $language->porter;
$csv = $porter->exportCsv([
	'source' => '{source}',
	'scope' => 'all',
	'exportTo' => 'string',
]);
$exportInfo = $porter->getLastExportInfo();

For translate_import actions, do not use the full export above. Export only the next batch of untranslated phrases, exactly like this:

$csv = $porter->exportCsv([
	'source' => '{source}',
	'scope' => 'all',
	'exportTo' => 'string',
	'include' => 'untranslated',
	'limit' => {max_phrases},
]);
$exportInfo = $porter->getLastExportInfo();

If the target language cannot be found, or if LanguageSupport/LanguagePorter is not available, stop and report that requirement clearly. If the exported CSV is too large to handle safely in this single request, stop and recommend a narrower source directory or smaller maximum phrase count rather than continuing to call tools.

The CSV columns are original, translated, description, file, and hash, though the header uses language names. Preserve the CSV structure, row order, file values, hash values, descriptions, line endings, and quoting rules. Translate only human-facing text in the original phrase into the target language. Preserve placeholders, sprintf tokens, named tokens, HTML tags, entities, URLs, email addresses, code fragments, ProcessWire selectors, product names, and variable-looking text exactly unless the guidance explicitly says otherwise. Keep plural/context meaning intact for _n() and _x() style phrases. If a phrase should intentionally remain identical to the default/English phrase, enter "=" as the translated value to tell ProcessWire that no translation is necessary.

If action is "review", do not import anything and do not modify files or database content. Export the CSV, use $porter->getLastExportInfo() for exact total/translated/untranslated/exported counts, summarize phrase counts by file when practical, identify ambiguous or risky phrases, note placeholder/markup patterns that need care, and provide concise recommendations for translation.

If action is "translate_import", export only untranslated rows using include "untranslated" and limit {max_phrases}. Do not export or translate the full source CSV in translate_import mode. Translate every data row in the filtered CSV, and no rows beyond that filtered CSV. Preserve existing non-empty translated values by default unless the user explicitly asks you to revise existing translations. Do not retranslate or overwrite existing translations just for stylistic changes.

For translate_import, do not attempt to translate more than the rows present in the filtered CSV. Create an import CSV containing the original header row and the translated rows, preserving each row's original file, hash, description, and quoting. Import that CSV with $porter->importCsvStr($translatedCsv) or $porter->importCsv($translatedCsv). Use eval_php for the export and import operations. Do not save CSV files unless there is a specific need.

After import, do not estimate the remaining untranslated count. Use eval_php to count remaining untranslated rows with an export using include "untranslated" and limit 0, then report that exact count:

$remainingCsv = $porter->exportCsv([
	'source' => '{source}',
	'scope' => 'all',
	'exportTo' => 'string',
	'include' => 'untranslated',
	'limit' => 0,
]);
$remainingInfo = $porter->getLastExportInfo();

Report rows processed, rows changed, and the exact remaining untranslated row count from $remainingInfo['untranslated'] so the user knows whether to run the task again. Do not invent or estimate counts.

Try to complete the task with one or two eval_php calls. Do not keep calling tools after you have enough information for a useful review or import.
TEXT
];
