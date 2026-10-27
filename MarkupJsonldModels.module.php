<?php namespace ProcessWire;

/**
 * Markup JSON-LD Models
 *
 * #pw-summary Allows defining JSON-LD models on a per-page and per-template basis with placeholder support.
 *
 * #pw-var $markupJsonldModels
 *
 * #pw-instantiate $markupJsonldModels = $modules->get('MarkupJsonldModels');
 *
 * #pw-body =
 * JSON-LD models are stored as compact JSON strings and resolved per page,
 * with placeholders (e.g. `{page.title}`) populated dynamically at render time.
 * Models are resolved in the order: page > template > default.
 *
 * ~~~~~
 * $markupJsonldModels = $modules->get('MarkupJsonldModels');
 * ~~~~~
 *
 * See the API.md and README.md files in the module directory for full documentation.
 * #pw-body
 *
 * @copyright 2026 NB Communication Ltd
 * @license Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 *
 * @property string $jsonld_model Default JSON-LD model to use if not set on page or template
 * @property string $placeholders_ignore List of placeholder names to ignore (not populate) when generating JSON-LD models
 * @property string $engineer_instructions Notes for Agent Tools to provide context about the site
 *
 */

class MarkupJsonldModels extends WireData implements Module, ConfigurableModule {

	/** @var TemplatesArray */
	protected $eligibleTemplates;

	/** @var string Regex matching placeholders that vary per request, populated after a cached model is retrieved */
	const requestPlaceholders = '(?:setting|input)\.[a-zA-Z0-9_.|]+';

