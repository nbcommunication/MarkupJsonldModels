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
 * @param string $engineer_instructions Notes for Agent Tools to provide context about the site
 *
 */

class MarkupJsonldModels extends WireData implements Module, ConfigurableModule {

	/** @var TemplatesArray */
	protected $eligibleTemplates;

	/**
	 * When ProcessWire is ready
	 *
	 */
	public function ready() {

		$page = $this->wire()->page;

		if($page->template->name === 'admin') {

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
				if(!$this->isEligible($page)) {
					return;
				}

				$event->return = $page->getUnformatted('jsonld_model') ?: $page->template->jsonld_model ?: $this->jsonld_model;
			});

			// Create a custom property so the populated JSON-LD model can be accessed with $page->jsonldOutput
			$this->addHookProperty('Page::jsonldOutput', function(HookEvent $event) {

				$page = $event->object;
				if(!$this->isEligible($page)) {
					return;
				}

				$_populate = function() use ($page) {
					return $this->populateModel($page->jsonldModel, $page);
				};

				$event->return = $this->wire()->config->debug ?
					$_populate() :
					$this->wire()->cache->getFor(
						$this,
						"{$page->template->name}.{$page->id}",
						"id={$page->id}", // expiry by selector so it is automatically cleared when the page is saved
						$_populate
					);
			});

