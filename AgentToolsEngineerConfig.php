<?php namespace ProcessWire;

/**
 * Agent Tools Engineer configuration builder
 *
 * Builds module configuration Inputfields for Engineer-related settings.
 *
 */
class AgentToolsEngineerConfig extends Wire {
	
	/**
	 * @var AgentTools
	 */
	protected $at;
	
	/**
	 * Construct Engineer configuration builder
	 *
	 * @param AgentTools $at
	 *
	 */
	public function __construct(AgentTools $at) {
		parent::__construct();
		$this->at = $at;
		$at->wire($this);
	}
	
	/**
	 * Module config inputfields for API credentials and provider settings
	 *
	 * @param InputfieldWrapper $inputfields
	 * @return void
	 *
	 */
	public function getConfigInputfields(InputfieldWrapper $inputfields): void {
		$modules = $this->wire()->modules;
		
		if(!$this->at->engineer_model) {
			// populate to primary agent fields, useful after an import
			/** @var AgentToolsAgent $agent */
			$agent = $this->at->getAgents()->first();
			if($agent) {
				$keys = [
					'api_key' => 'apiKey',
					'model' => 'model',
					'label' => 'label',
					'endpoint' => 'endpointUrl',
				];
				foreach($keys as $moduleKey => $agentKey) {
					$moduleKey = 'engineer_' . $moduleKey;
					$moduleValue = $this->at->get($moduleKey);
					if(empty($moduleValue)) $this->at->set($moduleKey, $agent->get($agentKey));
				}
			}
		}
		
		/** @var InputfieldFieldset $outerFs */
		$outerFs = $modules->get('InputfieldFieldset');
		$outerFs->label = $this->_('Engineer');
		$outerFs->icon = 'commenting';
		
		$primaryFs = $modules->get('InputfieldFieldset');
		$primaryFs->label = $this->_('Primary Agent');
		$primaryFs->themeOffset = 1;
		$outerFs->add($primaryFs);
		
		$primaryFs->description =
			$this->_('Configure the primary AI agent here.') . ' ' .
			$this->_('You can also edit and add more AI agents at [Setup > AgentTools > Agents](../setup/agent-tools/agents/).') . ' ' .
			$this->_('If you do not need AI tools in your admin then it is not necessary to add an agent here.') . ' ' .
			$this->_('You can still use AgentTools in dev environments with local CLI agents or equivalent.');
		
		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'engineer_provider');
		$f->label = $this->_('AI Provider');
		$f->addOption(AgentToolsEngineer::providerAnthropic, 'Anthropic (Claude)');
		$f->addOption(AgentToolsEngineer::providerOpenAI, $this->_('OpenAI-compatible'));
		$f->val($this->at->get('engineer_provider') ?: AgentToolsEngineer::providerAnthropic);
		$f->columnWidth = 50;
		$primaryFs->add($f);
		
		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_api_key');
		$f->attr('type', 'password');
		$f->label = $this->_('API Key');
		$f->val($this->at->get('engineer_api_key') ?: '');
		$f->columnWidth = 50;
		$primaryFs->add($f);
		
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_model');
		$f->label = $this->_('Model API identifier');
		$f->val($this->at->get('engineer_model') ?: '');
		$f->description = 'Example: `claude-sonnet-4-6` ';
		$f->columnWidth = 50;
		$primaryFs->add($f);
		
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_label');
		$f->label = $this->_('Model API label (optional)');
		$f->val($this->at->get('engineer_label') ?: '');
		$f->description = 'Example: `Claude Sonnet 4.6` ';
		$f->columnWidth = 50;
		$primaryFs->add($f);
		
		/** @var InputfieldURL $f */
		$f = $modules->get('InputfieldURL');
		$f->attr('name', 'engineer_endpoint');
		$f->label = $this->_('API endpoint URL');
		$f->val($this->at->get('engineer_endpoint') ?: '');
		$f->columnWidth = 50;
		$primaryFs->add($f);
		
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_description');
		$f->label = $this->_('Description (optional)');
		$f->val($this->at->get('engineer_description') ?: '');
		$f->columnWidth = 50;
		$primaryFs->add($f);
		
