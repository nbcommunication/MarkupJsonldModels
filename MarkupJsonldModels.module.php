<?php namespace ProcessWire;

/**
 * Markup JSON-LD Models
 *
 * #pw-summary Allows defining JSON-LD models on a per-page and per-template basis with placeholder support.
 *
 * @copyright 2026 NB Communication Ltd
 * @license Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 *
 * @param string $jsonld_model Default JSON-LD model to use if not set on page or template
 *
 */

class MarkupJsonldModels extends WireData implements Module, ConfigurableModule {

	// /**
	//  * Initialize the module
	//  *
	//  */
	// public function init() {

	// }

	/**
	 * When ProcessWire is ready
	 *
	 */
	public function ready() {

		$page = $this->wire()->page;


		if($page->rootParent->id === $this->wire()->config->adminRootPageID) {

			// If the admin, add hooks to page and template edit forms

			switch((string) $page->process) {
				case 'ProcessPageEdit':
					$this->addHookAfter('ProcessPageEdit::buildForm', $this, 'buildEditPageForm');
					break;
				case 'ProcessTemplate':
					$this->addHookAfter('ProcessTemplate::buildEditForm', $this, 'buildEditTemplateForm');
					$this->addHookBefore('Templates::save', $this, 'buildEditTemplateFormSave');
					break;
			}

		} else {

			// Create a custom property so the page's JSON-LD model can be accessed with $page->jsonldModel
			$this->addHookProperty('Page::jsonldModel', function(HookEvent $event) {
				$page = $event->object;
				$jsonld = $page->jsonld_model ?: $page->template->jsonld_model ?: $this->jsonld_model;
				$jsonld = $this->populateModel($jsonld, $page);
				$event->return = $jsonld;
			});

			// If not the admin, add hook to page render to add JSON-LD to the head
			$this->addHookAfter('Page::render', $this, 'appendJsonldToHead');
		}
	}

	/**
	 * Get field names for a page, excluding fieldsets
	 *
	 * @param Page $page
	 * @return array
	 *
	 */
	public function ___getPageFields(Page $page) {
		$fields = [];
		foreach($page->getFields() as $field) {
			if(
				$field->type instanceof FieldtypeFieldsetTabOpen ||
				$field->type instanceof FieldtypeFieldsetClose ||
				$field->type instanceof FieldtypeRepeater ||
				$field->name === 'jsonld_model' // exclude the jsonld_model field itself to avoid confusion
			) {
				continue;
			}
			$fields[$field->id] = $field->name;
		}
		return $fields;
	}

	/**
	 * Get templates that can be used with JSON-LD models (non-system HTML templates)
	 *
	 * @return array
	 *
	 */
	public function ___getTemplates() {
		$templates = [];
		foreach($this->wire()->templates as $template) {
			if($template->flags & Template::flagSystem) {
				continue;
			}
			$contentType = $template->contentType;
			if($contentType && $contentType !== 'html') {
				continue;
			}
			$templates[$template->id] = $template->name;
		}
		return $templates;
	}

	/**
	 * Load scripts for the module to use CodeMirror 6 in the admin for the JSON-LD model textarea
	 *
	 */
	public function loadScripts() {
		$config = $this->wire()->config;
		$url = $config->urls($this);
		$config->scripts->add("{$url}cm6.bundle.min.js");
		$config->scripts->add("{$url}cm6.init.js");
	}

	/**
	 * Populate JSON-LD model with breadcrumb values
	 *
	 * @param string $jsonld
	 * @param Page $page
	 * @return string
	 *
	 * @todo think about whether this should be hookable, or rather move page->parent->add($page) to a separate method that can be used in the hook and also in the default implementation, to allow for custom breadcrumb structures (e.g. including siblings, etc.)
	 *
	 */
	public function ___populateBreadcrumbList($jsonld, Page $page) {

		if(strpos($jsonld, '{breadcrumbList}') !== false) {

			$breadcrumbs = [];
			foreach($page->parents->add($page) as $parent) {
				$breadcrumbs[] = [
					'@type' => 'ListItem',
					'position' => count($breadcrumbs) + 1,
					'name' => $parent->title,
					'item' => $parent->httpUrl,
				];
			}

			$jsonld = str_replace(
				'"{breadcrumbList}"',
				json_encode($breadcrumbs),
				$jsonld
			);
		}

		return $jsonld;

	}

