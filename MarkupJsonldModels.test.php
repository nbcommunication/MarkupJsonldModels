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

	/** @var array Original module/config state, restored in finish() */
	protected $restore = [];

	/** @var string Minimal HTML document for appendJsonldToHead() checks */
	protected $html = '<html><head><title>Test</title></head><body></body></html>';

	/**
	 * Setup: create a temporary template + page (idempotent)
	 */
	public function init() {

		$this->mod = $this->wire()->modules->get('MarkupJsonldModels');

		$this->restore = [
			'debug' => $this->wire()->config->debug,
			'jsonld_model' => $this->mod->jsonld_model,
			'placeholders_ignore' => $this->mod->placeholders_ignore,
		];

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

		$page = $pages->getFresh($page->id);
		$page->of(false);

		$this->testEncodeValue();
		$this->testPlaceholderTypes($page);
		$this->testIgnoredPlaceholders($page);
		$this->testInputPlaceholders($page);
		$this->testModelPrecedence($page);
		$this->testCacheName($page);
		$this->testCaching($page);
		$this->testAppendToHead($page);
		$this->testEditFormSave($page);
	}

	/**
	 * encodeValue()
	 */
	protected function testEncodeValue() {
		$mod = $this->mod;
		$this->check('encodeValue() string is JSON-escaped without surrounding quotes', 'Say \\"Hi\\"', $mod->encodeValue('Say "Hi"'));
		$this->check('encodeValue() string decodes entities and trims', 'Fish & Chips', $mod->encodeValue(' Fish &amp; Chips '));
		$this->check('encodeValue() string keeps unicode unescaped', 'Café', $mod->encodeValue('Café'));
		$this->check('encodeValue() array is compact JSON', '{"a":1,"b":[true,null]}', $mod->encodeValue(['a' => 1, 'b' => [true, null]]));
	}

	/**
	 * populatePlaceholders() / populateModel() value types and sources
	 *
	 * @param Page $page
	 */
	protected function testPlaceholderTypes(Page $page) {

		$mod = $this->mod;

		// Booleans and arrays are output unquoted
		$out = $mod->populatePlaceholders('{"b":"{setting.b}","f":"{setting.f}"}', ['b' => true, 'f' => false], ['prefix' => 'setting']);
		$this->check('populatePlaceholders() booleans are unquoted', '{"b":true,"f":false}', $out);

		$out = $mod->populatePlaceholders('{"list":"{setting.list}"}', ['list' => ['a', 'b']], ['prefix' => 'setting']);
		$this->check('populatePlaceholders() arrays are output as JSON arrays', '{"list":["a","b"]}', $out);

		$out = $mod->populatePlaceholders('{"n":"{setting.n}"}', ['n' => 1.5], ['prefix' => 'setting']);
		$this->check('populatePlaceholders() floats are unquoted', '{"n":1.5}', $out);

		// Keys containing full stops
		$out = $mod->populatePlaceholders('{"v":"{setting.a.b}"}', ['a.b' => 'dotted'], ['prefix' => 'setting']);
		$this->check('populatePlaceholders() resolves keys containing full stops', '{"v":"dotted"}', $out);

		// Only the given prefix is populated
		$out = $mod->populatePlaceholders('{"a":"{setting.x}","b":"{page.title}"}', ['x' => 'X'], ['prefix' => 'setting']);
		$this->check('populatePlaceholders() leaves other prefixes in place', '{"a":"X","b":"{page.title}"}', $out);

		// System date fields are ISO 8601
		$decoded = json_decode($mod->populateModel('{"d":"{page.created}"}', $page), true);
		$this->check('populateModel() {page.created} is ISO 8601', date('c', $page->created), $decoded['d'] ?? null);

		// Homepage placeholders
		$home = $this->wire()->pages->get(1);
		$decoded = json_decode($mod->populateModel('{"h":"{home.httpUrl}"}', $page), true);
		$this->check('populateModel() populates {home.*} from the homepage', $home->httpUrl, $decoded['h'] ?? null);

		// Unresolved placeholders are emptied, not left in the output
		$out = $mod->populateModel('{"@type":"Thing","x":"{page.no_such_field}"}', $page);
		$this->check('populateModel() empties unresolved placeholders', '{"@type":"Thing","x":""}', $out);

		// Empty WireArray index lookups resolve to empty
		$decoded = json_decode($mod->populateModel('{"c":"{page.children.first}"}', $page), true);
		$this->check('populateModel() {page.field.first} on an empty WireArray is empty', '', $decoded['c'] ?? null);
	}

	/**
	 * placeholders_ignore setting
	 *
	 * @param Page $page
	 */
	protected function testIgnoredPlaceholders(Page $page) {

		$mod = $this->mod;
		$mod->placeholders_ignore = "search_term_string\ninput.ignoreme";

		$out = $mod->populateModel('{"q":"{search_term_string}","t":"{page.title}"}', $page);
		$this->check('populateModel() leaves ignored placeholders in place', '{"q":"{search_term_string}","t":"JSON-LD Test Page"}', $out);

		$out = $mod->populateRequestPlaceholders('{"a":"{input.ignoreme}"}');
		$this->check('populateRequestPlaceholders() leaves ignored placeholders in place', '{"a":"{input.ignoreme}"}', $out);

		$mod->placeholders_ignore = $this->restore['placeholders_ignore'];
	}

	/**
	 * {input.*} placeholders, including deferred population
	 *
	 * @param Page $page
	 */
	protected function testInputPlaceholders(Page $page) {

		$mod = $this->mod;
		$input = $this->wire()->input;
		$input->setUrlSegments(['alpha']);

		$json = '{"seg":"{input.urlSegment1}","t":"{page.title}","x":"{page.no_such_field}"}';

		$out = $mod->populateModel($json, $page);
		$this->check('populateModel() populates {input.urlSegment1}', '{"seg":"alpha","t":"JSON-LD Test Page","x":""}', $out);

		$deferred = $mod->populateModel($json, $page, true);
		$this->check('populateModel() with $deferRequest leaves {input.*} in place', '{"seg":"{input.urlSegment1}","t":"JSON-LD Test Page","x":""}', $deferred);

		$out = $mod->populateRequestPlaceholders($deferred);
		$this->check('populateRequestPlaceholders() populates deferred placeholders', '{"seg":"alpha","t":"JSON-LD Test Page","x":""}', $out);

		$out = $mod->populateRequestPlaceholders('{"p":"{input.pageNum}"}');
		$this->check('populateRequestPlaceholders() {input.pageNum} is unquoted', '{"p":' . $input->pageNum . '}', $out);

		$out = $mod->populateRequestPlaceholders('{"x":"{input.post}"}');
		$this->check('populateRequestPlaceholders() disallowed $input properties are empty', '{"x":""}', $out);

		$input->setUrlSegments([]);
		$out = $mod->populateRequestPlaceholders('{"seg":"{input.urlSegment1}"}');
		$this->check('populateRequestPlaceholders() empty values are empty', '{"seg":""}', $out);

		$this->check('populateRequestPlaceholders() returns unchanged without request placeholders', '{"a":"b"}', $mod->populateRequestPlaceholders('{"a":"b"}'));

		// {setting.*} is deferred and populated alongside {input.*}
		setting('jsonldTest', 'Setting Value');
		$input->setUrlSegments(['alpha']);
		$json = '{"s":"{setting.jsonldTest}","seg":"{input.urlSegment1}","e":"{setting.no_such_setting}","t":"{page.title}"}';

		$out = $mod->populateModel($json, $page);
		$this->check('populateModel() populates {setting.*}', '{"s":"Setting Value","seg":"alpha","e":"","t":"JSON-LD Test Page"}', $out);

		$deferred = $mod->populateModel($json, $page, true);
		$this->check('populateModel() with $deferRequest leaves {setting.*} in place', '{"s":"{setting.jsonldTest}","seg":"{input.urlSegment1}","e":"{setting.no_such_setting}","t":"JSON-LD Test Page"}', $deferred);

		setting('jsonldTest', 'Changed Value');
		$out = $mod->populateRequestPlaceholders($deferred);
		$this->check('populateRequestPlaceholders() populates {setting.*} and {input.*} together', '{"s":"Changed Value","seg":"alpha","e":"","t":"JSON-LD Test Page"}', $out);

		setting('jsonldTest', null);
		$input->setUrlSegments([]);
	}

	/**
	 * Page::jsonldModel resolution order: page > template > default
	 *
	 * @param Page $page
	 */
	protected function testModelPrecedence(Page $page) {

		$mod = $this->mod;
		$template = $page->template;

		$page->set('jsonld_model', '{"@type":"FromPage"}');
		$template->jsonld_model = '{"@type":"FromTemplate"}';
		$mod->jsonld_model = '{"@type":"FromDefault"}';
		$this->check('Page::jsonldModel prefers the page model', '{"@type":"FromPage"}', $page->jsonldModel);

		$page->set('jsonld_model', '');
		$this->check('Page::jsonldModel falls back to the template model', '{"@type":"FromTemplate"}', $page->jsonldModel);

		$template->jsonld_model = '';
		$this->check('Page::jsonldModel falls back to the default model', '{"@type":"FromDefault"}', $page->jsonldModel);

		$mod->jsonld_model = $this->restore['jsonld_model'];
	}

	/**
	 * getCacheName()
	 *
	 * @param Page $page
	 */
	protected function testCacheName(Page $page) {

		$mod = $this->mod;
		$base = "{$this->tplName}.{$page->id}";

		if($this->wire()->languages) {
			$language = $this->wire()->user->language;
			$this->check('getCacheName() is suffixed with the current language', "$base.{$language->name}", $mod->getCacheName($page));
			$this->check('getCacheName() all languages uses a wildcard', "$base.*", $mod->getCacheName($page, true));
		} else {
			$this->check('getCacheName() is template.id', $base, $mod->getCacheName($page));
			$this->check('getCacheName() all languages is template.id without multi-language', $base, $mod->getCacheName($page, true));
		}
	}

	/**
	 * Page::jsonldOutput caching
	 *
	 * @param Page $page
	 */
	protected function testCaching(Page $page) {

		$mod = $this->mod;
		$cache = $this->wire()->cache;
		$input = $this->wire()->input;
		$this->wire()->config->debug = false;
		$mod->clearCache();

		$page->set('jsonld_model', '{"name":"{page.title}","seg":"{input.urlSegment1}","s":"{setting.jsonldTest}"}');
		$page->save('jsonld_model');

		setting('jsonldTest', 'First');
		$input->setUrlSegments(['alpha']);
		$output = json_decode((string) $page->jsonldOutput, true);
		$this->check('Page::jsonldOutput populated with caching', 'JSON-LD Test Page', $output['name'] ?? null);

		$cached = (string) $cache->getFor($mod, $mod->getCacheName($page));
		$this->check('Page::jsonldOutput stores the model in the cache', '"name":"JSON-LD Test Page"', $cached, '*=');
		$this->check('Cached model keeps {input.*} placeholders', '{input.urlSegment1}', $cached, '*=');
		$this->check('Cached model keeps {setting.*} placeholders', '{setting.jsonldTest}', $cached, '*=');

		// Unsaved change: the cached value should be served
		$page->title = 'Changed Title';
		$output = json_decode((string) $page->jsonldOutput, true);
		$this->check('Page::jsonldOutput is served from the cache', 'JSON-LD Test Page', $output['name'] ?? null);

		// {input.*} is populated per request, after retrieval from the cache
		$this->check('Cached Page::jsonldOutput populates {input.*} on retrieval', 'alpha', $output['seg'] ?? null);
		$input->setUrlSegments(['beta']);
		$output = json_decode((string) $page->jsonldOutput, true);
		$this->check('Cached Page::jsonldOutput reflects the current request input', 'beta', $output['seg'] ?? null);

		// {setting.*} is populated after retrieval from the cache, so changes don't need the cache clearing
		$this->check('Cached Page::jsonldOutput populates {setting.*} on retrieval', 'First', $output['s'] ?? null);
		setting('jsonldTest', 'Second');
		$output = json_decode((string) $page->jsonldOutput, true);
		$this->check('Cached Page::jsonldOutput reflects the current setting()', 'Second', $output['s'] ?? null);
		setting('jsonldTest', null);

		// Saving a single field expires the cache
		$page->save('title');
		$this->check('Saving a single field clears the page cache', true, empty($cache->getFor($mod, $mod->getCacheName($page))));
		$output = json_decode((string) $page->jsonldOutput, true);
		$this->check('Page::jsonldOutput repopulates after save', 'Changed Title', $output['name'] ?? null);

		// Saving the page expires the cache
		$this->check('Page::jsonldOutput is cached again', false, empty($cache->getFor($mod, $mod->getCacheName($page))));
		$page->save();
		$this->check('Saving the page clears its cache', true, empty($cache->getFor($mod, $mod->getCacheName($page))));

		// clearCache() with the all-languages name
		$mod->clearCache($mod->getCacheName($page, true));
		$this->check('clearCache(getCacheName($page, true)) clears the page cache', true, empty($cache->getFor($mod, $mod->getCacheName($page))));

		$page->title = 'JSON-LD Test Page';
		$page->set('jsonld_model', '');
		$page->save();
		$input->setUrlSegments([]);
		$this->wire()->config->debug = $this->restore['debug'];
	}

	/**
	 * appendJsonldToHead() output
	 *
	 * @param Page $page
	 */
	protected function testAppendToHead(Page $page) {

		$this->wire()->config->debug = true; // bypass the cache

		// Only use the page model, so the site's default model doesn't apply when it's empty
		$this->mod->jsonld_model = '';
		$page->template->jsonld_model = '';

		// Basic output
		$data = $this->renderJsonld($page, '{"@context":"https://schema.org","@type":"WebPage","name":"{page.title}"}');
		$this->check('appendJsonldToHead() outputs a script tag in the head', true, is_object($data));
		$this->check('appendJsonldToHead() output is populated', 'JSON-LD Test Page', $data->name ?? null);

		// Script tag breakout via entities that decode to </script>
		$html = $this->renderHead($page, '{"name":"a &lt;&#47;script&gt;&lt;script&gt;alert(1)&lt;&#47;script&gt; b"}');
		$this->check('appendJsonldToHead() escapes </script> in values', 1, substr_count($html, '</script>'));
		$data = $this->extractJsonld($html);
		$this->check('appendJsonldToHead() value survives escaping', 'a </script><script>alert(1)</script> b', $data->name ?? null);

		// Pretty-printed model (whitespace between tokens)
		$data = $this->renderJsonld($page, "{\n\t\"@type\": \"Thing\",\n\t\"name\": \"Pretty\"\n}");
		$this->check('appendJsonldToHead() accepts a pretty-printed model', 'Pretty', $data->name ?? null);

		// Control characters inside string values
		$data = $this->renderJsonld($page, "{\"name\":\"a\tb\"}");
		$this->check('appendJsonldToHead() escapes control characters in values', "a\tb", $data->name ?? null);

		// Empty objects are preserved
		$html = $this->renderHead($page, '{"@type":"Thing","x":{}}');
		$data = $this->extractJsonld($html);
		$this->check('appendJsonldToHead() preserves empty objects', true, isset($data->x) && $data->x instanceof \stdClass);

		// Arrays are wrapped in a @graph
		$data = $this->renderJsonld($page, '[{"@type":"A"},{"@type":"B"}]');
		$this->check('appendJsonldToHead() wraps an array in @graph', 2, isset($data->{'@graph'}) ? count($data->{'@graph'}) : 0);
		$this->check('appendJsonldToHead() @graph has @context', 'https://schema.org', $data->{'@context'} ?? null);

		// Existing JSON-LD in the head is left alone
		$existing = str_replace('</head>', '<script type="application/ld+json">{}</script></head>', $this->html);
		$this->check('appendJsonldToHead() skips a head that already has JSON-LD', $existing, $this->renderHead($page, '{"@type":"Thing"}', $existing));

		// Non-HTML documents are left alone
		$fragment = '<div>fragment</div>';
		$this->check('appendJsonldToHead() skips output without </head>', $fragment, $this->renderHead($page, '{"@type":"Thing"}', $fragment));

		// Empty model or empty object outputs nothing
		$this->check('appendJsonldToHead() skips an empty model', $this->html, $this->renderHead($page, ''));
		$this->check('appendJsonldToHead() skips an empty object', $this->html, $this->renderHead($page, '{}'));

		// Invalid JSON is logged and skipped
		$this->check('appendJsonldToHead() skips invalid JSON', $this->html, $this->renderHead($page, '{"a":}'));
		$logFiles = glob($this->wire()->config->paths->assets . ".{$this->mod->className}/invalid-jsonld-{$this->tplName}-{$page->id}-*.txt");
		$this->check('appendJsonldToHead() logs invalid JSON to a file', true, count($logFiles) > 0);
		foreach($logFiles as $logFile) {
			$this->wire()->files->unlink($logFile);
		}

		$page->set('jsonld_model', '');
		$this->mod->jsonld_model = $this->restore['jsonld_model'];
		$this->wire()->config->debug = $this->restore['debug'];
	}

	/**
	 * Page and template edit form save hooks
	 *
	 * @param Page $page
	 */
	protected function testEditFormSave(Page $page) {

		$post = $this->wire()->input->post;
		$template = $page->template;
		$current = '{"@type":"Current"}';

		$page->set('jsonld_model', $current);
		$post->set('jsonld_model', "{\n\t\"@type\": \"Thing\"\n}");

		// Another page saved during the request is not affected
		$post->set('id', $page->id + 1);
		$this->callHook('buildEditPageFormSave', $page);
		$this->check('Page save hook ignores pages other than the one being edited', $current, $page->jsonld_model);

		// The edited page has its model compacted
		$post->set('id', $page->id);
		$this->callHook('buildEditPageFormSave', $page);
		$this->check('Page save hook compacts the posted model', '{"@type":"Thing"}', $page->jsonld_model);

		// Invalid JSON keeps the current model
		$page->set('jsonld_model', $current);
		$post->set('jsonld_model', '{"@type":}');
		$this->callHook('buildEditPageFormSave', $page);
		$this->check('Page save hook keeps the current model if the posted JSON is invalid', $current, $page->jsonld_model);
		$this->wire()->notices->removeAll();

		// An empty post clears the model
		$post->set('jsonld_model', '');
		$this->callHook('buildEditPageFormSave', $page);
		$this->check('Page save hook clears the model if the posted value is empty', '', $page->jsonld_model);

		// Template save hook
		$template->jsonld_model = $current;
		$post->set('jsonld_model', "{\n\t\"@type\": \"Thing\"\n}");
		$post->set('id', $template->id + 1);
		$this->callHook('buildEditTemplateFormSave', $template);
		$this->check('Template save hook ignores templates other than the one being edited', $current, $template->jsonld_model);

		$post->set('id', $template->id);
		$this->callHook('buildEditTemplateFormSave', $template);
		$this->check('Template save hook compacts the posted model', '{"@type":"Thing"}', $template->jsonld_model);

		$post->remove('jsonld_model');
		$post->remove('id');
		$template->jsonld_model = '';
		$page->set('jsonld_model', '');
	}

	/**
	 * Call one of the module's protected hook methods with a HookEvent
	 *
	 * @param string $method
	 * @param Page|Template $object Hooked object (render hooks) or first argument (save hooks)
	 * @param string|null $return
	 * @return HookEvent
	 */
	protected function callHook($method, $object, $return = null) {
		$event = new HookEvent([
			'object' => $object,
			'arguments' => [$object],
			'return' => $return,
		]);
		(new \ReflectionMethod($this->mod, $method))->invoke($this->mod, $event);
		return $event;
	}

	/**
	 * Run appendJsonldToHead() for a page with the given model
	 *
	 * @param Page $page
	 * @param string $model
	 * @param string|null $html
	 * @return string
	 */
	protected function renderHead(Page $page, $model, $html = null) {
		$page->set('jsonld_model', $model);
		return $this->callHook('appendJsonldToHead', $page, $html ?? $this->html)->return;
	}

	/**
	 * Run appendJsonldToHead() and return the decoded JSON-LD
	 *
	 * @param Page $page
	 * @param string $model
	 * @return object|null
	 */
	protected function renderJsonld(Page $page, $model) {
		return $this->extractJsonld($this->renderHead($page, $model));
	}

	/**
	 * Extract and decode the JSON-LD script from HTML
	 *
	 * @param string $html
	 * @return object|null
	 */
	protected function extractJsonld($html) {
		if(!preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches)) {
			return null;
		}
		return json_decode($matches[1]);
	}

	/**
	 * Cleanup: remove the temporary page and template
	 */
	public function finish() {

		if(count($this->restore)) {
			$this->wire()->config->debug = $this->restore['debug'];
			$this->mod->jsonld_model = $this->restore['jsonld_model'];
			$this->mod->placeholders_ignore = $this->restore['placeholders_ignore'];
		}
		$this->wire()->input->setUrlSegments([]);
		$this->mod->clearCache("{$this->tplName}.*");

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