			// If not the admin, add hook to page render to add JSON-LD to the head
			$this->addHookAfter('Page::render', $this, 'appendJsonldToHead');
		}
	}

	/**
	 * Clear all cached JSON-LD model outputs
	 *
	 * @param string $name Optional name to clear specific cache entries (e.g. "templateName.*" to clear all pages for a template, or "templateName.pageId" to clear a specific page)
	 */
	public function clearCache($name = '*') {
		$this->wire()->cache->deleteFor($this, $name);
	}

	/**
	 * Get breadcrumb list for a page in JSON-LD format
	 *
	 * @param Page $page
	 * @return array
	 *
	 */
	public function ___getBreadcrumbList(Page $page) {
		$breadcrumbs = [];
		foreach($this->getBreadcrumbPages($page) as $item) {
			$breadcrumbs[] = $this->getBreadcrumbListItem($item, count($breadcrumbs) + 1);
		}
		return $breadcrumbs;
	}

	/**
	 * Get a breadcrumb list item for a page in JSON-LD format
	 *
	 * @param Page $page
	 * @param int $position
	 * @return array
	 *
	 */
	public function ___getBreadcrumbListItem(Page $page, $position) {
		return [
			'@type' => 'ListItem',
			'position' => $position,
			'name' => $page->getUnformatted('title'),
			'item' => $page->httpUrl,
		];
	}

	/**
	 * Get breadcrumb pages for a page (parents + self)
	 *
	 * @param Page $page
	 * @return PageArray
	 *
	 */
	public function ___getBreadcrumbPages(Page $page) {
		return $page->parents->add($page);
	}

	/**
	 * Get field names for a page, excluding fieldsets
	 *
	 * @param Fieldgroup $fieldgroup
	 * @param Page|null $page Optional page to check for field values to exclude empty fields from the list
	 * @return FieldsArray
	 *
	 */
	public function ___getExampleFields(Fieldgroup $fieldgroup, Page $page = null) {
		$exampleFields = $this->wire(new FieldsArray());
		if(!$fieldgroup->count()) {
			return $exampleFields;
		}
		foreach($fieldgroup as $field) {
			if(
				$field->type instanceof FieldtypeDatetime ||
				$field->type instanceof FieldtypeDecimal ||
				$field->type instanceof FieldtypeFloat ||
				$field->type instanceof FieldtypeInteger ||
				$field->type instanceof FieldtypeText
			) {
				if($field->name === 'jsonld_model') { // exclude the jsonld_model field itself to avoid confusion
					continue;
				}
				if($page && $page->id) {
					$valueRaw = $page->getUnformatted($field->name);
					if(empty($valueRaw) || ($valueRaw instanceof WireArray && !$valueRaw->count())) continue;
				}
				$exampleFields->add($field);
			}
		}
		return $exampleFields;
	}

	/**
	 * Get templates that can be used with JSON-LD models (non-system HTML templates)
	 *
	 * @return TemplatesArray
	 *
	 */
	public function ___getTemplates() {
		$modelTemplates = $this->wire(new TemplatesArray());
		foreach($this->wire()->templates as $template) {
			if($template->flags & Template::flagSystem) {
				continue;
			}
			$contentType = $template->contentType;
			if($contentType && $contentType !== 'html') {
				continue;
			}
			$modelTemplates->add($template);
		}
		return $modelTemplates;
	}

	/**
	 * Load scripts for the module to use CodeMirror 6 in the admin for the JSON-LD model textarea
	 *
	 * Called internally and from MarkupJsonldModelsConfig
	 *
	 * @param bool $overtype If true, also load the Overtype plugin for the engineer_instructions field
	 *
	 */
	public function loadScripts($overtype = false) {
		$config = $this->wire()->config;
		$url = $config->urls($this) . 'scripts/';
		$config->scripts->add("{$url}cm6.bundle.min.js");
		$config->scripts->add("{$url}cm6.init.js");
		if($overtype) {
			$config->scripts->add("{$url}overtype.min.js");
			$config->scripts->add("{$url}overtype.init.js");
		}
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
		if(strpos($jsonld, '{breadcrumbList}') !== false) {
			$jsonld = str_replace(
				'"{breadcrumbList}"',
				json_encode($this->getBreadcrumbList($page)),
				$jsonld
			);
		}

		// Populate setting() vars
		if(strpos($jsonld, '{setting.') !== false) {
			$jsonld = $this->populatePlaceholders($jsonld, setting() ?: [], [
				'prefix' => 'setting',
			]);
		}

		// Populate $page vars
		if(strpos($jsonld, '{page.') !== false) {
			$jsonld = $this->populatePlaceholders($jsonld, $page, [
				'prefix' => 'page',
			]);
		}

		// Populate $input vars
		if(strpos($jsonld, '{input.') !== false) {
			$jsonld = $this->populatePlaceholders($jsonld, $this->wire()->input, [
				'prefix' => 'input',
			]);
		}

		return $this->populatePlaceholders($jsonld, $page, [
			'removeNullTags' => true, // Removes any placeholder tags (e.g. {page.null_field} -> "") that resolve to null
			'removeEmptyTags' => true, // Removes any placeholder tags (e.g. {page.empty_field} => "") that resolve to an empty string
			// These options do not remove the key/value pair if the placeholder is resolved to an empty string
			// For example {"field": "{page.empty_field}"} would resolve to {"field": ""} rather than being removed entirely
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
			'name' => $pagefile->description ?: $pagefile->basename,
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
			'name' => $pageimage->description ?: $pageimage->basename,
			'url' => $pageimage->httpUrl,
			'width' => $pageimage->width,
			'height' => $pageimage->height,
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

			$truncate = $options['truncate'] ?? false;
			if($truncate && !is_int($truncate)) {
				$truncate = 100;
			}

			// Check vars and if convert to a string if necessary
			$placeholderVars = [];
			if(preg_match_all('/\{' . preg_quote($prefix) . '\.([a-zA-Z0-9_.|]+)\}/', $jsonld, $matches)) {

				$removeQuotes = [];
				$isPage = $vars instanceof Page;
				$isInput = $vars instanceof WireInput;
				$isObject = is_object($vars);
				$isArray = is_array($vars);
				$allowedInputFields = [
					'url', 'httpUrl', 'httpHostUrl', 'scheme',
					'urlSegment1', 'urlSegment2', 'urlSegment3',
					'pageNum', 'pageNumStr', 'queryString', 'urlSegmentStr'
				];
				foreach(array_unique($matches[1]) as $field) {

					$value = '';
					if($isPage) {

						if(preg_match('/\.(first|last|[0-9]+)$/', $field, $match)) {
							// Handle {page.field.0}, {page.field.first}, {page.field.last}
							$fieldName = substr($field, 0, strrpos($field, '.'));
							$index = $match[1];
							$fieldValue = $vars->getUnformatted($fieldName);
							if($fieldValue instanceof WireArray) {
								if($index === 'first') {
									$value = $fieldValue->first() ?: '';
								} else if($index === 'last') {
									$value = $fieldValue->last() ?: '';
								} else {
									$value = $fieldValue->eq($index) ?: '';
								}
							} else {
								$value = '';
							}
						} else {
							$value = $vars->getUnformatted($field);
						}

					} else if($isInput) {
						if(in_array($field, $allowedInputFields)) {
							$value = $field === 'httpHostUrl' ? $vars->httpHostUrl() : $vars->$field;
						} else {
							$value = '';
						}
					} else if($isObject && isset($vars->$field)) {
						$value = $vars->$field;
					} else if($isArray && isset($vars[$field])) {
						$value = $vars[$field];
					}

					if($value !== '' && !is_string($value)) {

						$key = "{$prefix}.{$field}";
						if(is_object($value)) {

							if($value instanceof Pageimages) {

								$removeQuotes[] = $key;
								$value = $value->count ?
									json_encode(array_values($value->explode(function($pageimage) {
										return $this->populatePageimage($pageimage);
									}))) :
									'[]';

							} else if($value instanceof Pageimage) {

								$removeQuotes[] = $key;
								$value = json_encode($this->populatePageimage($value));

							} else if($value instanceof Pagefiles) {

								$removeQuotes[] = $key;
								$value = $value->count ?
									json_encode(array_values($value->explode(function($pagefile) {
										return $this->populatePagefile($pagefile);
									}))) :
									'[]';

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

						} else if(is_int($value) && $isPage && ($f = $vars->getField($field)) && $f->type instanceof FieldtypeDatetime) {

							// If the field is a date field, convert to ISO 8601 format for JSON-LD
							$value = date('c', $value);

						} else if(is_bool($value)) {

							$removeQuotes[] = $key;
							$value = $value ? 'true' : 'false';

						} else if(is_null($value)) {

							$value = '';

						} else {

							$removeQuotes[] = $key; // int, float should not be quoted in the JSON-LD output
						}
					}

					if($truncate && is_string($value) && strlen($value) > $truncate) {
						$value = $this->wire()->sanitizer->truncate($value, $truncate);
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
	 * Append JSON-LD script to the head of the page if a model is defined
	 *
	 * @param HookEvent $event
	 *
	 */
	protected function appendJsonldToHead(HookEvent $event) {

		$page = $event->object;
		$html = $event->return;

		if(!is_string($html)) {
			return;
		}

		$headEnd = strpos($html, '</head>');
		if(
			$headEnd === false ||
			strpos($html, '</html>') === false ||
			strpos($html, '</body>') === false ||
			strpos(substr($html, 0, $headEnd), 'application/ld+json') !== false ||
			!$this->isEligible($page)
		) {
			return;
		}

		$jsonld = trim($page->jsonldOutput);
		if(empty($jsonld)) {
			return;
		}

		// An array of JSON-LD objects should be wrapped in a @graph
		if(isset($jsonld[0]) && $jsonld[0] === '[' && substr($jsonld, -1) === ']') {
			$jsonld = "{\"@context\":\"https://schema.org\",\"@graph\":$jsonld}";
		}

		$data = json_decode($jsonld, true);
		if($data === null && json_last_error() !== JSON_ERROR_NONE) {
			if($this->wire()->config->debug && $this->wire()->user->isSuperUser()) {
				$this->log(sprintf($this->_('Invalid JSON-LD model for page %1$d: %2$s - %3$s'), $page->id, json_last_error_msg(), $jsonld));
			}
			return;
		}
		if(empty($data)) return; // empty but valid - silently skip

		$event->return = str_replace(
			'</head>',
			'<script type="application/ld+json">' .
				($this->wire()->user->isSuperUser() ?
					json_encode($data, JSON_PRETTY_PRINT) :
					$jsonld
				) .
			'</script>' .
			'</head>',
			$html
		);
	}

	/**
	 * Build the Template edit form to add JSON-LD model textarea
	 *
	 * @param HookEvent $event
	 *
	 */
	protected function buildEditTemplateForm(HookEvent $event) {

		$template = $event->arguments(0);

		if(!$this->eligibleTemplates()->has($template)) {
			return;
		}

		$form = $event->return;

		$tabs = $form->find('id=tab_files');
		if($tabs->count) {

			$this->loadScripts();

			$placeholders = [];
			foreach($this->getExampleFields($template->fieldgroup) as $field) {
				$placeholders[] = sprintf(
					'`{page.%1$s}`: %2$s',
					$field->name,
					$field->label ?: $field->name
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
				'notes' => sprintf(
					$this->_('You can use the following placeholders to populate existing page values: %s'),
					"\n" . implode("\n", $placeholders)
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

		$input = $this->wire()->input;
		$template = $event->arguments(0);

		// Only act when the form actually submitted this field
		if($input->post('jsonld_model') === null) return;

		// Only act on the template that ProcessTemplate is editing
		$editId = (int) $input->post('id');
		if($editId && $editId !== $template->id) return;

		if(!$this->eligibleTemplates()->has($template)) return;

		$newModel = $input->post->textarea('jsonld_model');
		$data = json_decode($newModel, true) ?? [];
		if(!empty($newModel) && empty($data)) {
			$this->error(sprintf($this->_('Invalid JSON in JSON-LD model: %s'), json_last_error_msg()));
		}

		// If the model has changed, clear the cache
		$currentModel = $template->jsonld_model;
		if($currentModel !== $newModel) {
			$this->clearCache("{$template->name}.*");
		}

		$template->set('jsonld_model', is_array($data) && count($data) ? json_encode($data) : '');
	}

	/**
	 * Build the page edit form for pages with the jsonld_model field
	 *
	 * @param HookEvent $event
	 *
	 */
	protected function buildEditPageForm(HookEvent $event) {

		$form = $event->return;

		$page = $event->object->getPage();
		if($page->id && $page->hasField('jsonld_model')) {

			$jsonldModelField = $form->get('jsonld_model');
			if($jsonldModelField) {

				$this->loadScripts();

				$existingNotes = $jsonldModelField->notes;
				if($existingNotes) {
					$existingNotes .= "\n\n";
				}

				$placeholders = [];
				foreach($this->getExampleFields($page->fields, $page) as $field) {

					$key = $field->name;
					$placeholders[] = sprintf(
						'`{page.%1$s}`: %2$s',
						$key,
						$this->populatePlaceholders('{page.' . $key . '}', $page, [
							'prefix' => 'page',
							'truncate' => true,
						])
					);
				}

				$existingNotes .= sprintf(
					$this->_('You can use the following placeholders to populate existing page values: %s'),
					"\n" . implode("\n", $placeholders)
				);
				$jsonldModelField->notes = $existingNotes;
			}
		}

		$event->return = $form;
	}

	/**
	 * Get eligible templates for JSON-LD
	 *
	 * @return TemplatesArray
	 *
	 */
	protected function eligibleTemplates() {
		if($this->eligibleTemplates === null) {
			$this->eligibleTemplates = $this->getTemplates();
		}
		return $this->eligibleTemplates;
	}

	/**
	 * Check if a page is eligible for JSON-LD models based on its template and type
	 *
	 * @param Page $page
	 * @return bool
	 *
	 */
	protected function isEligible(Page $page) {
		return $this->eligibleTemplates()->has($page->template) && !($page instanceof RepeaterPage);
	}

	/**
	 * Install MarkupJsonldModels
	 *
	 */
	public function ___install() {
		// Add jsonld_model field if it doesn't exist
		$fields = $this->wire()->fields;
		if(!$fields->get('jsonld_model')) {
			$fields->new('textarea', 'jsonld_model', [
				'label' => $this->_('JSON-LD Model'),
				'icon' => 'code',
				'contentType' => 0,
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
		// Clear cache
		$this->clearCache();
		// Remove jsonld_model field if it exists
		$fields = $this->wire()->fields;
		$field = $fields->get('jsonld_model');
		if(!$field) return;
		foreach($this->wire()->templates as $template) {
			if($template->fieldgroup->hasField($field)) {
				$template->fieldgroup->remove($field);
				$template->fieldgroup->save();
			}
		}
		$fields->delete($field);
	}
}
