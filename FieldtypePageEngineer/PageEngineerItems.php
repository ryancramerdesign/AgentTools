<?php namespace ProcessWire;

require_once(__DIR__ . '/PageEngineerItem.php');

/**
 * Page Engineer: conversation history (collection of PageEngineerItem objects)
 *
 */
class PageEngineerItems extends WireArray {

	public function isValidItem($item) {
		return $item instanceof PageEngineerItem;
	}

	/**
	 * Get JSON version of this object
	 *
	 * @return string
	 *
	 */
	public function getJson() {
		$items = [];
		foreach($this->getArray() as $item) {
			/** @var PageEngineerItem $item */
			$items[] = $item->getArray();
		}
		return (string) json_encode($items, JSON_PRETTY_PRINT);
	}

	/**
	 * Populate this object from JSON
	 *
	 * @param string $str
	 *
	 */
	public function setJson(string $str) {
		if(strpos($str, '[') !== 0 && strpos($str, '{') !== 0) return;
		$value = json_decode($str, true);
		if($value === false) {
			$this->error("Cannot decode JSON: $str");
			return;
		}
		foreach($value as $v) {
			$item = $this->newItem();
			$this->wire($item);
			$item->setArray($v);
			$this->add($item);
		}
	}

	public function markupValue() {
		$a = [];
		foreach($this as $item) {
			/** @var PageEngineerItem $item */
			$a[] = $item->markupValue();
		}
		return implode('<hr>', $a);
	}

	/**
	 * Create a new PageEngineerItem
	 *
	 * @param string $text
	 * @param string $from
	 * @param bool $isAgent
	 * @return PageEngineerItem
	 *
	 */
	public function newItem($text = '', $from = '', $isAgent = false) {
		$item = new PageEngineerItem();
		$this->wire($item);
		if(empty($from)) $from = $this->wire()->user->name;
		if(strlen($text)) $item->text = $text;
		if(strlen($from)) $item->from = $from;
		$item->when = date('Y-m-d H:i:s');
		$item->isAgent = $isAgent;
		return $item;
	}

}
