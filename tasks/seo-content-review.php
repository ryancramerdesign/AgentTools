<?php namespace ProcessWire;

return [
	'name' => 'seo-content-review',
	'title' => 'SEO and content hygiene review',
	'summary' => 'Review site structure and representative content for SEO/content quality issues.',
	'description' => 'Looks for missing titles, weak metadata, thin content signals, broken references, and common content hygiene problems.',
	'icon' => 'search',
	'mode' => 'review',
	'scheduleable' => 0,
	'inputs' => [
		'review_scope' => [
			'type' => 'radios',
			'label' => 'Review scope',
			'options' => [
				'page' => 'Selected page',
				'sample' => 'Sample key site pages',
			],
			'value' => 'page',
			'optionColumns' => 1,
		],
		'page_id' => [
			'type' => 'PageListSelect',
			'label' => 'Page to review',
			'description' => 'Select a local ProcessWire page to review.',
			'showIf' => 'review_scope=page',
		],
		'sample_size' => [
			'type' => 'integer',
			'label' => 'Representative pages to sample',
			'min' => 1,
			'max' => 50,
			'value' => 10,
			'showIf' => 'review_scope=sample',
		],
		'fetch_rendered_pages' => [
			'type' => 'checkbox',
			'label' => 'Fetch rendered HTML for sampled pages',
			'description' => 'Selected page reviews always fetch rendered HTML. For samples, this fetches rendered HTML for up to 3 sampled pages.',
			'value' => 0,
			'showIf' => 'review_scope=sample',
		],
		'report_style' => [
			'type' => 'select',
			'label' => 'Report style',
			'options' => [
				'concise' => 'Concise',
				'normal' => 'Normal',
				'detailed' => 'Detailed',
			],
			'value' => 'normal',
		],
	],
	'prompt' => <<<'TEXT'
Perform a bounded SEO and content hygiene review of this ProcessWire site. Review scope: {review_scope}. Selected page ID, when applicable: {page_id}. Representative pages to sample: {sample_size}. Fetch rendered sampled pages: {fetch_rendered_pages}. Report style: {report_style}.

This is a bounded triage task, not a complete crawl. Do not crawl the whole site and do not keep calling tools to make the review complete.

If review_scope is "page", review only the selected page. Use one eval_php call to load page ID {page_id}, confirm it exists/viewable, collect structured page facts (path, URL/httpUrl, template, title/name/headline-style fields, summary/meta-style fields, image fields, status, parent, children count, and relevant content field excerpts), then fetch the rendered local page URL using WireHttp or cURL to inspect final HTML. Do not render the page through the ProcessWire API. Do not sample unrelated pages except shared navigation/layout context if obvious from rendered HTML.

If review_scope is "sample", use the ProcessWire API to select exactly {sample_size} representative published pages and collect concise structured facts. Prefer homepage, major landing/content templates, listing/detail pages, and pages with images or summaries. If fetch_rendered_pages is enabled, fetch rendered HTML for at most 3 sampled pages. Otherwise, do not fetch rendered HTML for sampled pages.

Try to complete this task with one or two eval_php calls. Use read_file or site_info only for a specific question that cannot be answered from page data or rendered HTML. If more investigation is needed, list it as follow-up rather than continuing to call tools.

Look for missing or duplicate titles, weak summaries/meta descriptions when fields exist, broken internal references visible from page data/rendered HTML, empty required-looking content fields, thin content signals, image metadata gaps, canonical/navigation concerns, Open Graph/Twitter metadata gaps, robots/canonical issues, heading/title mismatches, and template patterns that could affect search visibility.

Return a {report_style} report grouped by issue type and severity. Include affected templates/pages when possible, recommended fixes, and any ProcessWire fields/templates that appear relevant. If nothing significant is found in the reviewed scope, say so clearly and list the scope reviewed.
TEXT
];
