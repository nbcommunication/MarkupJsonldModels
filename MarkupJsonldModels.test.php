<?php namespace ProcessWire;

/**
 * WireTest for MarkupJsonldModels
 *
 * Verifies the module's documented public API. Creates a throwaway template and page
 * for deterministic placeholder / property checks and removes them in finish().
 *
 * Run with: php index.php test MarkupJsonldModels
 * (requires the WireTests module to be installed)
 */

class WireTest_MarkupJsonldModels extends WireTest {

	/** @var string Temporary template name */
	protected $tplName = 'jsonldtest_tpl';

	/** @var string Temporary page name */
	protected $pageName = 'jsonldtest-page';

	/** @var MarkupJsonldModels */
	protected $mod;

	/**
	 * Setup: create a temporary template + page (idempotent)
	 */
	public function init() {

		$this->mod = $this->wire()->modules->get('MarkupJsonldModels');

		$fields = $this->wire()->fields;
		$templates = $this->wire()->templates;
		$fieldgroups = $this->wire()->fieldgroups;
		$pages = $this->wire()->pages;

		// The module's field must be present
		if(!$fields->get('jsonld_model')) {
			$this->fail('Field "jsonld_model" not found; is the module installed correctly?');
			return;
		}

		// Create a temporary template with title + jsonld_model
		$template = $templates->get($this->tplName);
		if(!$template) {
			$fg = $fieldgroups->get($this->tplName);
			if(!$fg) {
				$fg = new Fieldgroup();
				$fg->name = $this->tplName;
				$fg->add($fields->get('title'));
				$fg->add($fields->get('jsonld_model'));
				$fg->save();
			}
			$template = new Template();
			$template->name = $this->tplName;
			$template->fieldgroup = $fg;
			$template->save();
			$this->ok("Created temporary template: {$this->tplName}");
		}

		// Create a temporary page under home
		$page = $pages->get("template={$this->tplName}, name={$this->pageName}, include=all");
		if(!$page->id) {
			$page = $pages->newPage($template);
			$page->parent = $pages->get(1);
			$page->name = $this->pageName;
			$page->of(false);
			$page->title = 'JSON-LD Test Page';
			$page->save();
			$this->ok("Created temporary page: /{$this->pageName}/");
		}
	}