	/**
	 * Populate JSON-LD model with page values
	 *
	 * @param string $jsonld
	 * @param Page $page
	 * @return string
	 *
	 */
	public function ___populateModel($jsonld, Page $page) {

		if(empty($jsonld) || strpos($jsonld, '{') === false) {
			return $jsonld;
		}

		// Populate Page breadcrumb items
		$jsonld = $this->populateBreadcrumbList($jsonld, $page);

		// Populate setting() vars
		$jsonld = $this->populatePlaceholders($jsonld, setting(), [
			'prefix' => 'setting',
		]);

		// Populate any other wire-defined objects (e.g. user, page, etc.)
		// only if the placeholder matches the format {object.field}
		if(preg_match_all('/\{([a-zA-Z0-9_.]+\.[a-zA-Z0-9_.]+)\}/', $jsonld, $matches)) {

			foreach($matches[1] as $placeholder) {

				$parts = explode('.', $placeholder, 2);
				$prefix = $parts[0];

				if($this->wire()->$prefix) {
					$jsonld = $this->populatePlaceholders($jsonld, $this->wire()->$prefix, [
						'prefix' => $prefix,
					]);
				}
			}
		}

		return $this->populatePlaceholders($jsonld, $page, [
			'removeNullTags' => true,
			'removeEmptyTags' => true,
		]);
	}

	/**
	 * Convert a Pagefile to an array of values for JSON-LD
	 *
	 * @param Pagefile $pagefile
	 * @return array
	 *
	 */
	public function ___populatePagefile(Pagefile $pagefile) {
		return [
			'@context' => 'https://schema.org',
			'@type' => 'DigitalDocument',
			'name' => $pagefile->description,
			'contentUrl' => $pagefile->httpUrl,
			'contentSize' => $pagefile->filesizeStr,
		];
	}

	/**
	 * Convert a Pageimage to an array of values for JSON-LD
	 *
	 * @param Pageimage $pageimage
	 * @return array
	 *
	 */
	public function ___populatePageimage(Pageimage $pageimage) {
		return [
			'@context' => 'https://schema.org',
			'@type' => 'ImageObject',
			'url' => $pageimage->httpUrl,
			'width' => $pageimage->width,
			'height' => $pageimage->height,
			'name' => $pageimage->description,
		];
	}

