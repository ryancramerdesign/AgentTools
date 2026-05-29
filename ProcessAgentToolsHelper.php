<?php namespace ProcessWire;

/**
 * Base class for ProcessAgentToolsHelper instances to extend
 *
 */
abstract class ProcessAgentToolsHelper extends Wire {

	/**
	 * @var AgentTools
	 */
	protected $at;

	/**
	 * @var ProcessAgentTools
	 */
	protected $pat;

	/**
	 * Construct
	 *
	 * @param ProcessAgentTools $pat
	 * @param AgentTools $at
	 */
	public function __construct(ProcessAgentTools $pat, AgentTools $at) {
		$at->wire($this);
		$this->pat = $pat;
		$this->at = $at;
		parent::__construct();
	}

	/**
	 * Set headline
	 *
	 * @param string $headline
	 *
	 */
	protected function headline($headline) {
		$this->pat->headline($headline);
	}

	/**
	 * Add breadcrumb
	 *
	 * @param string $url
	 * @param string $label
	 *
	 */
	protected function breadcrumb($url, $label) {
		$this->pat->breadcrumb($url, $label);
	}

	/**
	 * Return a translation label
	 *
	 * @param string $name
	 * @return string
	 *
	 */
	protected function label($name) {
		return $this->pat->label($name);
	}

	/**
	 * Return an icon name for a given label/action
	 *
	 * @param string $name
	 * @return string
	 *
	 */
	protected function iconName($name) {
		return $this->pat->iconName($name);
	}

	/**
	 * Get a feature description
	 *
	 * @param string $name
	 * @return string
	 *
	 */
	protected function description($name) {
		return $this->pat->description($name);
	}

	/**
	 * Render a uk-label element
	 *
	 * @param string $label
	 * @param string $type One of 'success', 'danger', 'warning' or omit for default
	 * @return string
	 *
	 */
	protected function ukLabel($label, $type = '') {
		return $this->pat->ukLabel($label, $type);
	}

	/**
	 * Render a <pre> and entity encode text
	 *
	 * @param string $text
	 * @param array $options
	 *  - `entityEncode` (bool): Entity encode given text? (default=true)
	 *  - `wordWrap` (bool): Word wrap long lines? (default=true)
	 * @return string
	 *
	 */
	protected function pre($text, array $options = []) {
		return $this->pat->pre($text, $options);
	}

	/**
	 * Render a note about troubleshooting timeouts (html)
	 *
	 * @return string
	 *
	 */
	protected function troubleshootingNote($prepend = '') {
		return $this->pat->troubleshootingNote($prepend);
	}

	/**
	 * Select random thinking words
	 *
	 * @return string
	 *
	 */
	protected function renderThinkingWords() {
		return $this->pat->renderThinkingWords();
	}

	/**
	 * Format and prepare engineer response for output
	 *
	 * @param string $response
	 * @return string
	 *
	 */
	protected function formatEngineerResponse($response) {
		return $this->pat->formatEngineerResponse($response);
	}

	/**
	 * Get background queue error, or blank when available
	 *
	 * @return string
	 *
	 */
	protected function getBackgroundJobError(): string {
		return $this->pat->getBackgroundJobError();
	}

	/**
	 * Render queued background job confirmation
	 *
	 * @param array $job
	 * @return string
	 *
	 */
	protected function renderQueuedJobConfirmation(array $job, string $returnUrl = '', string $returnLabel = '', string $returnIcon = ''): string {
		return $this->pat->renderQueuedJobConfirmation($job, $returnUrl, $returnLabel, $returnIcon);
	}
}