		/** @var InputfieldToggle $f */
		$f = $modules->get('InputfieldToggle');
		$f->attr('name', 'engineer_readonly');
		$f->label = $this->_('Read-only mode');
		$f->description = $this->_('When enabled, the Site Engineer can query live site data and suggest changes, but cannot create migration files or intentionally change site behavior.');
		$f->notes = $this->_('This setting does not apply to Page Engineer fields, if you are using any.');
		$f->val((int) $this->at->get('engineer_readonly'));
		$f->columnWidth = 50;
		$outerFs->add($f);
		
		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'engineer_mem_qty');
		$f->label = $this->_('Conversation memory (message pairs)');
		$f->description = $this->_('Number of past request/response pairs to retain in the AI context window. Older pairs are dropped first. Applies to both the Site Engineer and Page Engineer.');
		$f->attr('min', 1);
		$f->attr('max', 100);
		$val = (int) $this->at->get('engineer_mem_qty');
		$f->val($val ?: AgentToolsEngineer::defaultHistoryPairs);
		$f->columnWidth = 50;
		$outerFs->add($f);
		
		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'engineer_max_iterations');
		$f->label = $this->_('Maximum tool-use rounds');
		$f->description = $this->_('Maximum number of AI/tool response rounds allowed before AgentTools stops a request. Increase this for longer tasks that need more tool use, but higher values may run into web server or PHP timeouts.');
		$f->notes = $this->_('The Engineer is told about this budget in its system prompt so it can plan its work.');
		$f->attr('min', 1);
		$f->attr('max', 100);
		$val = (int) $this->at->get('engineer_max_iterations');
		$f->val($val ?: AgentToolsEngineer::defaultMaxIterations);
		$f->columnWidth = 50;
		$outerFs->add($f);
		
		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'engineer_request_timeout');
		$f->label = $this->_('AI request timeout (seconds)');
		$f->description = $this->_('Maximum time to wait for each AI provider API request. Longer translation or analysis tasks may need this to match your PHP/web server timeout settings.');
		$f->notes = $this->_('Default is 300 seconds. Use 600 seconds only when your PHP and web server timeouts are also configured to allow long requests.');
		$f->attr('min', 30);
		$f->attr('max', 1200);
		$val = (int) $this->at->get('engineer_request_timeout');
		$f->val($val ?: AgentToolsEngineer::defaultRequestTimeout);
		$f->columnWidth = 50;
		$outerFs->add($f);
		
		/** @var InputfieldEmail $f */
		$f = $modules->get('InputfieldEmail');
		$f->attr('name', 'engineer_email_from');
		$f->label = $this->_('Background job email from');
		$f->description = $this->_('Optional sender address for background job notification emails.');
		$f->notes = $this->_('Use an address authorized to send mail from this server/domain, such as info@example.com.');
		$f->val($this->at->get('engineer_email_from') ?: '');
		$f->columnWidth = 50;
		$outerFs->add($f);
		
		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'engineer_user');
		$f->label = $this->_('Run engineer as user');
		$f->description = $this->_('Optionally specify the user name or ID of the user Engineer should run as.');
		$f->notes = $this->_('If omitted, Engineer will run as logged-in user or "guest", depending on the operation.');
		$f->val($this->at->get('engineer_user') ?: '');
		$f->columnWidth = 50;
		$outerFs->add($f);
		
		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'engineer_instructions');
		$f->label = $this->_('Custom instructions');
		$f->description = $this->_('Additional instructions appended to the Engineer system prompt. Use this to provide site-specific context, point the Engineer to custom API.md files, or restrict its behavior for this installation.');
		$f->attr('rows', 5);
		$f->val($this->at->get('engineer_instructions') ?: '');
		$f->collapsed = Inputfield::collapsedBlank;
		$outerFs->add($f);

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'engineer_memory');
		$f->label = $this->_('Persistent memory');
		$f->description = $this->_('Durable site/workflow preferences automatically included in Site Engineer and Task requests.');
		$f->notes =
			$this->_('Site Engineer agents will add or modify entries when explicitly asked to remember durable preferences.') . ' ' .
			$this->_('You can modify or remove entries here if needed.') . ' ' .
			$this->_('To add entries please ask the Site Engineer to remember something directly.');
		$f->attr('rows', 8);
		$f->val($this->at->get('engineer_memory') ?: '');
		$f->collapsed = Inputfield::collapsedYes;
		$outerFs->add($f);
		
		/** @var InputfieldFieldset $traceFs */
		$traceFs = $modules->get('InputfieldFieldset');
		$traceFs->label = $this->_('Debug and traces');
		$traceFs->icon = 'bug';
		if(!$this->at->get('engineer_debug_mode') && !$this->at->get('engineer_trace_mode')) {
			$traceFs->collapsed =  Inputfield::collapsedYes;
		}
		$traceFs->themeOffset = 1;
		$traceFs->notes = sprintf($this->_('Trace files are saved in %s.'), '`site/assets/at/traces/`');
		
		/** @var InputfieldToggle $f */
		$f = $modules->get('InputfieldToggle');
		$f->attr('name', 'engineer_debug_mode');
		$f->label = $this->_('Debug mode');
		$f->description = $this->_('When enabled, live Engineer requests show a compact trace summary as an admin notification.');
		$f->notes = $this->_('Background jobs save traces when trace logging is enabled, but do not show live admin notifications.');
		$f->val((int) $this->at->get('engineer_debug_mode'));
		$f->columnWidth = 50;
		$traceFs->add($f);
		
		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'engineer_trace_mode');
		$f->label = $this->_('Trace agent runs');
		$f->description = $this->_('Save compact JSON traces of Engineer, Task, and Page Engineer runs for later review.');
		$f->addOption('', $this->_('Off'));
		$f->addOption('summary', $this->_('Summary'));
		$f->addOption('detailed', $this->_('Detailed'));
		$f->val($this->at->get('engineer_trace_mode') ?: '');
		$f->columnWidth = 50;
		$traceFs->add($f);
		
		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'engineer_trace_keep_days');
		$f->label = $this->_('Keep traces for days');
		$f->description = $this->_('Old trace files are pruned when new traces are saved. Use 0 to keep traces indefinitely.');
		$f->attr('min', 0);
		$f->attr('max', 3650);
		$val = (int) $this->at->get('engineer_trace_keep_days');
		$f->val($val ?: 30);
		$f->showIf = 'engineer_trace_mode!=""';
		$f->columnWidth = 50;
		$traceFs->add($f);
		
		/** @var InputfieldToggle $f */
		$f = $modules->get('InputfieldToggle');
		$f->attr('name', 'engineer_trace_include_content');
		$f->label = $this->_('Include prompts and responses');
		$f->description = $this->_('When enabled, saved trace JSON includes the user prompt and final AI response. Leave disabled when traces may contain private content.');
		$f->val((int) $this->at->get('engineer_trace_include_content'));
		$f->showIf = 'engineer_trace_mode!=""';
		$f->columnWidth = 50;
		$traceFs->add($f);
		
		$outerFs->add($traceFs);
		
		/** @var InputfieldFieldset $secFs */
		$secFs = $modules->get('InputfieldFieldset');
		$secFs->label = $this->_('Suspicious prompt reporting');
		$secFs->icon = 'shield';
		$secFs->collapsed = Inputfield::collapsedBlank;
		$secFs->themeOffset = 1;
		
		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'engineer_suspicious');
		$f->label = $this->_('Enable reporting');
		$f->addOption('', $this->_('Disabled'));
		$f->addOption('page', $this->_('Page Engineer only'));
		$f->addOption('all', $this->_('All engineers (Page Engineer + Site Engineer)'));
		$f->description = $this->_('When enabled, the AI refuses requests for sensitive configuration data and reports them. Flagged users are blocked from Engineer requests for 1 hour.');
		$f->val($this->at->get('engineer_suspicious') ?: '');
		$secFs->add($f);
		
		/** @var InputfieldEmail $f */
		$f = $modules->get('InputfieldEmail');
		$f->attr('name', 'engineer_suspicious_email');
		$f->label = $this->_('Notification email address');
		$f->description = $this->_('When a suspicious prompt is detected, a notification is sent here.');
		$f->val($this->at->get('engineer_suspicious_email') ?: '');
		$f->showIf = 'engineer_suspicious!=""';
		$secFs->add($f);
		
		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'engineer_suspicious_log');
		$f->label = $this->_('Suspicious prompt log');
		$f->description = $this->_('One entry per line: username | timestamp | prompt. Delete a line to unblock that user. Entries older than 1 hour are ignored for blocking but kept for your review.');
		$f->notes = $this->_('This is populated automatically by the AI agent, you do not need to enter anything in here.');
		$f->attr('rows', 5);
		$f->val($this->at->get('engineer_suspicious_log') ?: '');
		$f->showIf = 'engineer_suspicious!=""';
		$f->collapsed = Inputfield::collapsedBlank;
		$secFs->add($f);
		
		$outerFs->add($secFs);
		
		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'engineer_additional_models');
		$f->label = $this->_('Agents (export/import)');
		$f->collapsed = Inputfield::collapsedYes;
		$f->description =
			$this->_('This field contains your agents configuration in pipe-separated format (one agent per line).') . ' ' .
			$this->_('You can copy this value to transfer your agent configuration to another installation, or paste a configuration from another installation here.');
		$f->attr('rows', 6);
		$f->val($this->at->getAgents()->getString());
		$outerFs->add($f);
		$inputfields->add($outerFs);
	}
}
