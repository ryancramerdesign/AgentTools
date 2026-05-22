<?php namespace ProcessWire;

$templateFileOptions = [];
$templatePath = wire()->config->paths->templates;
if(is_dir($templatePath)) {
	$iterator = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($templatePath, \FilesystemIterator::SKIP_DOTS)
	);
	foreach($iterator as $file) {
		/** @var \SplFileInfo $file */
		if(!$file->isFile() || $file->getExtension() !== 'php') continue;
		$name = str_replace('\\', '/', substr($file->getPathname(), strlen($templatePath)));
		$parts = explode('/', $name);
		foreach($parts as $part) {
			if(strpos($part, '.') === 0) continue 2;
		}
		$templateFileOptions[$name] = $name;
	}
	ksort($templateFileOptions);
}

return [
	'name' => 'template-security-scan',
	'title' => 'Template file security scan',
	'summary' => 'Scan template files for common security risks in output, selectors, and request handling.',
	'description' => 'Reviews files in $config->paths->templates for unsafe output and ProcessWire API usage patterns.',
	'icon' => 'shield',
	'mode' => 'review_then_fix',
	'scheduleable' => 0,
	'inputs' => [
		'scan_scope' => [
			'type' => 'radios',
			'label' => 'Scan scope',
			'options' => [
				'file' => 'Selected template file(s)',
				'sample' => 'Sample template files',
			],
			'value' => 'file',
			'optionColumns' => 1,
		],
		'template_file' => [
			'type' => 'AsmSelect',
			'label' => 'Template files',
			'description' => 'Select one template file and any related files needed for the review, such as partials, output wrappers, or result renderers.',
			'notes' => 'Example: for search.php, you might also include the search result partial and _main.php.',
			'options' => $templateFileOptions,
			'showIf' => 'scan_scope=file',
			'required' => true,
		],
		'file_limit' => [
			'type' => 'integer',
			'label' => 'Template files to sample',
			'min' => 1,
			'max' => 25,
			'value' => 5,
			'showIf' => 'scan_scope=sample',
		],
		'minimum_severity' => [
			'type' => 'select',
			'label' => 'Minimum severity',
			'options' => [
				'low' => 'Low',
				'medium' => 'Medium',
				'high' => 'High',
			],
			'value' => 'medium',
		],
		'propose_fixes' => [
			'type' => 'checkbox',
			'label' => 'Propose fixes when safe',
			'value' => 1,
		],
	],
	'prompt' => <<<'TEXT'
Perform a bounded security code review of ProcessWire template files in $config->paths->templates. Scan scope: {scan_scope}. Selected template files: {template_file}. Template files to sample: {file_limit}. Minimum severity: {minimum_severity}. Propose fixes when safe: {propose_fixes}.

This is a bounded triage task, not a complete application security audit. Do not crawl the whole template directory, do not inspect every include, and do not keep calling tools to make the review complete.

If scan_scope is "file", review only the selected template files. Do not review unrelated templates or auto-expand into additional files unless a selected file cannot be understood at all without one small direct include. If you need unselected files for confidence, list them as follow-up instead of continuing.

If scan_scope is "sample", use one eval_php call to list template PHP files in $config->paths->templates, select only the first {file_limit} relevant top-level template files, and read only those selected files. You may mention that additional files remain unreviewed, but do not review their contents.

Look especially for:
- user-controlled values output without appropriate encoding
- user input passed into selectors without sanitization or selector-safe APIs
- request data used in file paths, redirects, email headers, SQL, markup, or authorization decisions
- unsafe use of eval-like behavior or dynamic includes
- missing permission/access checks around sensitive page or file output
- CSRF-sensitive actions in templates

Use ProcessWire API context only when needed for a concrete line of code. Avoid broad site_info/schema exploration unless a specific selector or field name cannot be assessed from the code itself.

Return findings grouped by severity with file paths, line/context when available, why it matters, confidence level, and recommended remediation. If nothing at or above the minimum severity is found in the reviewed scope, say so clearly and list the scope that was reviewed.

If a fix can be made safely, describe the exact patch or migration needed. If a migration is appropriate for a configuration-level fix, create one. If the issue is PHP template code, report the needed code change rather than forcing it into a migration.

If the user later asks you to create a migration that patches PHP template files, keep the migration small and syntactically conservative. Be especially careful with PHP variables such as $_GET, $_POST, $input, or $page inside migration strings: use single-quoted strings, nowdoc blocks, concatenation, or escaped dollar signs so the migration itself remains valid PHP. Do not put unescaped PHP variable expressions inside double-quoted migration strings.
TEXT
];
