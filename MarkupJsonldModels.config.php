<?php namespace ProcessWire;

/**
 * Markup JSON-LD Models Configuration
 *
 */

class MarkupJsonldModelsConfig extends ModuleConfig {

	/**
	 * Returns default values for module variables
	 *
	 * @return array
	 *
	 */
	public function getDefaults() {
		return [
			'jsonld_model' => '',
			'placeholders_ignore' => 'search_term_string',
			'engineer_instructions' => '',
		];
	}

	/**
	 * Returns inputs for module configuration
	 *
	 * @return InputfieldWrapper
	 *
	 */
	public function getInputfields() {

		$cache = $this->wire()->cache;
		$input = $this->wire()->input;
		$modules = $this->wire()->modules;
		$pages = $this->wire()->pages;

		$markupJsonldModels = $modules->get(str_replace('Config', '', $this->className));

		// If the cache clear button was pressed or the default model was changed, clear the cache and reload the page
		if($input->post->bool('clearCache') || ($input->post('submit_save_module') && $input->post('_jsonld_model_previous') !== $markupJsonldModels->jsonld_model)) {
			$markupJsonldModels->clearCache();
			$markupJsonldModels->message($this->_('Cache cleared'));
			$this->wire()->session->redirect($input->url(true));
		}

		$inputfields = parent::getInputfields();

		$overtype = false;

		$inputfields->add([
			'type' => 'textarea',
			'name' => 'jsonld_model',
			'label' => $this->_('Default JSON-LD Model'),
			'description' => $this->_('Enter a default JSON-LD model to be used for an enabled page when no other model is specified.'),
			'notes' => $this->_('For instructions on how to define a JSON-LD model with placeholders, please refer to the README.'),
			'icon' => 'code',
			'rows' => 20,
		]);

		$inputfields->add([
			'type' => 'textarea',
			'name' => 'placeholders_ignore',
			'label' => $this->_('Placeholders to Ignore'),
			'description' => $this->_('Enter a list of placeholder names that should be ignored (left as they are in your model) when generating JSON-LD models.'),
			'notes' => $this->_('Please enter one placeholder name per line.'),
			'icon' => 'leaf',
		]);

		$inputfields->add([
			'type' => 'hidden',
			'name' => '_jsonld_model_previous',
			'value' => $markupJsonldModels->jsonld_model,
		]);

		if($modules->isInstalled('AgentTools')) {
			$overtype = true;
			$inputfields->add([
				'type' => 'textarea',
				'name' => 'engineer_instructions',
				'label' => $this->_('Notes for Agent Tools'),
				'description' => $this->_('Enter any notes for Agent Tools to provide context about the site. This could include information about the types of content on the site, the intended audience, or any other relevant details that could help an AI agent understand how to generate effective JSON-LD models.'),
				'notes' => $this->_('Use the toolbar to format this text with markdown.'),
				'icon' => 'sticky-note',
				'rows' => 10,
			]);
		}

		$markupJsonldModels->loadScripts($overtype);

		$labelPage = $this->_('Page');
		$labelTemplate = $this->_('Template');

		$rows = [
			'page' => [],
			'template' => [],
		];

		$_editButton = function($url) use ($modules) {
			$button = $modules->get('InputfieldButton');
			$button->href = $url;
			$button->attr('target', '_blank');
			$button->value = '';
			$button->icon = 'pencil';
			$button->small = true;
			return $button->render();

		};

		$modelPagesSelectors = ['include' => 'hidden'];
		if($this->wire()->fields->get('jsonld_model')) {
			$modelPagesSelectors['jsonld_model'] = '!=';
		}
		$allModelPages = $pages->find($modelPagesSelectors);
		foreach($markupJsonldModels->getTemplates() as $template) {

			$modelPages = $allModelPages->find("template=$template");
			$byPage = $modelPages->count;

			if($template->jsonld_model) {

				$cached = $cache->getFor($markupJsonldModels, "{$template->name}.*");

				$rows['template'][] = [
					$template->label ? "$template->label ({$template->name})" : $template->name,
					$pages->count("template=$template,include=hidden") - $byPage,
					is_array($cached) ? count($cached) : 0,
					$_editButton("$template->editUrl#{$markupJsonldModels->className}"),
				];
			}

			if($byPage) {
				foreach($modelPages as $page) {
					if(!$page->jsonld_model) continue;
					$rows['page'][] = [
						$page->title,
						"<a href=\"$page->url\" target=\"_blank\">$page->url</a>",
						$cache->getFor($markupJsonldModels, "{$page->template->name}.{$page->id}") ?
							$this->_('Yes') :
							$this->_('No'),
						$_editButton($page->editUrl)
					];
				}
			}
		}

		$out = '';
		foreach($rows as $type => $typeRows) {

			if(empty($typeRows)) continue;

			usort($typeRows, function($a, $b) {
				return strcmp($a[0], $b[0]);
			});

			$table = $modules->get('MarkupAdminDataTable');
			$table->setEncodeEntities(false);

			$table->caption = '';
			$out .= '<h3>' . sprintf($this->_('Defined by %s'), $type === 'template' ? $labelTemplate : $labelPage) . '</h3>';

			$headers = [];
			switch($type) {
				case 'template':
					$headers = [
						$labelTemplate,
						$this->_('# Pages'),
						$this->_('# Cached'),
					];
					break;
				case 'page':
					$headers = [
						$this->_('Title'),
						$this->_('URL'),
						$this->_('Cached'),
					];
					break;
			}

			$headers[] = $this->_('Edit');

			$table->headerRow($headers);
			foreach($typeRows as $row) {
				$table->row($row);
			}

			$table->addClass('uk-table-justify');
			$table->setColNotSortable(count($headers) - 1);

			$out .= $table->render();
		}

		$infoNotes = '';

		$allCached = $cache->getFor($markupJsonldModels, '*');
		$totalCached = is_array($allCached) ? count($allCached) : 0;
		if($totalCached > 0) {

			$out .= '<p>' . sprintf(
				$this->_n(
					'There is currently %d cached JSON-LD model.',
					'There are currently %d cached JSON-LD models.',
					$totalCached
				),
				$totalCached
			) . '</p>';

			$clearButton = $modules->get('InputfieldSubmit');
			$clearButton->attr('name+id', 'clearCache');
			$clearButton->value = $this->_('Clear Cache');
			$out .= $clearButton->render();

		} else if(!empty($out) && $this->wire()->config->debug) {
			$infoNotes .= $this->_('Cache is disabled in debug mode, so no cached models are being stored.');
		}

		if(!empty($out)) {
			$inputfields->add([
				'type' => 'markup',
				'name' => '_jsonld_model_info',
				'label' => $this->_('JSON-LD Models'),
				'description' => $this->_('A summary of the configured JSON-LD models.'),
				'notes' => $infoNotes,
				'icon' => 'info',
				'value' => $out,
			]);
		}

		return $inputfields;
	}
}