	/**
	 * Populate placeholders in a string with values from an array or object
	 *
	 * @param string $jsonld
	 * @param array|object $vars
	 * @param array $options Same options as WireTextTools::populatePlaceholders with the addition of:
	 *  - prefix: If set, only populate placeholders that start with this prefix (e.g. {page.title} with prefix 'page' would only populate {page.*} placeholders)
	 *  - truncate: If true, truncate values to 100 characters. If an integer is provided, truncate to that number of characters.
	 * @return string
	 *
	 */
	public function populatePlaceholders($jsonld, $vars, array $options = []) {

		$prefix = $options['prefix'] ?? null;
		if($prefix) {

			unset($options['prefix']);

			$prefixPlaceholder = '{' . $prefix . '.';
			if(strpos($jsonld, $prefixPlaceholder) === false) {
				return $jsonld;
			}

			// Check vars and if convert to a string if necessary
			$placeholderVars = [];
			if(preg_match_all('/\{' . preg_quote($prefix) . '\.([a-zA-Z0-9_.]+)\}/', $jsonld, $matches)) {

				$removeQuotes = [];
				foreach(array_unique($matches[1]) as $field) {

					$isPage = $vars instanceof Page;

					$value = '';
					if($isPage) {

						if(preg_match('/\.(first|last|[0-9]+)$/', $field, $match)) {
							// Handle {page.field.0}, {page.field.first}, {page.field.last}
							$fieldName = substr($field, 0, strrpos($field, '.'));
							$index = $match[1];
							$fieldValue = $vars->getUnformatted($fieldName);
							if($index === 'first') {
								$value = $fieldValue->first();
							} else if($index === 'last') {
								$value = $fieldValue->last();
							} else {
								$value = $fieldValue->eq($index) ?: '';
							}

						} else {
							$value = $vars->getUnformatted($field);
						}

					} else if($vars instanceof Wire || $vars instanceof WireData) {
						$value = $vars->get($field);
					} else if(is_object($vars) && isset($vars->$field)) {
						$value = $vars->$field;
					} else if(is_array($vars) && isset($vars[$field])) {
						$value = $vars[$field];
					}

					if($value !== '' && !is_string($value)) {

						$key = "{$prefix}.{$field}";
						if(is_object($value)) {

							if($value instanceof Pageimages) {

								$removeQuotes[] = $key;
								$value = json_encode(array_values($value->explode(function($pageimage) {
									return $this->populatePageimage($pageimage);
								})));

							} else if($value instanceof Pageimage) {

								$removeQuotes[] = $key;
								$value = json_encode($this->populatePageimage($value));

							} else if($value instanceof Pagefiles) {

								$removeQuotes[] = $key;
								$value = json_encode(array_values($value->explode(function($pagefile) {
									return $this->populatePagefile($pagefile);
								})));

							} else if($value instanceof Pagefile) {

								$removeQuotes[] = $key;
								$value = json_encode($this->populatePagefile($value));

							} else if(method_exists($value, 'jsonSerialize')) {

								$removeQuotes[] = $key;
								$value = json_encode($value->jsonSerialize());

							} else if(method_exists($value, '__toString')) {

								$value = (string) $value;

							} else {

								$removeQuotes[] = $key;
								$value = json_encode(get_object_vars($value));
							}

						} else if(is_array($value)) {

							$removeQuotes[] = $key;
							$value = json_encode($value);

						} else if(in_array($field, ['created', 'modified', 'published', 'unpublished'])) {

							// If the field is a system date field, convert to ISO 8601 format for JSON-LD
							$value = date('c', $value);

						} else if(is_int($value) && $isPage && $vars->getField($field)->type instanceof FieldtypeDatetime) {

							// If the field is a date field, convert to ISO 8601 format for JSON-LD
							$value = date('c', $value);

						} else {
							$removeQuotes[] = $key; // int, float, bool, null should not be quoted in the JSON-LD output
						}

						if(is_bool($value)) {
							$value = $value ? 'true' : 'false';
						} else if(is_null($value)) {
							$value = '';
						}
					}

					$truncate = $options['truncate'] ?? false;
					if($truncate) {
						if(!is_int($truncate)) {
							$truncate = 100;
						}
						if(is_string($value) && strlen($value) > $truncate) {
							$value = $this->wire()->sanitizer->truncate($value, $truncate);
						}
					}

					$placeholderVars[$field] = $value;
				}

				foreach($removeQuotes as $key) {
					$jsonld = str_replace(
						'"{' . $key . '}"',
						'{' . $key . '}',
						$jsonld
					);
				}

				$vars = $placeholderVars;

			} else {
				return $jsonld;
			}

			$jsonld = str_replace(
				$prefixPlaceholder,
				'{',
				$jsonld
			);
		}

		return $this->wire()->sanitizer->getTextTools()->populatePlaceholders($jsonld, $vars, array_merge([
			'removeNullTags' => false,
			'removeEmptyTags' => false,
		], $options));
	}

	/**
	 * Build the Template edit form to add JSON-LD model textarea
	 *
	 * @param HookEvent $event
	 *
	 */
	protected function buildEditTemplateForm(HookEvent $event) {

		$template = $event->arguments(0);

		if(!isset($this->getTemplates()[$template->id])) {
			return;
		}

		$form = $event->return;

		$tabs = $form->find('id=tab_files');
		if($tabs->count) {

			$this->loadScripts();

			$placeholders = [];
			foreach($this->getPageFields($this->wire()->pages->get("template=$template")) as $fieldId => $fieldName) {
				$placeholders[] = sprintf(
					'`{page.%1$s}`: %2$s',
					$fieldName,
					$this->wire()->fields->get($fieldId)->label ?: $fieldName
				);
			}

			$tab = $this->wire(new InputfieldWrapper());
			$tab->attr('title', $this->_('JSON-LD'));
			$tab->attr('id', $this->className);
			$tab->attr('class', 'WireTab');
			$tab->add([
				'type' => 'textarea',
				'name' => 'jsonld_model',
				'label' => $this->_('JSON-LD Model'),
				// 'description' => $this->_('Define a JSON-LD model using placeholders like {{page.title}} or {{setting.name}}'),
				'notes' => sprintf(
				$this->_('You can use the following placeholders to populate existing page values: %s'),
				"\n" .
				implode("\n", $placeholders)
			),
				'icon' => 'code',
				'rows' => 20,
				'collapsed' => 2,
				'value' => $template->jsonld_model,
			]);

			$form->insertAfter($tab, $tabs->first);
		}

		$event->return = $form;
	}

