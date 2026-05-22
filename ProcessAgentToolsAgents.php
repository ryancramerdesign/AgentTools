<?php namespace ProcessWire;

/**
 * ProcessAgentTools Agents Helper
 *
 */
class ProcessAgentToolsAgents extends ProcessAgentToolsHelper {

	/**
	 * Agents configuration
	 *
	 * @return string
	 *
	 */
	public function executeAgents() {
		$modules = $this->wire()->modules;
		$at = $this->at;
		$maxAgents = 10;
		$form = $modules->get('InputfieldForm'); /** @var InputfieldForm $form */
		$agents = $at->getAgents();
		$dataLists = include(__DIR__ . '/datalists.php');

		$this->headline('Agents configuration');

		$labels = [
			'model' => 'Model ID',
			'label' => 'Optional label',
			'apiKey' => 'API key',
			'endpointUrl' => 'Endpoint URL',
		];

		$engineerKeys = [
			'engineer_model' => 'model',
			'engineer_label' => 'label',
			'engineer_api_key' => 'apiKey',
			'engineer_endpoint' => 'endpointUrl',
		];

		$headerActions = [
			'apiKey' => [
				'onIcon' => 'toggle-on',
				'onEvent' => 'at-apikey-show',
				'onTooltip' => 'Hide API key',
				'offIcon' => 'toggle-off',
				'offEvent' => 'at-apikey-hide',
				'offTooltip' => 'Show API key',
			],
		];

		for($n = 1; $n <= $maxAgents; $n++) {

			$fs = $form->InputfieldFieldset;
			$fs->label = "Agent $n" . ($n === 1 ? ' (Primary)' : '');
			$fs->themeOffset = 1;
			$fs->collapsed = Inputfield::collapsedBlank;
			$form->add($fs);
			$agent = $agents->eq($n-1);

			foreach($labels as $name => $label) {
				$f = $form->InputfieldText;
				$f->attr('name', "$name$n");
				$f->label = $label;
				$f->columnWidth = 25;
				if($agent) $f->val($agent->get($name));
				if($name === 'apiKey') $f->attr('type', 'password');
				$fs->add($f);

				if(isset($headerActions[$name])) {
					$f->wrapClass("at-$name");
					$f->addHeaderAction($headerActions[$name]);
				}

				if(!isset($dataLists[$name])) continue;

				$f->attr('list', "$name-examples");
				$examples = $dataLists[$name];
				if(!count($examples)) continue; // already rendered the datalist
				$o = '';

				foreach($examples as $label => $example) {
					$value = htmlspecialchars($example, ENT_QUOTES, 'UTF-8');
					$label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
					$o .= "<option value='$value' label='$label'>";
				}

				$f->appendMarkup = "<datalist id='$name-examples'>$o</datalist>";
				$dataLists[$name] = []; // ensure we render it only once
			}
		}

		$submit = $form->InputfieldSubmit;
		$submit->attr('name', 'submit_agents');
		$submit->value = $this->_('Save');
		$submit->showInHeader();
		$form->add($submit);

		if(!$form->isSubmitted($submit)) return $form->render();

		$form->processInput($this->wire()->input->post);
		$agents = new AgentToolsAgents();

		for($n = 1; $n <= $maxAgents; $n++) {
			$agent = new AgentToolsAgent();
			foreach($labels as $name => $label) {
				$agent->set($name, $form->getValueByName("$name$n"));
			}
			if($agent->model || $agent->apiKey || $agent->endpointUrl) $agents->add($agent);
		}

		$data = $modules->getConfig('AgentTools');
		$agent = $agents->first(); /** @var AgentToolsAgent $agent */

		// save settings (keeping legacy settings for now)
		foreach($engineerKeys as $key => $prop) {
			$data[$key] = $agent ? $agent->get($prop) : '';
		}
		$data['engineer_additional_models'] = $agents->getString();
		$modules->saveConfig($at, $data);
		$this->message('Saved agents');

		if(count($form->getErrors())) return $form->render();

		$this->wire()->session->location('./');
	}
}
