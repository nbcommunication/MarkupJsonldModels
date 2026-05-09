<?php namespace ProcessWire;

/**
 * Markup JSON-LD Models
 *
 * #pw-summary Allows defining JSON-LD models on a per-page and per-template basis with placeholder support.
 *
 * @param string $jsonld Default JSON-LD model to use if not set on page or template
 *
 * @license Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 *
 * @copyright 2026 NB Communication Ltd
 */

class MarkupJsonldModels extends WireData implements Module, ConfigurableModule {

	/**
	 * Available schema types
	 *
	 * @return array
	 *
	 */
	protected function getSchemaTypes() {
		return array(
			'' => $this->_('Default'),
			'Article' => $this->_('Article'),
			'BlogPosting' => $this->_('BlogPosting'),
			'NewsArticle' => $this->_('NewsArticle'),
			'Product' => $this->_('Product'),
			'Event' => $this->_('Event'),
			'Organization' => $this->_('Organization'),
			'LocalBusiness' => $this->_('LocalBusiness'),
			'Person' => $this->_('Person'),
			'WebPage' => $this->_('WebPage'),
			'FAQPage' => $this->_('FAQPage'),
		);
	}

	/**
	 * Schema fields that can be mapped for each schema type
	 *
	 * @return array
	 *
	 */
	protected function getSchemaFields() {
		return array(
			'Article' => array(
				'headline',
				'description',
				'image',
				'author',
				'datePublished',
				'dateModified',
			),
			'BlogPosting' => array(
				'headline',
				'description',
				'image',
				'author',
				'datePublished',
				'dateModified',
			),
			'NewsArticle' => array(
				'headline',
				'description',
				'image',
				'author',
				'datePublished',
				'dateModified',
			),
			'Product' => array(
				'name',
				'description',
				'image',
				'brand',
				'sku',
			),
			'Event' => array(
				'name',
				'description',
				'image',
				'startDate',
				'endDate',
				'location',
			),
			'Organization' => array(
				'name',
				'description',
				'url',
				'logo',
			),
			'LocalBusiness' => array(
				'name',
				'description',
				'image',
				'telephone',
				'address',
			),
			'Person' => array(
				'name',
				'description',
				'image',
				'jobTitle',
			),
			'WebPage' => array(
				'name',
				'description',
				'image',
			),
			'FAQPage' => array(
				'name',
				'description',
			),
		);
	}

	/**
	 * Build module configuration inputs
	 *
	 * @param array $data
	 * @return InputfieldWrapper
	 *
	 */
	public function getModuleConfigInputfields(array $data) {

		$inputfields = new InputfieldWrapper();

		$modules = $this->wire()->modules;
		$fields = $this->wire()->fields;

		$fieldOptions = array(
			'' => $this->_('Do not map'),
		);

		foreach($fields as $field) {
			$fieldOptions[$field->name] = $field->name . ' — ' . $field->label;
		}

		$schemaFields = $this->getSchemaFields();

		foreach($schemaFields as $schemaType => $properties) {
			$fieldset = $modules->get('InputfieldFieldset');
			$fieldset->label = sprintf($this->_('%s field mappings'), $schemaType);
			$fieldset->collapsed = Inputfield::collapsedYes;

			foreach($properties as $property) {
				$name = 'schema_field_map__' . $schemaType . '__' . $property;

				$f = $modules->get('InputfieldSelect');
				$f->attr('name', $name);
				$f->label = $property;
				$f->description = sprintf(
					$this->_('Select the page field used for schema property "%s".'),
					$property
				);
				$f->addOptions($fieldOptions);

				if(isset($data[$name])) {
					$f->attr('value', $data[$name]);
				}

				$fieldset->add($f);
			}

			$inputfields->add($fieldset);
		}

		return $inputfields;
	}

	/**
	 * Get mapped JSON-LD values for a page and schema type
	 *
	 * @param Page $page
	 * @param string $schemaType
	 * @return array
	 *
	 */
	protected function getMappedSchemaData(Page $page, $schemaType) {

		$data = array();

		$schemaFields = $this->getSchemaFields();

		if(!$schemaType || !isset($schemaFields[$schemaType])) {
			return $data;
		}

		foreach($schemaFields[$schemaType] as $property) {
			$configName = 'schema_field_map__' . $schemaType . '__' . $property;
			$fieldName = $this->$configName;

			if(!$fieldName) {
				continue;
			}

			$value = $page->get($fieldName);
			$value = $this->schemaValueToString($value);

			if($value !== '') {
				$data[$property] = $value;
			}
		}

		return $data;
	}

