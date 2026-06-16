<?php namespace ProcessWire;

/**
 * Page Engineer: conversation item (a single message from the user or agent)
 *
 * @property string $from Name of user or model that this $text is from
 * @property string $when ISO-8601 date/time
 * @property string $text Text from user or agent
 * @property int $isAgent Is text from an AI agent?
 *
 * @method string render()
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
		if($this->isAgent && $this->wire('at')) {
			return $this->wire('at')->markdownToHtml($this->text, [ 'safe' => true ]);
		}
		return '<p>' . nl2br(htmlspecialchars($this->text)) . '</p>';
	}

	/**
	 * Render this item as a comment
	 *
	 * @return string
	 *
	 */
	public function ___render() {
		$sanitizer = $this->wire()->sanitizer;
		$at = $this->wire('at'); /** @var AgentTools $at */
		$body = $this->markupValue();

		if($this->isAgent) {
			$agent = $at->getAgents()->getByValue($this->from);
			$from = $agent ? $agent->getAgentName() : $this->from;
			$class = 'at-comment-agent uk-comment-primary';
			$icon = wireIconMarkup('at', 'uk-text-muted');
		} else {
			$from = $this->from;
			$class = 'at-comment-user';
			$icon = wireIconMarkup('user-circle', 'uk-text-muted');
		}

		$from = $sanitizer->entities(ucfirst($from));
		$when = $sanitizer->entities(wireDate('Y/m/d h:ia', $this->when));

		return "
			<div class='uk-comment $class uk-margin'>
				<h3 class='uk-margin-small'>$icon&nbsp; $from <span class='uk-text-meta'>$when</span></h3>
				$body
			</div>
		";

	}
}
