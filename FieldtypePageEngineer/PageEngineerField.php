<?php namespace ProcessWire;

require_once(__DIR__ . '/PageEngineerItems.php');

/**
 * @property int $scope
 * @property string $instructions
 * @property int|bool $backup Backup to version before making change?
 * @property array $onlyFields Limit scope of changes to only these fields
 *
 */
class PageEngineerField extends Field {

	const scopePage = 1;
	const scopePageAndChildren = 2;
	const scopeChildren = 3;

	public function __construct() {
		parent::__construct();
		$this->set('scope', self::scopePage);
		$this->set('instructions', 'Help the user make requested edits to the page being edited.');
		$this->set('backup', true);
		$this->set('onlyFields', []);
	}

}
