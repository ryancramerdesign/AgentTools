<?php namespace ProcessWire;

/**
 * WireArray of AgentToolsAgent instances
 * 
 * @implements IteratorAggregate<int, AgentToolsAgent>
 *
 */
class AgentToolsAgents extends WireArray {
	
	/**
	 * @param string $str Optional agents definition string to populate
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
	 * @param string $str
	 * @return self
	 *
	 */
	public function addString(string $str) {
		$lines = strpos($str, "\n") !== false ? explode("\n", $str) : [ $str ];
		foreach($lines as $line) {
			if(strpos($line, '|') === false) continue;
			$agent = $this->makeBlankItem();
			$agent->setString($line);
			$this->add($agent);
		}
		return $this;
	}
	
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
}
