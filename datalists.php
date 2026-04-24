<?php namespace ProcessWire;

if(!defined('PROCESSWIRE')) die();

/**
 * Examples for populating agent configuration datalist elements
 * 
 */

$datalists = [
	'model' => [
		'Claude Opus 4.7' => 'claude-opus-4-7',
		'Claude Opus 4.6' => 'claude-opus-4-6',
		'Claude Sonnet 4.6' => 'claude-sonnet-4-6',
		'Gemini 2.0 Flash' => 'gemini-2.0-flash',
		'Z.AI GLM 5.1' => 'glm-5.1',
		'Z.AI GLM 4.7' => 'glm-4.7',
		'Groq Llama 3.3' => 'llama-3.3-70b-versatile',
		'OpenAI GPT 5.4 pro' => 'gpt-5.4-pro',
		'OpenAI GPT 5.4' => 'gpt-5.4',
		'OpenAI GPT 5.4 mini' => 'gpt-5.4-mini',
		'OpenAI GPT 5' => 'gpt-5',
		'OpenAI GPT 4.1' => 'gpt-4.1',
		'OpenRouter Qwen 3.5-9B' => 'qwen/qwen3.5-9b'
	],
	'endpointUrl' => [
		'Claude' => 'https://api.anthropic.com/v1/messages',
		'OpenAI' => 'https://api.openai.com/v1',
		'Gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
		'Z.AI' => 'https://api.z.ai/api/paas/v4/chat/completions',
		'Groq' => 'https://api.groq.com/openai/v1',
		'OpenRouter' => 'https://openrouter.ai/api/v1',
	],
	'label' => [],
];

$datalists['label'] = array_flip($datalists['model']); 

return $datalists;
