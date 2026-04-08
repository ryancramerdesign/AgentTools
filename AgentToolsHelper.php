<?php namespace ProcessWire;

abstract class AgentToolsHelper extends Wire {

	/**
	 * @var AgentTools
	 *
	 */
	protected $at;

	/**
	 * Construct
	 *
	 * @param AgentTools $at
	 *
	 */
	public function __construct(AgentTools $at) {
		parent::__construct();
		$this->at = $at;
	}

	/**
	 * Get array of CLI help [ 'syntax' => 'description' ]
	 * 
	 * @return array
	 * 
	 */
	public function cliHelp() {
		return [];
	}

	/**
	 * Execute CLI action
	 *
	 * @param string $action
	 * @return bool|null Return true on success, false on fail, null if not applicable
	 *
	 */
	public function cliExecute($action) {
		return null;
	}

	/**
	 * Add any interactive configuration Inputfields for this helper
	 * 
	 * @param InputfieldWrapper $inputfields
	 * 
	 */
	public function getConfigInputfields(InputfieldWrapper $inputfields) {
	}
	
	public function upgrade($fromVersion, $toVersion) {
	}
}