	/**
	 * Save the JSON-LD model when the Template edit form is saved
	 *
	 * @param HookEvent $event
	 *
	 */
	protected function buildEditTemplateFormSave(HookEvent $event) {
		$event->arguments(0)->set('jsonld_model', json_encode(json_decode($this->wire()->input->post->textarea('jsonld_model'))) ?: '');
	}

	/**
	 * Build the page edit form for pages with the jsonld_model field
	 *
	 * @param HookEvent $event
	 *
	 */
	protected function buildEditPageForm(HookEvent $event) {

		$form = $event->return;

		$page = $this->wire()->pages->get($this->wire()->input->get->int('id'));
		if($page->id && $page->hasField('jsonld_model')) {

			$jsonldModelField = $form->get('jsonld_model'); // todo what if it is in a repeater?
			if($jsonldModelField) {

				$this->loadScripts();

				$existingNotes = $jsonldModelField->notes;
				if($existingNotes) {
					$existingNotes .= "\n\n";
				}

				$placeholders = [];
				foreach($this->getPageFields($page) as $fieldId => $fieldName) {

					$value = $page->get($fieldName);
					if(empty($value)) continue;

					$valueRaw = $page->getUnformatted($fieldName);
					if($valueRaw instanceof WireArray && !$valueRaw->count()) continue;

					$placeholders[] = sprintf(
						'`{page.%1$s}`: %2$s',
						$fieldName,
						$this->populatePlaceholders('{page.' . $fieldName . '}', $page, [
							'prefix' => 'page',
							'truncate' => true,
						])
					);
				}

				$existingNotes .= sprintf(
					$this->_('You can use the following placeholders to populate existing page values: %s'),
					"\n" .
					implode("\n", $placeholders)
				);
				$jsonldModelField->notes = $existingNotes;
			}
		}

		$event->return = $form;
	}

	/**
	 * Append JSON-LD script to the head of the page if a model is defined
	 *
	 * @param HookEvent $event
	 *
	 */
	protected function appendJsonldToHead(HookEvent $event) {

		$page = $event->object;
		$html = $event->return;
		$contentType = $page->template->contentType;

		if(
			// Not HTML or missing HTML tags
			($contentType && $contentType !== 'html') ||
			strpos($html, '</head>') === false ||
			strpos($html, '</html>') === false ||
			strpos($html, '</body>') === false ||
			// Page already has JSON-LD in the head
			strpos(explode('</head>', $event->return)[0], 'application/ld+json') !== false
		) {
			return;
		}

		$jsonld = $page->jsonldModel;
		if(empty($jsonld)) {
			return;
		}

		// An array of JSON-LD objects should be wrapped in a @graph
		if(substr($jsonld, 0, 1) === '[' && substr($jsonld, -1) === ']') {
			$jsonld = "{\"@context\": \"https://schema.org\",\"@graph\": $jsonld}";
		}

		$data = json_decode($jsonld, true) ?: [];
		if(empty($data)) {
			if($this->wire()->config->debug && $this->wire()->user->isSuperUser()) {
				$this->wire()->log("Invalid JSON-LD model for page {$page->id}: $jsonld");
			}
			return;
		}

		$event->return = str_replace(
			'</head>',
			'<script type="application/ld+json">' .
				json_encode($data, ($this->wire()->user->isSuperUser() ? JSON_PRETTY_PRINT : 0)) .
			'</script>' .
			'</head>',
			$html
		);
	}

	/**
	 * Install MarkupJsonldModels
	 *
	 */
	public function ___install() {
		// Add jsonld_model field if it doesn't exist
		$fields = $this->wire()->fields;
		if(!$fields->get('jsonld_model')) {
			$field = $fields->new('textarea', 'jsonld_model', [
				'label' => $this->_('JSON-LD Model'),
				'icon' => 'code',
				'contentType' => 1,
				'collapsed' => 2,
				'rows' => 20,
			]);
		}
	}

	/**
	 * Uninstall MarkupJsonldModels
	 *
	 */
	public function ___uninstall() {
		// Remove jsonld_model field if it exists
		$fields = $this->wire()->fields;
		$field = $fields->get('jsonld_model');
		if($field) {
			$fields->delete($field);
		}
	}

}