	/**
	 * Convert a ProcessWire field value to a schema-safe scalar value
	 *
	 * @param mixed $value
	 * @return string
	 *
	 */
	protected function schemaValueToString($value) {

		if(is_string($value) || is_numeric($value)) {
			return (string) $value;
		}

		if($value instanceof Page) {
			return $value->title;
		}

		if($value instanceof PageArray && $value->count()) {
			$items = array();

			foreach($value as $page) {
				$items[] = $page->title;
			}

			return implode(', ', $items);
		}

		if($value instanceof WireArray && $value->count()) {
			$first = $value->first();

			if($first && isset($first->url)) {
				return $first->url;
			}
		}

		if(is_object($value) && isset($value->url)) {
			return $value->url;
		}

		return '';
	}

	/**
	 * Initialize the module
	 *
	 */
	public function init() {

		// Add hooks that do not depend on the current page being available
		$this->addHookAfter('ProcessPageEdit::buildForm', $this, 'buildPageEditForm');
		$this->addHookBefore('Pages::saveReady', $this, 'savePageSchemaType');
	}

	/**
	 * When ProcessWire is ready
	 *
	 */
	public function ready() {

		$page = $this->wire()->page;

		if(!$page) {
			return;
		}

		// If the admin
		if($page->rootParent->id === $this->wire()->config->adminRootPageID) {

			// Add hooks on template editor
			$process = 'ProcessTemplate';
			if($page->process === $process) {
				$this->addHookAfter("$process::buildEditForm", $this, 'buildEditForm');
				$this->addHookBefore('Templates::save', $this, 'buildEditFormSave');
			}
		}

		// If not the admin
		if($page->rootParent->id !== $this->wire()->config->adminRootPageID) {

			// Hook on page render to add JSON-LD to the head
			$this->addHookAfter('Page::render', function(HookEvent $event) {

				$page = $event->object;
				$html = $event->return;
				$contentType = $page->template->contentType;

//				if(
//					// Not HTML or missing HTML tags
//					($contentType && $contentType !== 'html') ||
//					strpos($html, '</head>') === false ||
//					strpos($html, '</html>') === false ||
//					strpos($html, '</body>') === false //||
//					// Page already has JSON-LD in the head
//					//strpos(explode('</head>', $event->return)[0], 'application/ld+json') !== false
//				) {
//                    echo "HTML missing";
//					return;
//				}

                $data = array();

                $jsonld = $page->jsonld ?: $page->template->jsonld ?: $this->jsonld;
                $schemaType = $page->meta('jsonld_schema_type');

                if($jsonld) {
                    $parsed = $this->parseJsonld($jsonld, $page);
                    if($parsed) {
                        $data = json_decode($parsed, true);
                    }
                }

				if($schemaType && empty($data)) {
					$data = array(
						'@context' => 'https://schema.org',
						'@type' => $schemaType,
					);
				}

				if(empty($data)) {
					return;
				}

				if($schemaType) {
					if(isset($data['@type'])) {
						$data['@type'] = $schemaType;
						$data = array_merge($data, $this->getMappedSchemaData($page, $schemaType));
					} else if(isset($data[0]) && is_array($data[0])) {
						$data[0]['@type'] = $schemaType;
						$data[0] = array_merge($data[0], $this->getMappedSchemaData($page, $schemaType));
					}
				}

				$event->return = str_replace(
					'</head>',
					'<script type="application/ld+json" class="output-json">' .
						json_encode($data) .
					'</script>' .
					'</head>',
					$html
				);
			});
		}
	}

	/**
	 * Add schema type selector to the page editor
	 *
	 * #pw-internal
	 * #pw-hooker
	 *
	 * @param HookEvent $event
	 *
	 */
	public function buildPageEditForm(HookEvent $event) {

		$form = $event->return;
		$page = $event->object->getPage();

		if(!$page || !$page->id || $page->template->flags & Template::flagSystem) {
			return;
		}

		$f = $this->wire()->modules->get('InputfieldSelect');
		$f->attr('name', 'jsonld_schema_type');
		$f->label = $this->_('JSON-LD Schema Type');
		$f->description = $this->_('Select the schema.org type to use for this page.');
		$f->addOptions($this->getSchemaTypes());
		$f->attr('value', $page->meta('jsonld_schema_type'));
		$f->collapsed = Inputfield::collapsedBlank;

		$tab = $form->get('ProcessPageEditContent');

		if($tab) {
			$tab->add($f);
		} else {
			$form->add($f);
		}

		$event->return = $form;
		$event->return = $form;
	}

