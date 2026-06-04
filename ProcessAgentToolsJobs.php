<?php namespace ProcessWire;

/**
 * ProcessAgentTools Jobs Helper
 *
 */
class ProcessAgentToolsJobs extends ProcessAgentToolsHelper {

	/**
	 * List background jobs.
	 *
	 * @return string
	 *
	 */
	public function executeJobs(): string {
		$this->headline($this->label('jobs'));

		$table = $this->wire()->modules->get('MarkupAdminDataTable'); /** @var MarkupAdminDataTable $table */
		$table->setEncodeEntities(false);
		$table->headerRow([
			$this->_('Created'),
			$this->label('status'),
			$this->_('Type / task'),
			$this->_('Agent'),
			$this->_('User'),
			$this->_('Finished'),
			$this->_('Action'),
		]);

		$qty = 0;
		foreach($this->at->jobs()->getRecentJobs(100) as $job) {
			$id = (string) ($job['id'] ?? '');
			if($id === '') continue;
			$url = $this->url('view-job/?id=' . rawurlencode($id));
			$status = (string) ($job['status'] ?? '');
			$table->row([
				$this->formatJobTime((int) ($job['created'] ?? 0)),
				$this->formatJobStatus($status),
				$this->formatJobType($job),
				htmlspecialchars($this->getJobAgentShortLabel($job)),
				htmlspecialchars((string) ($job['userName'] ?? '')),
				$this->formatJobTime((int) ($job['finished'] ?? 0)),
				"<a href='$url'>" . htmlspecialchars($this->_('View')) . "</a>",
			]);
			$qty++;
		}

		if(!$qty) $table->row([ $this->_('No background jobs found.') ], [ 'colspan' => 7 ]);

		return $table->render();
	}

	/**
	 * Restore a completed background job conversation and redirect to Engineer.
	 *
	 * @return string
	 *
	 */
	public function executeReplyJob(): string {
		$input = $this->wire()->input;
		$session = $this->wire()->session;
		$id = (string) $input->get('id');
		$job = $this->getViewableJob($id, true);
		$url = $this->url('engineer/');
		if(!$job) {
			$session->error($this->label('background-job-not-found'));
			$session->location($url);
		}

		if(!empty($job['history']) && is_array($job['history'])) {
			$session->set('at_engineer_history', $job['history']);
			$session->set('at_engineer_prefill', '');
			$user = $this->wire()->user;
			$meta = $user->meta('AgentTools') ?: [];
			$meta['engineer_memory'] = 'yes';
			$modelIndex = $this->getJobModelIndex($job);
			if($modelIndex !== null) $meta['engineer_model_index'] = $modelIndex;
			$user->meta('AgentTools', $meta);
			$session->message($this->_('Background job conversation loaded. Use the Engineer form to continue.'));
		} else {
			$session->error($this->_('This background job does not have conversation history to restore.'));
		}

		$session->location($url);
		return '';
	}

