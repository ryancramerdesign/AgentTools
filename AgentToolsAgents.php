<?php namespace ProcessWire;

/**
 * WireArray of AgentToolsAgent instances
 * 
 * @implements IteratorAggregate<int, AgentToolsAgent>
 *
 */
class AgentToolsAgents extends WireArray {
	
	/**
	 * @param string|array $str Optional agents definition string to populate (string or array of strings)
	 * 
	 */
	public function __construct($str = '') {
		parent::__construct();
		if($str) $this->addString($str);
	}
	
	public function isValidItem($item) {
		return $item instanceof AgentToolsAgent;
	}
	
	/**
	 * Make a blank AgentToolsAgent
	 *
	 * @return AgentToolsAgent
	 *
	 */
	public function makeBlankItem() {
		$agent = new AgentToolsAgent();
		$this->wire($agent);
		return $agent;
	}
	
	/**
	 * Add agent(s) by definition string
	 *
	 * @param string|array $str String definition or array of strings definition
	 * @return self
	 *
	 */
	public function addString($str) {
		if(is_array($str)) {
			$lines = $str;
		} else {
			$lines = strpos($str, "\n") !== false ? explode("\n", $str) : [$str];
		}
		foreach($lines as $line) {
			if(strpos($line, '|') === false) continue;
			$agent = $this->makeBlankItem();
			$agent->setString($line);
			$this->add($agent);
		}
		return $this;
	}
	
	/**
	 * Render string of all agents configuration
	 * 
	 */
	public function getString() {
		$a = [];
		foreach($this as $agent) {
			/** @var AgentToolsAgent $agent */
			$a[] = $agent->getString();
		}
		return trim(implode("\n", $a));
	}
	
	/**
	 * Render just the model names
	 * 
	 * @return string
	 * 
	 */
	public function __toString() {
		$a = [];
		foreach($this as $agent) {
			/** @var AgentToolsAgent $agent */
			$a[] = $agent->model;
		}
		return implode('|', $a);
	}
	
	/**
	 * @return \ArrayIterator<int, AgentToolsAgent>
	 *
	 */
	public function getIterator(): \ArrayIterator {
		return new \ArrayIterator($this->data);
	}
	
	public function removeDuplicates() {
		$a = [];
		foreach($this as $agent) { /** @var AgentToolsAgent $agent */
			$hash = $agent->getHash();
			if(isset($a[$hash])) $this->remove($agent);
			$a[$hash] = $agent;
		}
	}
}