	/**
	 * Run the tests
	 */
	public function execute() {

		$mod = $this->mod;
		$pages = $this->wire()->pages;

		// --- getTemplates() ---
		$templates = $mod->getTemplates();
		$this->check('getTemplates() returns TemplatesArray', true, $templates instanceof TemplatesArray);
		$this->check('getTemplates() excludes admin (system) template', false, $templates->has('admin'));
		$this->check('getTemplates() includes our test template', true, $templates->has($this->tplName));

		// --- getBreadcrumbListItem() ---
		$home = $pages->get(1);
		$item = $mod->getBreadcrumbListItem($home, 1);
		$this->check('getBreadcrumbListItem() @type', 'ListItem', $item['@type']);
		$this->check('getBreadcrumbListItem() position', 1, $item['position']);
		$this->check('getBreadcrumbListItem() name', (string) $home->title, (string) $item['name']);
		$this->check('getBreadcrumbListItem() item is httpUrl', $home->httpUrl, $item['item']);

		// --- getBreadcrumbList() ---
		$page = $pages->get("template={$this->tplName}, name={$this->pageName}, include=all");
		$list = $mod->getBreadcrumbList($page);
		$this->check('getBreadcrumbList() returns array', true, is_array($list));
		$this->check('getBreadcrumbList() has 2 items (home + page)', 2, count($list));
		if(count($list)) {
			$this->check('getBreadcrumbList() first position is 1', 1, $list[0]['position']);
			$last = end($list);
			$this->check('getBreadcrumbList() last item is the page', $page->httpUrl, $last['item']);
		}

		// --- getExampleFields() ---
		$exampleFields = $mod->getExampleFields($page->template->fieldgroup);
		$this->check('getExampleFields() returns FieldsArray', true, $exampleFields instanceof FieldsArray);
		$this->check('getExampleFields() excludes jsonld_model itself', false, $exampleFields->has('jsonld_model'));
		$this->check('getExampleFields() includes title (text field)', true, $exampleFields->has('title'));

		// --- populatePlaceholders() with array + prefix ---
		$out = $mod->populatePlaceholders('{"name":"{setting.foo}"}', ['foo' => 'Bar'], ['prefix' => 'setting']);
		$this->check('populatePlaceholders() array prefix populates', '{"name":"Bar"}', $out);

		// No-match prefix returns string unchanged
		$out = $mod->populatePlaceholders('{"name":"static"}', ['foo' => 'Bar'], ['prefix' => 'setting']);
		$this->check('populatePlaceholders() no prefix match returns unchanged', '{"name":"static"}', $out);

		// --- populatePlaceholders() with Page + prefix ---
		$out = $mod->populatePlaceholders('{"name":"{page.title}"}', $page, ['prefix' => 'page']);
		$this->check('populatePlaceholders() page prefix populates title', '{"name":"JSON-LD Test Page"}', $out);

		// truncate option
		$long = str_repeat('a', 150);
		$page->of(false);
		$page->title = $long;
		$out = $mod->populatePlaceholders('{page.title}', $page, ['prefix' => 'page', 'truncate' => 20]);
		$this->check('populatePlaceholders() truncate shortens value', true, strlen($out) <= 25 && strlen($out) < 150);
		// restore title
		$page->title = 'JSON-LD Test Page';
		$page->save('title');

		// int value should be unquoted
		$out = $mod->populatePlaceholders('{"id":"{page.id}"}', $page, ['prefix' => 'page']);
		$this->check('populatePlaceholders() integer value is unquoted', '{"id":' . $page->id . '}', $out);

		// --- populateModel() ---
		$this->check('populateModel() empty string short-circuits', '', $mod->populateModel('', $page));
		$this->check('populateModel() no-brace short-circuits', 'plain', $mod->populateModel('plain', $page));

		$json = '{"@context":"https://schema.org","@type":"WebPage","name":"{page.title}"}';
		$out = $mod->populateModel($json, $page);
		$decoded = json_decode($out, true);
		$this->check('populateModel() produces valid JSON', true, is_array($decoded));
		$this->check('populateModel() populated page.title', 'JSON-LD Test Page', $decoded['name'] ?? null);

		// breadcrumbList expansion
		$json = '{"@type":"BreadcrumbList","itemListElement":"{breadcrumbList}"}';
		$out = $mod->populateModel($json, $page);
		$decoded = json_decode($out, true);
		$this->check('populateModel() expands {breadcrumbList} to array', true, isset($decoded['itemListElement']) && is_array($decoded['itemListElement']));

		// --- populateModel() decodes HTML entities (e.g. &amp; -> &) ---
		$json = '{"@type":"WebPage","name":"Fish &amp; Chips"}';
		$out = $mod->populateModel($json, $page);
		$decoded = json_decode($out, true);
		$this->check('populateModel() produces valid JSON when decoding entities', true, is_array($decoded));
		$this->check('populateModel() decodes HTML entities (&amp; -> &)', 'Fish & Chips', $decoded['name'] ?? null);

		// --- populateModel() escapes double quotes found inside string values ---
		$page->of(false);
		$page->title = 'Say "Hello" World';
		$page->save('title');
		$json = '{"name":"{page.title}"}';
		$out = $mod->populateModel($json, $page);
		$decoded = json_decode($out, true);
		$this->check('populateModel() produces valid JSON when value contains quotes', true, is_array($decoded));
		$this->check('populateModel() escapes double quotes inside string values', 'Say "Hello" World', $decoded['name'] ?? null);
		// restore title
		$page->title = 'JSON-LD Test Page';
		$page->save('title');
		// --- Page::jsonldModel precedence ---
		$page->of(false);
		$page->set('jsonld_model', '{"@type":"Article"}');
		$page->save('jsonld_model');
		$fresh = $pages->getFresh($page->id);
		$this->check('Page::jsonldModel returns page-level model', '{"@type":"Article"}', trim((string) $fresh->jsonldModel));

		// --- Page::jsonldOutput populates ---
		$page->of(false);
		$page->set('jsonld_model', '{"@type":"WebPage","name":"{page.title}"}');
		$page->save('jsonld_model');
		$fresh = $pages->getFresh($page->id);
		$output = json_decode(trim((string) $fresh->jsonldOutput), true);
		$this->check('Page::jsonldOutput populated title', 'JSON-LD Test Page', $output['name'] ?? null);

		// --- populatePageimage() / populatePagefile() structure ---
		// (structure-only sanity checks are covered via populateModel above;
		//  no image/file fixture is created to keep the test lightweight)

		// --- clearCache() ---
		$mod->clearCache("{$this->tplName}.*");
		$this->ok('clearCache(template.*) ran without error');
		$mod->clearCache();
		$this->ok('clearCache() wildcard ran without error');
	}

	/**
	 * Cleanup: remove the temporary page and template
	 */
	public function finish() {

		$pages = $this->wire()->pages;
		$templates = $this->wire()->templates;
		$fieldgroups = $this->wire()->fieldgroups;

		$page = $pages->get("name={$this->pageName}, include=all");
		if($page->id && $page->template->name === $this->tplName) {
			$pages->delete($page, true);
			$this->ok("Deleted temporary page: /{$this->pageName}/");
		}

		$template = $templates->get($this->tplName);
		if($template) {
			$templates->delete($template);
			$this->ok("Deleted temporary template: {$this->tplName}");
		}

		$fg = $fieldgroups->get($this->tplName);
		if($fg) {
			$fieldgroups->delete($fg);
		}
	}
}
