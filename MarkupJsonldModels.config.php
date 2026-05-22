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
		];
	}

	/**
	 * Returns inputs for module configuration
	 *
	 * @return InputfieldWrapper
	 *
	 */
	public function getInputfields() {

		$modules = $this->wire()->modules;
		$pages = $this->wire()->pages;

		$markupJsonldModels = $modules->get(str_replace('Config', '', $this->className));

		$markupJsonldModels->loadScripts();

		$inputfields = parent::getInputfields();

		$inputfields->add([
			'type' => 'textarea',
			'name' => 'jsonld_model',
			'label' => $this->_('Default JSON-LD Model'),
			'description' => $this->_('Enter a default JSON-LD model to be used for an enabled page when no other model is specified.'),
			'notes' => $this->_('For instructions on how to define a JSON-LD model with placeholders, please refer to the README.'),
			'icon' => 'code',
			'rows' => 20,
		]);

		$labelPage = $this->_('Page');
		$labelTemplate = $this->_('Template');

		$rows = [
			'page' => [],
			'template' => [],
		];
		$_editLink = function($url, $label) {
			$label = sprintf($this->_('Edit %s'), $label);
			return "<a href='$url' target='_blank'>$label</a>";
		};
		foreach($markupJsonldModels->getTemplates() as $id => $tpl) {

			$modelPages = $pages->find("template=$id,jsonld_model!=");
			$byPage = $modelPages->count;

			$template = $this->wire()->templates->get($id);
			if($template->jsonld_model) {
				$rows['template'][] = [
					$template->label ? "$template->label ({$tpl})" : $tpl,
					$pages->count("template=$id,include=hidden") - $byPage,
					$_editLink($template->editUrl, $labelTemplate)
				];
			}

			if($byPage) {
				foreach($modelPages as $page) {
					$rows['page'][] = [
						$page->title,
						$page->url,
						$_editLink($page->editUrl, $labelPage)
					];
				}
			}
		}

		$out = '';
		foreach($rows as $type => $typeRows) {

			ksort($typeRows);

			$table = $modules->get('MarkupAdminDataTable');
			$table->setEncodeEntities(false);

			$table->caption = sprintf($this->_('Defined by %s'), $type === 'template' ? $labelTemplate : $labelPage);

			$headers = [];
			switch($type) {
				case 'template':
					$headers = [
						$labelTemplate,
						$this->_('Page Count'),
					];
					break;
				case 'page':
					$headers = [
						$this->_('Title'),
						$this->_('URL'),
					];
					break;
			}

			$headers[] = '';

			$table->headerRow($headers);
			foreach($typeRows as $row) {
				$rowOptions = [];
				if(isset($options['rowClasses'][$index])) {
					$rowOptions['class'] = $options['rowClasses'][$index];
				}
				$table->row($row, $rowOptions);
			}

			$table->setColNotSortable(count($headers) - 1);

			// $table->removeClass('uk-table-justify');

			$out .= $table->render();
		}

		if(count($rows)) {

			$inputfields->add([
				'type' => 'markup',
				'name' => 'jsonld_model_info',
				'label' => $this->_('JSON-LD Models'),
				'description' => $this->_('A summary of the configured JSON-LD models.'),
				'icon' => 'info',
				'value' => $out,
			]);
		}

		return $inputfields;
	}
}