	/**
	 * View a background job result.
	 *
	 * @return string
	 *
	 */
	public function executeViewJob(): string {
		$input = $this->wire()->input;
		$id = (string) $input->get('id');
		$job = $this->getViewableJob($id);
		if(!$job) {
			$this->error($this->label('background-job-not-found'));
			return '';
		}

		$this->headline($this->label('background-job'));
		$this->breadcrumb($this->url('jobs/'), $this->label('jobs'));

		$form = $this->wire()->modules->get('InputfieldForm'); /** @var InputfieldForm $form */
		$sanitizer = $this->wire()->sanitizer;
		$items = [
			$this->label('status') => (string) ($job['status'] ?? ''),
			$this->_('Type') => (string) ($job['type'] ?? ''),
		];
		$agentLabel = $this->at->jobs()->getJobAgentLabel($job);
		if($agentLabel !== '') $items[$this->_('Agent')] = $agentLabel;
		if(!empty($job['dryRun'])) $items[$this->_('Mode')] = $this->_('Preview only');
		if(!empty($job['taskName'])) $items[$this->label('task')] = (string) $job['taskName'];
		if(!empty($job['fieldName'])) $items[$this->_('Field')] = (string) $job['fieldName'];
		if(!empty($job['notifyEmail'])) $items[$this->label('email')] = (string) $job['notifyEmail'];
		if(!empty($job['emailError'])) $items[$this->label('email-error')] = (string) $job['emailError'];
		if(!empty($job['migration'])) $items[$this->label('migration')] = basename($job['migration']);

		$out = '<h2 class="uk-margin-top">' . $sanitizer->entities((string) ($job['id'] ?? '')) . '</h2>';
		$out .= '<ul class="uk-list uk-list-divider">';
		foreach($items as $label => $value) {
			$out .= '<li><strong>' . $sanitizer->entities($label) . ':</strong> ' . $sanitizer->entities($value) . '</li>';
		}
		if(!empty($job['pageEditUrl'])) {
			$pageTitle = trim((string) ($job['pageTitle'] ?? ''));
			if($pageTitle === '') $pageTitle = $this->_('Edit page');
			$out .= '<li><strong>' . $sanitizer->entities($this->_('Page')) . ':</strong> ' .
				'<a href="' . $sanitizer->entities((string) $job['pageEditUrl']) . '">' .
				$sanitizer->entities($pageTitle) .
				'</a></li>';
		}
		$out .= '</ul>';

		$ready = in_array((string) ($job['status'] ?? ''), [ 'done', 'failed' ], true);
		if(!$ready) {
			$out .= '<p class="uk-alert uk-alert-primary">' .
				$sanitizer->entities($this->_('This background job is not ready yet. Please refresh this page in a minute.')) .
				'</p>';
		}

		if(!empty($job['prompt'])) {
			$out .= '<h3>' . $this->label('prompt') . '</h3>';
			$out .= '<blockquote><p>' . nl2br($sanitizer->entities($job['prompt'])) . '</p></blockquote>';
		}

		if(!empty($job['error'])) {
			$out .= '<h3>' . $this->label('error') . '</h3>';
			$out .= $this->pre($job['error']);
		} else if(!empty($job['response'])) {
			$out .= '<h3>' . $this->label('response') . '</h3>';
			$out .= $this->formatEngineerResponse($job['response']);
		}

		if(!empty($job['migration'])) {
			$btn = $form->InputfieldButton;
			$btn->href = $this->url('view-migration/?name=' . urlencode(basename($job['migration'])));
			$btn->icon = 'database';
			$btn->val($this->label('review-and-apply-migration'));
			$btn->showInHeader(true);
			$form->add($btn);
		}

		if(($job['type'] ?? '') !== 'page-engineer' && !empty($job['history']) && is_array($job['history'])) {
			$btn = $form->InputfieldButton;
			$btn->href = $this->url('reply-job/?id=' . rawurlencode($job['id']));
			$btn->icon = 'reply';
			$btn->val($this->label('reply'));
			if(empty($job['migration'])) {
				$btn->showInHeader(true);
			} else {
				$btn->setSecondary();
			}
			$form->add($btn);
		}

		$btn = $form->InputfieldButton;
		$btn->href = $this->url('view-job/?id=' . rawurlencode($job['id']));
		$btn->icon = 'refresh';
		$btn->val($this->label('refresh'));
		if(!$ready) {
			$btn->showInHeader(true);
		} else {
			$btn->setSecondary();
		}
		$form->add($btn);

		$btn = $form->InputfieldButton;
		$btn->href = $this->url('jobs/');
		$btn->icon = 'arrow-left';
		$btn->val($this->label('back-to-jobs'));
		$btn->setSecondary();
		$form->add($btn);

		$form->val($out);
		return $form->render();
	}