	/**
	 * When ProcessWire is ready
	 *
	 */
	public function ready() {

		$page = $this->wire()->page;

		// Clear the cache when the page or a single field is saved
		// The cache expiry selector only covers full page saves, and on older cores it misses caches created earlier in the same request
		$this->addHookAfter('Pages::savedPageOrField', function(HookEvent $event) {
			$page = $event->arguments(0);
			if($this->isEligible($page)) {
				$this->clearCache($this->getCacheName($page, true));
			}
		});

		if($page->template->name === 'admin') {

			// If the admin, add hooks to page and template edit forms

			switch((string) $page->process) {
				case 'ProcessPageEdit':
					$this->addHookAfter('ProcessPageEdit::buildForm', $this, 'buildEditPageForm');
					$this->addHookBefore('Pages::save', $this, 'buildEditPageFormSave');
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

				if($this->wire()->config->debug) {
					$event->return = $this->populateModel($page->jsonldModel, $page);
					return;
				}

				// {setting.*} and {input.*} placeholders vary per request, so they are left in the cached model and populated afterwards
				$event->return = $this->populateRequestPlaceholders(
					$this->wire()->cache->getFor(
						$this,
						$this->getCacheName($page),
						"id={$page->id}", // expiry by selector so it is automatically cleared when the page is saved
						function() use ($page) {
							return $this->populateModel($page->jsonldModel, $page, true);
						}
					)
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
	 * Get the cache name for a page's JSON-LD model output
	 *
	 * On multi-language installs the name is suffixed with the current user's language name.
	 *
	 * @param Page $page
	 * @param bool $allLanguages Use a wildcard in place of the language (multi-language only), e.g. for clearing/checking all languages
	 * @return string
	 */
	public function getCacheName(Page $page, $allLanguages = false) {
		$name = "{$page->template->name}.{$page->id}";
		if($this->wire()->languages) {
			$name .= '.' . ($allLanguages ? '*' : $this->wire()->user->language->name);
		}
		return $name;
	}

	/**
	 * Prepare a value for JSON-LD output
	 *
	 * @param array|string $value
	 * @return string
	 *
	 */
	public function encodeValue(array|string $value) {

		$_encode = function($value) {
			return json_encode($value, JSON_UNESCAPED_UNICODE);
		};

		if(is_string($value)) {
			$value = $this->unentities(trim($value));
			$value = $_encode($value);
			$value = substr($value, 1, -1);
		} else {
			$value = $_encode($value);
		}

		return $value;
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
			'name' => $this->unentities($page->title ?: $page->name),
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
	public function ___getExampleFields(Fieldgroup $fieldgroup, ?Page $page = null) {
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
					if($valueRaw === null || $valueRaw === '' || ($valueRaw instanceof WireArray && !$valueRaw->count())) continue;
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
	 * @param bool $deferRequest If true, {setting.*} and {input.*} placeholders are left in place to be populated later with populateRequestPlaceholders()
	 * @return string
	 *
	 */
	public function ___populateModel($jsonld, Page $page, $deferRequest = false) {

		if(empty($jsonld) || strpos($jsonld, '{') === false) {
			return $jsonld;
		}

		if(method_exists($page, 'modifyJsonldModel')) {
			$jsonld = $page->modifyJsonldModel($jsonld, $this);
		}

		$jsonld = $this->ignorePlaceholders($jsonld);

		// Populate Page breadcrumb items
		if(strpos($jsonld, '{breadcrumbList}') !== false) {
			$jsonld = str_replace(
				'"{breadcrumbList}"',
				$this->encodeValue($this->getBreadcrumbList($page)),
				$jsonld
			);
		}

		// Populate the homepage fields with {home.fieldname} placeholders
		if(strpos($jsonld, '{home.') !== false) {
			$jsonld = $this->populatePlaceholders($jsonld, $this->wire()->pages->get(1), [
				'prefix' => 'home',
			]);
		}

		// Populate $page vars
		if(strpos($jsonld, '{page.') !== false) {
			$jsonld = $this->populatePlaceholders($jsonld, $page, [
				'prefix' => 'page',
			]);
		}

		// Populate setting() and $input vars, or protect them from removal below if deferred
		if($deferRequest) {
			$jsonld = preg_replace('/\{(' . self::requestPlaceholders . ')\}/', '[[$1]]', $jsonld);
		} else {
			$jsonld = $this->populateRequestPlaceholders($jsonld);
		}

		// Remove any placeholder tags that do not resolve
		$jsonldModel = $this->populatePlaceholders($jsonld, [], [
			'removeNullTags' => true, // Removes any placeholder tags (e.g. {page.null_field} -> "") that resolve to null
			'removeEmptyTags' => true, // Removes any placeholder tags (e.g. {page.empty_field} => "") that resolve to an empty string
			// These options do not remove the key/value pair if the placeholder is resolved to an empty string
			// For example {"field": "{page.empty_field}"} would resolve to {"field": ""} rather than being removed entirely
		]);

		// Restore any deferred and ignored placeholders back to their original form
		if($deferRequest) {
			$jsonldModel = preg_replace('/\[\[(' . self::requestPlaceholders . ')\]\]/', '{$1}', $jsonldModel);
		}
		$jsonldModel = $this->ignorePlaceholders($jsonldModel, true);

		return $this->unentities($jsonldModel);
	}

	/**
	 * Populate {setting.*} and {input.*} placeholders
	 *
	 * Called after a cached model has been retrieved, as these values vary per request.
	 *
	 * @param string $jsonld
	 * @return string
	 *
	 */
	public function populateRequestPlaceholders($jsonld) {

		$hasSetting = strpos((string) $jsonld, '{setting.') !== false;
		$hasInput = strpos((string) $jsonld, '{input.') !== false;
		if(!$hasSetting && !$hasInput) {
			return $jsonld;
		}

		$jsonld = $this->ignorePlaceholders($jsonld);

		if($hasSetting) {
			$jsonld = $this->populatePlaceholders($jsonld, setting() ?: [], [
				'prefix' => 'setting',
			]);
		}

		if($hasInput) {
			$jsonld = $this->populatePlaceholders($jsonld, $this->wire()->input, [
				'prefix' => 'input',
			]);
		}

		// Remove tags that resolve to empty, as this may run after the cleanup in populateModel()
		$jsonld = $this->populatePlaceholders($jsonld, [], [
			'removeNullTags' => true,
			'removeEmptyTags' => true,
		]);

		return $this->ignorePlaceholders($jsonld, true);
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
			'name' => $this->unentities($pagefile->description ?: $pagefile->basename),
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
			'name' => $this->unentities($pageimage->description ?: $pageimage->basename),
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

							if(($f = $vars->getField($field)) && $f->type instanceof FieldtypeDatetime) {
								$value = $vars->getUnformatted($field);
							} else {
								$value = $vars->get($field);
							}
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

					if($value !== '') {

						if(is_string($value)) {

							$value = $this->encodeValue($value);

						} else {

							$key = "{$prefix}.{$field}";
							if(is_object($value)) {

								if($value instanceof Pageimages) {

									$removeQuotes[] = $key;
									$value = $value->count ?
										$this->encodeValue(array_values($value->explode(function($pageimage) {
											return $this->populatePageimage($pageimage);
										}))) :
										'[]';

								} else if($value instanceof Pageimage) {

									$removeQuotes[] = $key;
									$value = $this->encodeValue($this->populatePageimage($value));

								} else if($value instanceof Pagefiles) {

									$removeQuotes[] = $key;
									$value = $value->count ?
										$this->encodeValue(array_values($value->explode(function($pagefile) {
											return $this->populatePagefile($pagefile);
										}))) :
										'[]';

								} else if($value instanceof Pagefile) {

									$removeQuotes[] = $key;
									$value = $this->encodeValue($this->populatePagefile($value));

								} else if(method_exists($value, 'jsonSerialize')) {

									$removeQuotes[] = $key;
									$value = $this->encodeValue($value->jsonSerialize());

								} else if(method_exists($value, '__toString')) {

									$value = $this->encodeValue((string) $value);

								} else {

									$removeQuotes[] = $key;
									$value = $this->encodeValue(get_object_vars($value));
								}

							} else if(is_array($value)) {

								$removeQuotes[] = $key;
								$value = $this->encodeValue($value);

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

		// Remove any full stops in var keys to avoid issues with WireTextTools::populatePlaceholders() not being able to resolve them
		foreach($vars as $key => $value) {
			if(strpos($key, '.') !== false) {
				$newKey = str_replace('.', '_', $key);
				$vars[$newKey] = $value;
				unset($vars[$key]);
				$jsonld = str_replace(
					'{' . $key . '}',
					'{' . $newKey . '}',
					$jsonld
				);
			}
		}

		return $this->wire()->sanitizer->getTextTools()->populatePlaceholders($jsonld, $vars, array_merge([
			'removeNullTags' => false,
			'removeEmptyTags' => false,
			'entityDecode' => true,
		], $options));
	}

	/**
	 * Protect (or restore) placeholders listed in the placeholders_ignore setting so they are not populated
	 *
	 * @param string $jsonld
	 * @param bool $restore If true, restore protected placeholders back to their original form
	 * @return string
	 *
	 */
	protected function ignorePlaceholders($jsonld, $restore = false) {
		foreach(array_filter(array_map('trim', explode("\n", (string) $this->placeholders_ignore))) as $placeholder) {
			$placeholder = [
				'{' . $placeholder . '}',
				'[[' . $placeholder . ']]', // temporarily replace with square braces to avoid being populated
			];
			if($restore) {
				$placeholder = array_reverse($placeholder);
			}
			$jsonld = str_replace($placeholder[0], $placeholder[1], $jsonld);
		}
		return $jsonld;
	}

	/**
	 * Decode HTML entities in a string
	 *
	 * @param string $str
	 * @return string
	 *
	 */
	public function unentities(string $str) {
		return $this->wire()->sanitizer->unentities($str);
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
		if(isset($jsonld[0]) && $jsonld[0] === '[' && substr($jsonld, -1) === ']' && trim($jsonld) !== '[]') {
			$jsonld = "{\"@context\":\"https://schema.org\",\"@graph\":$jsonld}";
		}

		// Decode to objects to preserve empty {} on re-encoding, unless modifyJsonldData() expects an array
		$modifyData = method_exists($page, 'modifyJsonldData');

		$data = json_decode($jsonld, $modifyData);
		if($data === null && json_last_error() !== JSON_ERROR_NONE) {
			// Retry with control characters escaped, in case any are present within string values
			// (not done first as this would break whitespace between tokens in a pretty-printed model)
			$jsonld = preg_replace_callback('/[\x00-\x1F\x7F]/', function($matches) {
				$char = $matches[0];
				switch($char) {
					case "\x08": return '\\b';
					case "\f": return '\\f';
					case "\n": return '\\n';
					case "\r": return '\\r';
					case "\t": return '\\t';
					default: return sprintf('\\u%04x', ord($char));
				}
			}, $jsonld);
			$data = json_decode($jsonld, $modifyData);
		}
		if($data === null && json_last_error() !== JSON_ERROR_NONE) {
			$files = $this->wire()->files;
			$logDir = $this->wire()->config->paths->assets . ".{$this->className}";
			if(!$files->exists($logDir)) {
				$files->mkdir($logDir);
			}
			$now = date('YmdHis');
			$logFile = "$logDir/invalid-jsonld-{$page->template->name}-{$page->id}-$now.txt";
			$files->filePutContents($logFile, $jsonld);
			$this->log(sprintf($this->_('Invalid JSON-LD model for page %1$d: %2$s. Result logged to %3$s'), $page->id, json_last_error_msg(), $logFile));
			return;
		}
		if(empty((array) $data)) return; // empty but valid - silently skip

		if($modifyData) {
			$data = $page->modifyJsonldData($data, $this);
		}

		// Always re-encode the decoded data, with < and > escaped, so values can't break out of the script tag
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG;
		if($this->wire()->user->isSuperUser()) {
			$flags |= JSON_PRETTY_PRINT;
		}

		$event->return = str_replace(
			'</head>',
			'<script type="application/ld+json">' . json_encode($data, $flags) . '</script></head>',
			$html
		);
	}

	/**
	 * Validate the JSON-LD model when the Page edit form is saved
	 *
	 * @param HookEvent $event
	 *
	 */
	protected function buildEditPageFormSave(HookEvent $event) {

		$input = $this->wire()->input;
		$page = $event->arguments(0);

		// Only act when the form actually submitted this field
		if($input->post('jsonld_model') === null) return;

		// Only act on the page that ProcessPageEdit is editing
		if((int) $input->post('id') !== $page->id) return;

		if(!$this->isEligible($page)) return;

		$this->validateAndSaveModel($page, $this->getCacheName($page, true));
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

		$this->validateAndSaveModel($template, "{$template->name}.*");
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
	 * Validate and save the JSON-LD model for a page or template
	 *
	 * @param Page|Template $pageOrTemplate
	 * @param string $cacheName Optional cache name to clear if the model has changed
	 *
	 */
	protected function validateAndSaveModel(Page|Template $pageOrTemplate, $cacheName = '') {

		$newModel = $this->wire()->input->post->textarea('jsonld_model');
		if($newModel === '') {
			$data = [];
		} else {
			$data = json_decode($newModel, true);
			if(json_last_error() !== JSON_ERROR_NONE) {
				$this->error(sprintf($this->_('Invalid JSON in JSON-LD model: %s'), json_last_error_msg()));
				$data = null; // signal "keep current"
			}
		}

		// If the model has changed, clear the cache
		$currentModel = $pageOrTemplate->get('jsonld_model');
		if(!empty($cacheName) && $currentModel !== $newModel) {
			$this->clearCache($cacheName);
		}

		$pageOrTemplate->set('jsonld_model', is_array($data) && count($data) ? $this->encodeValue($data) : (empty($newModel) ? '' : $currentModel));
	}

	/**
	 * Install MarkupJsonldModels
	 *
	 */
	public function ___install() {
		// Add jsonld_model field if it doesn't exist
		if(!$this->wire()->fields->get('jsonld_model')) {
			$field = $this->wire(new Field());
			$field->type = $this->wire()->modules->get('FieldtypeTextarea');
			$field->name = 'jsonld_model';
			$field->label = $this->_('JSON-LD Model');
			$field->icon = 'code';
			$field->contentType = 0; // text/plain
			$field->collapsed = 2;
			$field->rows = 20;
			$field->save();
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
