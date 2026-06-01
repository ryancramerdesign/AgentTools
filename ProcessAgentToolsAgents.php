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
		$maxAgents = 15;
		$numAgents = 0;
		$form = $modules->get('InputfieldForm'); /** @var InputfieldForm $form */
		$form->attr('id', 'at-agents-form');
		$agents = $at->getAgents();

		$this->headline('Agents configuration');

		$labels = [
			'model' => 'Model ID',
			'label' => 'Label',
			'apiKey' => 'API key',
			'endpointUrl' => 'Endpoint URL',
			'description' => 'Description',
		];

		$engineerKeys = [
			'engineer_model' => 'model',
			'engineer_label' => 'label',
			'engineer_api_key' => 'apiKey',
			'engineer_endpoint' => 'endpointUrl',
			'engineer_description' => 'description',
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
			'agent' => [
				'onIcon' => 'trash',
				'onEvent' => 'at-agent-delete',
				'onTooltip' => 'Undo delete',
				'offIcon' => 'trash-o',
				'offEvent' => 'at-agent-delete',
				'offTooltip' => 'Delete',
			],
			'clone' => [
				'icon' => 'copy',
				'tooltip' => 'Clone',
				'event' => 'at-agent-clone',
			],
		];

		for($n = 1; $n <= $maxAgents; $n++) {
			
			$agent = $agents->eq($n-1);
			$agentLabel = $agent ? $agent->get('label|model') : '';

			$fs = $form->InputfieldFieldset;
			$fs->label = $agentLabel ? $agentLabel : "Agent $n";
			$fs->wrapClass('at-agent-item');
			$fs->icon = 'arrows';
			//$fs->themeOffset = 1;
			$fs->collapsed = Inputfield::collapsedYes;
			$fs->wrapAttr('data-agent-n', $n);
			if($agent) {
				$numAgents++;
			} else if($n > 1) {
				$fs->wrapClass('at-agent-new');
				$fs->wrapAttr('hidden', 'hidden');
			}
			$fs->wrapClass('InputfieldNoFocus');
			$form->add($fs);

			foreach($labels as $name => $label) {
				$f = $form->InputfieldText;
				$f->attr('name', "$name$n");
				$f->attr('data-name', $name); // for clone action
				$f->label = $label;
				$f->columnWidth = 25;
				if($agent) $f->val($agent->get($name));
				if($name === 'apiKey') $f->attr('type', 'password');
				if($name === 'description') {
					$f->columnWidth = 100;
					$f->skipLabel = Inputfield::skipLabelHeader;
					$f->attr('placeholder', 'Description');
				}
				$fs->add($f);

				if(isset($headerActions[$name])) {
					$f->wrapClass("at-$name");
					$f->addHeaderAction($headerActions[$name]);
				}
			}
			
			$f = $form->InputfieldHidden;
			$f->attr('name', "agent{$n}_sort");
			$f->addClass('at-agent-sort');
			$f->val($n);
			$fs->add($f);
			
			$f = $form->InputfieldHidden;
			$f->attr('name', "agent{$n}_delete");
			$f->addClass('at-agent-delete');
			$f->attr('val', '');
			$fs->add($f);
			
			$fs->addHeaderAction($headerActions['agent']);
			$fs->addHeaderAction($headerActions['clone']);
		}

		$submit = $form->InputfieldSubmit;
		$submit->attr('name', 'submit_agents');
		$submit->value = $this->_('Save');
		$submit->showInHeader();
		$form->add($submit);
		
		if($numAgents < $maxAgents) {
			$icon = wireIconMarkup('plus-circle');
			$label = $this->_('Add New');
			$submit->prependMarkup .=
				"<p id='at-add-agent'><a href='#' id='at-add-agent-link'>$icon $label</a></p>";
		}
		
		$f = $form->InputfieldHidden;
		$f->attr('id+name', 'agents_sort');
		$f->val('');
		$form->add($f);

		if(!$form->isSubmitted($submit)) return $form->render();

		$input = $this->wire()->input;
		$form->processInput($input->post);
		$agents = new AgentToolsAgents();
		$this->wire($agents);

		for($n = 1; $n <= $maxAgents; $n++) {
			$agent = new AgentToolsAgent();
			$this->wire($agent);
			foreach($labels as $name => $label) {
				$agent->set($name, $form->getValueByName("$name$n"));
			}
			$delete = $form->getValueByName("agent{$n}_delete");
			if($delete === "delete$n") {
				$this->message("Deleted agent $n: $agent->label");
				continue;
			}
			if($agent->model || $agent->apiKey || $agent->endpointUrl) {
				$agents->add($agent);
			}
			$sort = (int) $form->getValueByName("agent{$n}_sort");
			$agent->setQuietly('_sort', $sort);
		}
		
		$agents->sort('_sort');

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