	/**
	 * Build a URL relative to the AgentTools admin page.
	 *
	 * @param string $path
	 * @return string
	 *
	 */
	protected function url(string $path = ''): string {
		$page = $this->wire()->pages->get('template=admin, process=ProcessAgentTools');
		if(!$page->id) $page = $this->wire()->pages->get('template=admin, name=agent-tools');
		$url = $page->id ? $page->url : $this->wire()->page->url;
		return rtrim($url, '/') . '/' . ltrim($path, '/');
	}

	/**
	 * Format job timestamp.
	 *
	 * @param int $timestamp
	 * @return string
	 *
	 */
	protected function formatJobTime(int $timestamp): string {
		return $timestamp > 0 ? htmlspecialchars(wireRelativeTimeStr($timestamp)) : '';
	}

	/**
	 * Format job status.
	 *
	 * @param string $status
	 * @return string
	 *
	 */
	protected function formatJobStatus(string $status): string {
		if($status === AgentToolsJobs::statusDone) return $this->ukLabel($this->_('Done'), 'success');
		if($status === AgentToolsJobs::statusFailed) return $this->ukLabel($this->_('Failed'), 'danger');
		if($status === AgentToolsJobs::statusRunning) return $this->ukLabel($this->_('Running'), 'warning');
		if($status === AgentToolsJobs::statusPending) return $this->ukLabel($this->_('Pending'));
		return htmlspecialchars($status);
	}

	/**
	 * Format job type and task.
	 *
	 * @param array $job
	 * @return string
	 *
	 */
	protected function formatJobType(array $job): string {
		$type = (string) ($job['type'] ?? '');
		if(!empty($job['taskName'])) $type = (string) $job['taskName'];
		$type = htmlspecialchars($type);
		if(!empty($job['scheduledTask'])) {
			$type .= " <span class='detail'>(" . $this->_('scheduled') . ")</span>";
		}
		return $type;
	}

	/**
	 * Get short agent label for table display.
	 *
	 * @param array $job
	 * @return string
	 *
	 */
	protected function getJobAgentShortLabel(array $job): string {
		$label = trim((string) ($job['agentLabel'] ?? ''));
		if($label !== '') return $label;
		$model = trim((string) ($job['agentModel'] ?? ''));
		if($model !== '') return $model;
		if(!empty($job['agentId'])) {
			$agent = $this->at->getAgents()->getById((string) $job['agentId']);
			if($agent) return (string) $agent->get('label|model');
		}
		return '';
	}

	/**
	 * Get current Engineer model selector index for a job.
	 *
	 * @param array $job
	 * @return int|null
	 *
	 */
	protected function getJobModelIndex(array $job): ?int {
		$availableModels = $this->at->engineer->getAvailableModels();
		$agentId = (string) ($job['agentId'] ?? '');
		if($agentId !== '') {
			foreach($availableModels as $index => $entry) {
				if((string) ($entry['id'] ?? '') === $agentId) return (int) $index;
			}
		}
		if(isset($job['modelIndex']) && isset($availableModels[(int) $job['modelIndex']])) {
			return (int) $job['modelIndex'];
		}
		return null;
	}

	/**
	 * Get a viewable background job or throw if forbidden.
	 *
	 * @param string $id
	 * @param bool $completedOnly Search only completed statuses?
	 * @return array
	 * @throws WirePermissionException
	 *
	 */
	protected function getViewableJob(string $id, bool $completedOnly = false): array {
		$statuses = $completedOnly ? [ 'done', 'failed' ] : [ 'done', 'failed', 'running', 'pending' ];
		$job = $this->at->jobs()->getJob($id, $statuses);
		if(!$job) return [];
		$user = $this->wire()->user;
		if(!empty($job['userId']) && (int) $job['userId'] !== (int) $user->id && !$user->isSuperuser()) {
			throw new WirePermissionException('You do not have permission to view this background job.');
		}
		return $job;
	}
}
