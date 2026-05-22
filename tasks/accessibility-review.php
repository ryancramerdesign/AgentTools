<?php namespace ProcessWire;

return [
	'name' => 'accessibility-review',
	'title' => 'WCAG accessibility review',
	'summary' => 'Review templates and representative content for common accessibility issues.',
	'description' => 'Looks for common WCAG problems in template output, image usage, headings, links, labels, and navigation.',
	'icon' => 'universal-access',
	'mode' => 'review',
	'admin' => 1,
	'scheduleable' => 0,
	'inputs' => [
		'page_id' => [
			'type' => 'PageListSelect',
			'label' => 'Page to review',
			'description' => 'Select a local ProcessWire page to review.',
			'required' => true,
		],
		'standard' => [
			'type' => 'select',
			'label' => 'Target standard',
			'options' => [
				'WCAG 2.2 AA' => 'WCAG 2.2 AA',
				'WCAG 2.1 AA' => 'WCAG 2.1 AA',
				'WCAG 2.2 A' => 'WCAG 2.2 A',
			],
			'value' => 'WCAG 2.2 AA',
		],
	],
	'prompt' => <<<'TEXT'
Perform a bounded first-pass {standard} accessibility review of selected ProcessWire page ID {page_id}. This is not a complete automated WCAG audit; it is a practical triage report for an administrator/developer.

Focus on the selected ProcessWire page only. Do not call site_info unless the selected page cannot be loaded by ID. Do not sample unrelated pages unless needed to explain shared layout/navigation.

Use this strict workflow and then stop:
1. Make one eval_php call to load page ID {page_id}, confirm it exists/viewable, collect path/URL/template context, then fetch the selected page's rendered frontend HTML using WireHttp or cURL against its local URL. Do not render the page through the ProcessWire API.
2. Analyze the rendered HTML first. Extract concise facts/snippets for headings, images, links, buttons, forms, ARIA attributes, nav landmarks, tables, iframes, video/audio, and obvious interactive UI.
3. If needed, use read_file for the selected page's template file and at most 2 directly related layout/include/CSS/JS files only to explain the likely cause of a finding.
4. Return the report. Do not keep calling tools to make the audit complete. Mark anything uncertain as "needs manual verification".

If you need more evidence than the limits allow, stop and list what should be manually checked next rather than continuing to call tools.

Look for issues such as missing or weak image alt text, heading order problems, unlabeled form controls, ambiguous links, keyboard navigation concerns, contrast risks visible in template/CSS usage, ARIA misuse, and dynamic UI that may need accessibility handling.

Return a concise report grouped by severity. Include file paths, template/page context, why each issue matters, and a recommended fix. If a finding cannot be confirmed from available code or content, mark it as "needs manual verification" rather than guessing.
TEXT
];
