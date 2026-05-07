<?php namespace ProcessWire;

/**
 * Markup JSON-LD Models
 *
 * #pw-summary todo
 *
 * @copyright 2026 NB Communication Ltd
 * @license Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 *
 * @param string $jsonld Default JSON-LD model to use if not set on page or template
 *
 */

class MarkupJsonldModels extends WireData implements Module, ConfigurableModule {

	/**
	 * Initialize the module
	 *
	 */
	public function init() {

		// If the admin
		if($this->wire()->page->rootParent->id === $this->wire()->config->adminRootPageID) {

			// Add hooks on template editor
			$process = 'ProcessTemplate';
			if($this->wire()->page->process === $process) {
				$this->addHookAfter("$process::buildEditForm", $this, 'buildEditForm');
				$this->addHookBefore('Templates::save', $this, 'buildEditFormSave');
			}
		}
	}

	/**
	 * When ProcessWire is ready
	 *
	 */
	public function ready() {

		// If not the admin
		if($this->wire()->page->rootParent->id !== $this->wire()->config->adminRootPageID) {

			// Hook on page render to add JSON-LD to the head
			$this->addHookAfter('Page::render', function(HookEvent $event) {

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

				$data = [];

				$jsonld = $page->jsonld ?: $page->template->jsonld ?: $this->jsonld;

				$event->return = str_replace(
					'</head>',
					'<script type="application/ld+json">' .
						json_encode($data, ($this->wire()->user()->isSuperUser() ? JSON_PRETTY_PRINT : 0)) .
					'</script>' .
					'</head>',
					$html
				);
			});
		}
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

		$event->return = $form;
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
	}

	/**
	 * Uninstall MarkupJsonldModels
	 *
	 */
	public function ___uninstall() {
		parent::___uninstall();

		// Remove jsonld field if it exists
	}

}
