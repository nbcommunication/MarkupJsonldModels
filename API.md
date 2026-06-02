# Markup JSON-LD Models — API

This document describes the programmatic API for managing JSON-LD models with the `MarkupJsonldModels` module.

For details on placeholders, output behaviour, eligible templates, hooks in depth, and the admin UI, see [README.md](README.md).

## Contents

- [Overview](#overview)
- [Getting the module instance](#getting-the-module-instance)
- [Site context notes for AI agents](#site-context-notes-for-ai-agents)
- [Reading models](#reading-models)
- [Writing models](#writing-models)
- [Clearing a model](#clearing-a-model)
- [Adding the `jsonld_model` field to a template](#adding-the-jsonld_model-field-to-a-template)
- [Checking placeholder resolution](#checking-placeholder-resolution)
- [Hookable methods (quick reference)](#hookable-methods-quick-reference)
- [Non-hookable methods](#other-methods)
- [See also](#see-also)

## Overview

JSON-LD models are stored as **compact JSON strings** in three possible locations:

1. **Per page** — on the `jsonld_model` field, if the page's template includes it.
2. **Per template** — on the template's `jsonld_model` property (set via the JSON-LD tab on the template edit screen, or via the API).
3. **Default** — in the module configuration, used when neither of the above is set.

When the module renders JSON-LD for a page it resolves the model in this order: **page > template > default**. An empty value at any level means "fall through to the next level".

The module is autoloaded. On every front-end render of an eligible page (non-system template, `html` content type, no existing `application/ld+json` script in `<head>`), it resolves placeholders and injects the result as a `<script type="application/ld+json">` tag immediately before `</head>`.

> ⚠️ The admin field validates JSON via CodeMirror, but **the API does not**. When setting `jsonld_model` programmatically you are responsible for storing valid JSON. Invalid JSON will simply not be rendered (and is logged for superusers when `$config->debug` is true).

## Getting the module instance

```php
$markupJsonldModels = $modules->get('MarkupJsonldModels');
```

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

## Reading models

### The resolved model for a page

`$page->jsonldModel` returns the resolved JSON-LD string for an eligible page, before placeholders are replaced by their dynamic values. Returns `null` for pages whose template is not eligible.

```php
$model = $page->jsonldModel;
```

### The populated model for a page

`$page->jsonldOutput` returns the **final, placeholder-populated** JSON-LD string for an eligible page, exactly as the module would output it in the head. Returns `null` for pages whose template is not eligible.

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

When writing models via the API you are typically assigning a JSON string to `jsonld_model` on a Page, a Template, or to the `jsonld_model` key of the module config. Use `json_encode()` to convert PHP arrays to JSON.

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

Any placeholders that resolved to an empty value will show as empty strings (e.g. `"name": ""`) in the resolved output, while placeholders that the module could not match at all will remain in the JSON in their original `{prefix.token}` form. This is usually enough to diagnose model issues.

### Inspecting individual placeholders

For a deeper view of how each placeholder resolves — including array/object placeholders such as `Pageimage` and `Pagefile` — call the non-hookable `populatePlaceholders()` method directly per prefix. Unlike `populateModel()`, this method does not apply the final `removeEmptyTags` / `removeNullTags` / `removeQuotes` passes, so you can distinguish "resolved to empty" from "did not match":

```php
$page = $pages->get('/path/to/page/');
$markupJsonldModels = $modules->get('MarkupJsonldModels');
$model = $page->jsonldModel;

// Each supported prefix has its own placeholder set.
$prefixes = ['page', 'setting', 'input', 'breadcrumbs'];

foreach($prefixes as $prefix) {
	if(!preg_match_all('/\{' . preg_quote($prefix) . '\.([a-zA-Z0-9_.|]+)\}/', $model, $m)) continue;

	echo "=== {{$prefix}.*} placeholders ===\n";
	foreach(array_unique($m[1]) as $token) {
		$placeholder = '{' . $prefix . '.' . $token . '}';
		// Pass a single placeholder through populatePlaceholders so we see the raw resolved value.
		$resolved = $markupJsonldModels->populatePlaceholders($placeholder, $page, [
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

For array-valued placeholders (e.g. `{page.images.first}` resolving via `populatePageimage()` to an `ImageObject` array), `populatePlaceholders()` will substitute a JSON-encoded representation in place of the placeholder. If you want the array form rather than the JSON string, hook `populatePageimage` / `populatePagefile` directly, or read the source field on the page (e.g. `$page->images->first()`) and pass it through `$markupJsonldModels->populatePageimage(...)` yourself.

### Page {page.*} placeholders

Properties on a `Page` object can be set dynamically, via hooks or other mechanisms, so checking whether a field exists on the template is not sufficient to determine if a placeholder may resolve to a value.

### Setting {setting.*} placeholders

The `{setting.*}` placeholders can be checked via `setting('foo')` for the relevant setting key, or accessing all settings via `setting()` with no arguments and checking the resulting array.

## Hookable methods (quick reference)

All of the following are hookable. Add hooks in `/site/ready.php`. See the [README](README.md#hooks) for full descriptions and examples.

| Method | Returns | Purpose |
|--------|---------|---------|
| `MarkupJsonldModels::getTemplates()` | `TemplatesArray` | Templates eligible for JSON-LD models. Default: all non-system templates with `html` (or unset) content type. |
| `MarkupJsonldModels::getExampleFields(Fieldgroup $fieldgroup, Page $page = null)` | `FieldsArray` | Fields shown as placeholder hints in the admin field notes. |
| `MarkupJsonldModels::populateModel(string $jsonld, Page $page)` | `string` | The placeholder-population step. Hook to modify the resolved model before it is rendered. |
| `MarkupJsonldModels::populatePagefile(Pagefile $pagefile)` | `array` | Conversion of any `Pagefile` placeholder to a `DigitalDocument` array. |
| `MarkupJsonldModels::populatePageimage(Pageimage $pageimage)` | `array` | Conversion of any `Pageimage` placeholder to an `ImageObject` array. |
| `MarkupJsonldModels::getBreadcrumbList(Page $page)` | `array` | Items rendered for the `{breadcrumbList}` placeholder. |
| `MarkupJsonldModels::getBreadcrumbListItem(Page $page, int $position)` | `array` | Shape of an individual breadcrumb `ListItem`. |
| `MarkupJsonldModels::getBreadcrumbPages(Page $page)` | `PageArray` | Pages included in the breadcrumb list (default: parents + self). |

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
	// Any other options you want to pass to WireTextTools::populatePlaceholders() can also be passed here and they will be forwarded to that method when the module populates placeholders in the model.
]);

```

## See also

- [README.md](README.md) — placeholder syntax, hook examples, output behaviour, troubleshooting.
- [Schema.org](https://schema.org/) — vocabulary reference.
- [Google's structured data documentation](https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data).
