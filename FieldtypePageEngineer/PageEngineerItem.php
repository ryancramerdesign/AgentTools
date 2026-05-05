<?php namespace ProcessWire;

/**
 * Page Engineer: conversation item (a single message from the user or agent)
 *
 * @property string $from Name of user or model that this $text is from
 * @property string $when ISO-8601 date/time
 * @property string $text Text from user or agent
 * @property int $isAgent Is text from an AI agent?
 *
 */
class PageEngineerItem extends WireData {
	public function __construct() {
		parent::__construct();
		$this->setArray([
			'from' => '',
			'when' => '',
			'text' => '',
			'isAgent' => false,
		]);
	}

	public function set($key, $value) {
		if($key === 'when') {
			$value = empty($value) ? '' : wireDate('Y-m-d H:i:s', $value);
		} else if($key === 'from') {
			if($value instanceof User) $value = $value->name;
			$value = (string) $value;
		} else if($key === 'text') {
			$value = (string) $value;
		} else if($key === 'isAgent') {
			$value = (bool) $value;
		}
		return parent::set($key, $value);
	}

	public function markupValue() {
		/** @var TextformatterMarkdownExtra $markdown */
		$markdown = $this->wire()->modules->get('TextformatterMarkdownExtra');
		if(!$markdown) return '<p>' . htmlspecialchars($this->text) . '</p>';
		if($this->isAgent) return $markdown->markdown($this->text);
		return '<p>' . nl2br(htmlspecialchars($this->text)) . '</p>';
	}
}
