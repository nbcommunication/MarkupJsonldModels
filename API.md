# MarkupJsonldModels

The **Markup JSON-LD Models** module (`$modules->get('MarkupJsonldModels')`) allows defining JSON-LD structured data models on a per-page and per-template basis, with placeholder support that is resolved dynamically at render time.

```php
$markupJsonldModels = $modules->get('MarkupJsonldModels');
```

This document describes the programmatic API. For placeholder syntax, output behaviour, eligible templates, hooks in depth, and the admin UI, see [README.md](README.md).

## Contents

- [Overview](#overview)
- [Site context notes for AI agents](#site-context-notes-for-ai-agents)
- [Properties added to Page](#properties-added-to-page)
- [Reading models](#reading-models)
- [Writing models](#writing-models)
- [Clearing a model](#clearing-a-model)
- [Clearing the cache](#clearing-the-cache)
- [Adding the `jsonld_model` field to a template](#adding-the-jsonld_model-field-to-a-template)
- [Checking placeholder resolution](#checking-placeholder-resolution)
- [Hookable methods (quick reference)](#hookable-methods-quick-reference)
- [Non-hookable methods](#non-hookable-methods)
- [See also](#see-also)
- [Notes](#notes)

## Overview

JSON-LD models are stored as **compact JSON strings** in three possible locations:

1. **Per page** — on the `jsonld_model` field, if the page's template includes it.
2. **Per template** — on the template's `jsonld_model` property (set via the JSON-LD tab on the template edit screen, or via the API).
3. **Default** — in the module configuration, used when neither of the above is set.

When the module renders JSON-LD for a page it resolves the model in this order: **page > template > default**. An empty value at any level means "fall through to the next level".

The module is autoloaded. On every front-end render of an eligible page it resolves placeholders and injects the result as a `<script type="application/ld+json">` tag immediately before `</head>`. A page is eligible when its template is non-system with an `html` (or unset) content type and the page is not a [[RepeaterPage]]. Injection additionally requires that the rendered markup contains `</head>`, `</body>` and `</html>`, and has no existing `application/ld+json` script within the `<head>`.

> ⚠️ The admin field validates JSON via CodeMirror, but **the API does not**. When setting `jsonld_model` programmatically you are responsible for storing valid JSON. Invalid JSON will simply not be rendered.

## Site context notes for AI agents

If the Agent Tools module is installed, this module provides a configurable notes field which allows the site owner to provide additional context about the site for AI agents in markdown format. This is stored in the `engineer_instructions` setting on the module and can be accessed via:

```php
$agentToolsNotes = $modules->getConfig('MarkupJsonldModels', 'engineer_instructions');
```

An AI Agent may update this field with any additional information it learns about the site. This can be done programmatically via the API:

```php
$notes = $modules->getConfig('MarkupJsonldModels', 'engineer_instructions');
$notes .= "\n\nSome new notes about the site.";
$modules->saveConfig('MarkupJsonldModels', 'engineer_instructions', $notes);
```

## Properties added to Page

The module adds **two** runtime properties to [[Page]] — `$page->jsonldModel` and `$page->jsonldOutput` — both of which return `null` for pages whose template is not eligible. The table below also lists the underlying `jsonld_model` field for reference; it is the stored field value itself (not added by the module) and returns an empty string when not set.

| Property | Type | Description |
|----------|------|-------------|
| `$page->jsonldModel` | `string` \| `null` | The resolved JSON-LD string (page > template > default), **before** placeholders are replaced by their dynamic values. |
| `$page->jsonldOutput` | `string` \| `null` | The **final, placeholder-populated** JSON-LD string. Note: for superusers the module pretty-prints (`JSON_PRETTY_PRINT`) the JSON it actually injects into the head, so the injected markup can differ in whitespace from this (compact) value. |
| `$page->jsonld_model` | `string` | The raw stored JSON on the page (the underlying field value), with placeholders still in place. Empty if not set. |

## Reading models

### The resolved model for a page

`$page->jsonldModel` returns the resolved JSON-LD string for an eligible page, before placeholders are replaced by their dynamic values.

```php
$model = $page->jsonldModel;
```

### The populated model for a page

`$page->jsonldOutput` returns the **final, placeholder-populated** JSON-LD string for an eligible page, exactly as the module would output it in the head.

```php
$output = $page->jsonldOutput;
```

This is the most useful read property when debugging or when you want to embed the model somewhere other than the auto-injected location.

### The raw stored model for a page

```php
$raw = $page->jsonld_model; // string of JSON, or empty
```

This is the unresolved JSON exactly as stored on the page — with placeholders still in place.

### The raw stored model for a template

```php
$templateModel = $templates->get('basic-page')->jsonld_model;
```

### The default model from module config

```php
$defaultModel = $modules->getConfig('MarkupJsonldModels', 'jsonld_model');
```

## Writing models

When writing models via the API you are typically assigning a JSON string to `jsonld_model` on a [[Page]], a [[Template]], or to the `jsonld_model` key of the module config. Use `json_encode()` to convert PHP arrays to JSON.

### Validate before saving

Because the API does not validate JSON for you, a safe pattern is:

```php
$json = json_encode($model);
if($json === false || json_decode($json) === null) {
	throw new WireException('Invalid JSON-LD model');
}
```

### Updating a JSON-LD model for a page

```php
$pageToUpdate = $pages->get('/path/to/page/');
if($pageToUpdate->id) {
	if($pageToUpdate->hasField('jsonld_model')) {
		$model = [
			'@context' => 'https://schema.org',
			'@type' => 'WebPage',
			'name' => '{page.title}',
			'url' => '{page.httpUrl}',
		];
		$pageToUpdate->of(false); // output formatting must be off to set fields
		$pageToUpdate->jsonld_model = json_encode($model);
		$pages->save($pageToUpdate);
	} else {
		// Page doesn't have the jsonld_model field
		// See "Adding the jsonld_model field to a template" below
	}
} else {
	// Page could not be found
}
```

### Updating a JSON-LD model for a template

```php
$templateToUpdate = $templates->get('template-name');
if($templateToUpdate->id) {
	if($modules->get('MarkupJsonldModels')->getTemplates()->has($templateToUpdate)) {
		$model = [
			'@context' => 'https://schema.org',
			'@type' => 'WebPage',
			'name' => '{page.title}',
			'url' => '{page.httpUrl}',
		];
		$templateToUpdate->jsonld_model = json_encode($model);
		$templates->save($templateToUpdate);
	} else {
		// Template not eligible for JSON-LD models
		// Hook MarkupJsonldModels::getTemplates to add it — see README
	}
} else {
	// Template could not be found
}
```

### Updating the default JSON-LD model

```php
$defaultModel = [
	'@context' => 'https://schema.org',
	'@type' => 'WebPage',
	'name' => '{page.title}',
	'url' => '{page.httpUrl}',
];
$modules->saveConfig('MarkupJsonldModels', 'jsonld_model', json_encode($defaultModel));
```

## Clearing a model

To remove a model at any level, set it to an empty string. The module will then fall through to the next level in the precedence chain (page > template > default).

```php
// Clear a page-level model
$pageToUpdate->of(false);
$pageToUpdate->jsonld_model = '';
$pages->save($pageToUpdate);

// Clear a template-level model
$templateToUpdate->jsonld_model = '';
$templates->save($templateToUpdate);

// Clear the default model
$modules->saveConfig('MarkupJsonldModels', 'jsonld_model', '');
```

## Clearing the cache

After saving a template or default model via the API, you should also clear the cache. This is because the module caches the resolved model for each page for performance, and these caches are not automatically cleared when you update models via the API.

```php
$markupJsonldModels = $modules->get('MarkupJsonldModels');
$markupJsonldModels->clearCache();
```

Saving a page via the API will automatically clear the cache for that page, so you don't need to do anything extra after updating page-level models.

Note: when `$config->debug` is true the module bypasses the cache entirely and resolves `$page->jsonldOutput` on every request. If output looks correct with debug on but stale with debug off, clear the cache.

## Adding the `jsonld_model` field to a template

For per-page models to work, the `jsonld_model` field must be added to the relevant template's fieldgroup. The field itself is installed automatically when the module is installed, but it is not added to any template by default.

```php
$template = $templates->get('basic-page');
$jsonldField = $fields->get('jsonld_model');

if($template && $jsonldField && !$template->fieldgroup->hasField($jsonldField)) {
	$template->fieldgroup->add($jsonldField);
	$template->fieldgroup->save();
}
```

To remove the field from a template:

```php
$template = $templates->get('basic-page');
$jsonldField = $fields->get('jsonld_model');

if($template && $jsonldField && $template->fieldgroup->hasField($jsonldField)) {
	$template->fieldgroup->remove($jsonldField);
	$template->fieldgroup->save();
}
```

## Checking placeholder resolution

The simplest debugging approach is to compare the raw stored model against the resolved output for a page:

```php
$page = $pages->get('/path/to/page/');

echo "=== Raw model (with placeholders) ===\n";
echo $page->jsonldModel . "\n\n";

echo "=== Resolved output (after placeholder replacement) ===\n";
echo $page->jsonldOutput . "\n";
```

Any placeholders that resolved to an empty value will show as empty strings (e.g. `"name": ""`) in the resolved output. For `{page.*}` and `{home.*}` placeholders an unknown field resolves to `null` (coerced to `""`) rather than being left in place, so a genuinely unmatched `{prefix.token}` is uncommon for those prefixes. Note also that the prefix is stripped from every matched occurrence, so a token that fails to match (e.g. `{page.foo-bar}`) can survive as `{foo-bar}`. This is usually enough to diagnose model issues.

### Inspecting individual placeholders

For a deeper view of how each placeholder resolves — including array/object placeholders such as [[Pageimage]] and [[Pagefile]] — call the non-hookable `populatePlaceholders()` method directly per prefix. Unlike `populateModel()`, this method does not apply the final `removeEmptyTags` / `removeNullTags` passes (both of which `populateModel()` sets to `true`), so you can more easily distinguish "resolved to empty" from "did not match". Note that quote removal for array/object and non-string values (e.g. Pageimage, Pagefiles, booleans, integers) happens *inside* `populatePlaceholders()` itself and is not something you can suppress here.

Each prefix resolves against a **different** source of variables, exactly as `populateModel()` does internally:

- `{setting.*}` → `setting() ?: []`
- `{home.*}` → `$pages->get(1)` (the homepage)
- `{page.*}` → the current `$page`
- `{input.*}` → `$input`

Passing the wrong source (e.g. the page for every prefix) will produce misleading results, so map each prefix to its correct vars:

```php
$page = $pages->get('/path/to/page/');
$markupJsonldModels = $modules->get('MarkupJsonldModels');
$model = $page->jsonldModel;

// Each supported prefix resolves against its own source of variables.
$varsByPrefix = [
	'setting' => setting() ?: [],
	'home'    => $pages->get(1),
	'page'    => $page,
	'input'   => $input,
];

foreach($varsByPrefix as $prefix => $vars) {
	if(!preg_match_all('/\{' . preg_quote($prefix) . '\.([a-zA-Z0-9_.|]+)\}/', $model, $m)) continue;

	echo "=== {{$prefix}.*} placeholders ===\n";
	foreach(array_unique($m[1]) as $token) {
		$placeholder = '{' . $prefix . '.' . $token . '}';
		// Pass a single placeholder through populatePlaceholders so we see the raw resolved value.
		$resolved = $markupJsonldModels->populatePlaceholders($placeholder, $vars, [
			'prefix' => $prefix,
		]);

		if($resolved === $placeholder) {
			$display = '(no match — placeholder unchanged)';
		} else if($resolved === '') {
			$display = '(resolved to empty)';
		} else {
			$display = $resolved;
		}
		echo "  $placeholder => $display\n";
	}
	echo "\n";
}
```

Be aware that for the `page` and `home` prefixes (where the vars source is a [[Page]]) an unknown field returns `null`, which is coerced to an empty string — so those tokens usually show as `(resolved to empty)` rather than `(no match — placeholder unchanged)`. Also note the prefix is stripped from every matched occurrence, so a token the regex cannot match (e.g. `{page.foo-bar}`) survives as `{foo-bar}` rather than in its original `{page.foo-bar}` form.

For array-valued placeholders (e.g. `{page.images.first}` resolving via `populatePageimage()` to an `ImageObject` array), `populatePlaceholders()` will substitute a JSON-encoded representation in place of the placeholder. If you want the array form rather than the JSON string, hook `populatePageimage` / `populatePagefile` directly, or read the source field on the page (e.g. `$page->images->first()`) and pass it through `$markupJsonldModels->populatePageimage(...)` yourself.

### Page {page.*} placeholders

Properties on a [[Page]] object can be set dynamically, via hooks or other mechanisms, so checking whether a field exists on the template is not sufficient to determine if a placeholder may resolve to a value.

### Setting {setting.*} placeholders

The `{setting.*}` placeholders can be checked via `setting('foo')` for the relevant setting key, or accessing all settings via `setting()` with no arguments and checking the resulting array.

## Hookable methods (quick reference)

All of the following are hookable. Add hooks in `/site/ready.php`. See the [README](README.md#hooks) for full descriptions and examples.

| Method | Returns | Purpose |
|--------|---------|---------|
| `MarkupJsonldModels::getTemplates()` | [[TemplatesArray]] | Templates eligible for JSON-LD models. Default: all non-system templates with `html` (or unset) content type. |
| `MarkupJsonldModels::getExampleFields(Fieldgroup $fieldgroup, Page $page = null)` | [[FieldsArray]] | Fields shown as placeholder hints in the admin field notes. |
| `MarkupJsonldModels::populateModel(string $jsonld, Page $page)` | `string` | The placeholder-population step. Hook to modify the resolved model before it is rendered. |
| `MarkupJsonldModels::populatePagefile(Pagefile $pagefile)` | `array` | Conversion of any [[Pagefile]] placeholder to a `DigitalDocument` array. |
| `MarkupJsonldModels::populatePageimage(Pageimage $pageimage)` | `array` | Conversion of any [[Pageimage]] placeholder to an `ImageObject` array. |
| `MarkupJsonldModels::getBreadcrumbList(Page $page)` | `array` | Items rendered for the `{breadcrumbList}` placeholder. |
| `MarkupJsonldModels::getBreadcrumbListItem(Page $page, int $position)` | `array` | Shape of an individual breadcrumb `ListItem`. |
| `MarkupJsonldModels::getBreadcrumbPages(Page $page)` | [[PageArray]] | Pages included in the breadcrumb list (default: parents + self). |

## Non-hookable methods

```php
$markupJsonldModels = $modules->get('MarkupJsonldModels');

// Clear the module's cache of resolved models for pages
$markupJsonldModels->clearCache();

// Clear specific cache entries, e.g. for a specific template or page
$markupJsonldModels->clearCache('templateName.*'); // clear all pages for a template
$markupJsonldModels->clearCache('templateName.pageId'); // clear a specific page

// Populate placeholders in a JSON-LD string (used internally before rendering)
$jsonld = '{"@context": "https://schema.org", "@type": "WebPage", "name": "{page.title}"}';
$populatedJsonld = $markupJsonldModels->populatePlaceholders($jsonld, $page, [
	'prefix' => 'page', // The prefix for placeholders to populate, e.g. 'page' for {page.title}, 'setting' for {setting.foo}, etc.
	'truncate' => 100, // optional, truncate resolved values to a certain length
	// Any other options you want to pass to WireTextTools::populatePlaceholders()
	// can also be passed here and they will be forwarded to that method when
	// the module populates placeholders in the model.
]);
```

`populatePlaceholders()` forwards options to [[WireTextTools::populatePlaceholders()]].

## See also

- [README.md](README.md) — placeholder syntax, hook examples, output behaviour, troubleshooting.
- [Schema.org](https://schema.org/) — vocabulary reference.
- [Google's structured data documentation](https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data).

## Notes

- This module is autoloaded. Retrieve an instance with `$modules->get('MarkupJsonldModels')`.
- The `jsonld_model` field is installed with the module but is not added to any template by default.
- Model resolution precedence is page > template > default; an empty value falls through to the next level.
- The API does not validate JSON — invalid JSON is silently skipped at render (and logged for review).
- When `$config->debug` is true, `$page->jsonldOutput` bypasses the resolved-model cache and is recomputed on every request.
- Readable/writable module config keys include `jsonld_model` (default model), `placeholders_ignore` (newline-separated placeholder names left un-populated) and `engineer_instructions` (AI agent site notes).

**Source file:** site/modules/MarkupJsonldModels/MarkupJsonldModels.module.php
