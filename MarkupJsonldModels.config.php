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
			'jsonld' => '',
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

		$inputfields = parent::getInputfields();

		// Get the module this configures
		$module = $modules->get(str_replace('Config', '', $this->className));

		// Remove Variations
		$inputfields->add([
			'type' => 'textarea',
			'name' => 'jsonld',
			'label' => $this->_('Default Model'),
			'description' => '',
			'notes' => '',
			'value' => '',
			'icon' => 'code',
		]);

		return $inputfields;
	}
}
