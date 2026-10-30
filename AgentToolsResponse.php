<?php namespace ProcessWire;

/**
 * Text and completion metadata returned by AgentToolsAgent::askResponse().
 *
 * Partial text is retained when an error or output limit prevents completion.
 * Stop reasons retain the provider's values; usage has consistent token keys.
 *
 * @property string $text Answer text, including any partial answer
 * @property string $error Error message, or an empty string on success
 * @property string $stopReason Provider stop reason or Responses API status
 * @property bool $truncated Whether the response reached its output token limit
 * @property array $usage Token counts: input, output, total, cacheRead, cacheWrite
 */
class AgentToolsResponse extends WireData {

	/**
	 * Normalize metadata from a raw provider response.
	 *
	 * @param array $response Raw provider response, or an empty array on request failure
	 * @param string $provider Provider name
	 * @param string $text Extracted answer text
	 */
	public function __construct(array $response = [], string $provider = '', string $text = '') {
		parent::__construct();
		$usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
		$anthropic = $provider === 'anthropic';
		$input = (int) ($anthropic ? ($usage['input_tokens'] ?? 0) : ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0));
		$output = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
		$cacheRead = (int) ($usage['cache_read_input_tokens'] ?? $usage['prompt_tokens_details']['cached_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? 0);
		$cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);
		$total = (int) ($usage['total_tokens'] ?? ($input + $output + ($anthropic ? $cacheRead + $cacheWrite : 0)));
		$stopReason = (string) ($anthropic
			? ($response['stop_reason'] ?? '')
			: ($response['choices'][0]['finish_reason'] ?? $response['incomplete_details']['reason'] ?? $response['status'] ?? ''));
		$truncated = in_array($stopReason, ['max_tokens', 'length', 'max_output_tokens'], true);
		$error = '';
		if(!empty($response['error'])) {
			$error = is_array($response['error'])
				? (string) ($response['error']['message'] ?? $response['error']['code'] ?? 'Agent request failed.')
				: (string) $response['error'];
		} else if($truncated) {
			$error = 'The agent response reached its output token limit and may be incomplete.';
		} else if($stopReason === 'content_filter') {
			$error = 'The agent response was interrupted by a content filter.';
		} else if(isset($response['status']) && $response['status'] !== 'completed') {
			$error = 'The agent response did not complete (status: ' . (string) $response['status'] . ').';
		}
		$this->setArray([
			'text' => $text,
			'error' => $error,
			'stopReason' => $stopReason,
			'truncated' => $truncated,
			'usage' => [
				'input' => $input,
				'output' => $output,
				'total' => $total,
				'cacheRead' => $cacheRead,
				'cacheWrite' => $cacheWrite,
			],
		]);
	}
}