	/**
	 * Save schema type selected on the page editor
	 *
	 * #pw-internal
	 * #pw-hooker
	 *
	 * @param HookEvent $event
	 *
	 */
	public function savePageSchemaType(HookEvent $event) {

		$page = $event->arguments(0);
		$input = $this->wire()->input;

		if(!$page || !$page->id) {
			return;
		}

		if(!isset($_POST['jsonld_schema_type'])) {
			return;
		}

		$schemaType = $input->post->text('jsonld_schema_type');
		$types = $this->getSchemaTypes();

		if(!array_key_exists($schemaType, $types)) {
			$schemaType = '';
		}

		$page->meta('jsonld_schema_type', $schemaType);
	}

	/**
	 * Build the MarkupJsonldModels configuration tab for the Template editor
	 *
	 * #pw-internal
	 * #pw-hooker
	 *
	 * @param HookEvent $event
	 * @return InputfieldForm
	 *
	 */
	public function buildEditForm(HookEvent $event) {

		$template = $event->arguments(0);

		// Don't show on system templates
		if($template->flags & Template::flagSystem) {
			return;
		}

		$form = $event->return;

		// insert jsonld textarea to the Advanced tab
        $tab = $form->get('tabAdvanced');
        if ($tab) {
            $f = $this->wire()->modules->get('InputfieldTextarea');
            $f->attr('name', 'jsonld');
            $f->label = $this->_('JSON-LD Model');
            $f->description = $this->_('Define a JSON-LD model using placeholders like {{page.title}} or {{setting.name}}');
            $f->attr('value', $template->jsonld);
            $f->rows = 10;
            $f->collapsed = Inputfield::collapsedBlank;
            $tab->add($f);
        }

        $event->return = $form;
	}

	/**
     * Parse JSON-LD template and replace placeholders
     *
     * @param string $jsonld
     * @param Page $page
     * @return string
     *
     */
    protected function parseJsonld($jsonld, Page $page)
    {

        return preg_replace_callback('/\{\{([a-zA-Z0-9_.]+)\}\}/', function ($matches) use ($page) {
            $parts = explode('.', $matches[1]);
            $obj = null;

            if ($parts[0] === 'page') {
                $obj = $page;
            } else if ($parts[0] === 'setting') {
                $obj = $this->wire()->pages->get('template=setting');
            }

            if ($obj && isset($parts[1])) {
                $value = $obj->get($parts[1]);
                return is_string($value) || is_numeric($value) ? $value : '';
            }

            return '';
        }, $jsonld);
    }

    /**
     * Save MarkupJsonldModels Template configuration
	 *
	 * #pw-internal
	 * #pw-hooker
	 *
	 * @param HookEvent $event
	 *
	 */
	public function buildEditFormSave(HookEvent $event) {
		$template = $event->arguments(0);
		$template->set('jsonld', $this->wire()->input->post->textarea('jsonld'));
	}

	/**
	 * Install MarkupJsonldModels
	 *
	 */
	public function ___install() {
		parent::___install();

		// Add jsonld field if it doesn't exist
		$fields = $this->wire()->fields;
		if(!$fields->get('jsonld')) {
			$f = new Field();
			$f->type = $this->wire()->modules->get('FieldtypeTextarea');
			$f->name = 'jsonld';
			$f->label = 'JSON-LD Model';
			$f->description = 'Define a JSON-LD model using placeholders like {{page.title}} or {{setting.name}}';
			$f->rows = 10;
			$f->save();
		}

		// Add jsonld_schema_type field if it doesn't exist
		if(!$fields->get('jsonld_schema_type')) {
			$f = new Field();
			$f->type = $this->wire()->modules->get('FieldtypeText');
			$f->name = 'jsonld_schema_type';
			$f->label = 'JSON-LD Schema Type';
			$f->description = 'Selected schema.org type for this page.';
			$f->save();
		}
	}

	/**
	 * Uninstall MarkupJsonldModels
	 *
	 */
	public function ___uninstall() {
		parent::___uninstall();

		// Remove jsonld field if it exists
		$fields = $this->wire()->fields;
		$f = $fields->get('jsonld');
		if($f) {
			$fields->delete($f);
		}

		// Remove jsonld_schema_type field if it exists
		$f = $fields->get('jsonld_schema_type');
		if($f) {
			$fields->delete($f);
		}
	}

}
