# Markup JSON-LD Models

## Overview
This module allows you to define JSON-LD models on a per-page and per-template basis with placeholder support. It also allows you to define a default model in the module settings that will be used if no page or template-specific model is found. The module includes an API for managing these models programmatically, and several hooks for modifying its behaviour and the output of the JSON-LD models.

<!-- TOC -->
## Table of Contents
- [Overview](#overview)
- [Installation](#installation)
- [Usage](#usage)
  - [Models](#models)
  - [Eligible pages and templates](#eligible-pages-and-templates)
  - [Placeholders](#placeholders)
- [Configuration](#configuration)
  - [Default JSON-LD model](#default-json-ld-model)
  - [JSON-LD Model by Template](#json-ld-model-by-template)
  - [JSON-LD Model by Page: JSON-LD field](#json-ld-model-by-page-json-ld-field)
- [Output](#output)
  - [JSON-LD output format](#json-ld-output-format)
  - [$page->jsonldModel](#page-jsonldmodel)
  - [$page->jsonldOutput](#page-jsonldoutput)
- [Hooks](#hooks)
  - [Changing module behaviour](#changing-module-behaviour)
  - [Modifying placeholder values and model output](#modifying-placeholder-values-and-model-output)
- [Example Model and Output](#example-model-and-output)
- [Working with Agent Tools](#working-with-agent-tools)
  - [Providing context for AI agents](#providing-context-for-ai-agents)
  - [Example prompts](#example-prompts)
- [Caching](#caching)
- [Troubleshooting and Debugging](#troubleshooting-and-debugging)
- [License](#license)
- [Further Reading and Resources](#further-reading-and-resources)
- [API.md](#apimd)
<!-- /TOC -->

## Installation
1. Download the [zip file](https://github.com/nbcommunication/MarkupJsonldModels/archive/main.zip) at Github or clone the repo into your `site/modules` directory.
2. If you downloaded the zip file, extract it in your `sites/modules` directory.
3. In your admin, go to Modules > Refresh, then Modules > New, then click on the Install button for this module.

**ProcessWire >= 3.0.218 and PHP >= 7.1 are required to use this module.**

## Usage

### Models
JSON-LD models are defined using valid JSON syntax, and should include placeholders that will be replaced with dynamic values when the model is output on the front end. To ensure the JSON-LD is valid, placeholders should be wrapped in double quotes in the model, e.g.:
```json
{
	"@context": "https://schema.org",
	"@type": "WebPage",
	"name": "{page.title}"
}
```

Models can be defined on a per-page basis using the `jsonld_model` field, on a per-template basis, or as the default model in the module settings. The module will look for models in that order (page > template > default) and use the first one it finds.

In each case the field for entering the model is a textarea that accepts JSON input, and **requires the JSON to be valid before it is saved**. The field uses CodeMirror 6 to provide syntax highlighting and error checking for the JSON input, and will show an error message if the JSON is invalid. Please note that if updating models programmatically via the API, you must ensure the JSON is valid before saving.

A list of example placeholders is appended to the field via `Inputfield::notes`. For the `jsonld_model` field, the example placeholders are determined by the page itself, whereas the template examples will output the field's label. The rendered values are truncated in the notes to avoid excessively long placeholder examples.

By default, the example placeholders only use simple field types such as Text and Integer, as these are easier to represent in a simple example. You can amend the list of fields used for the examples by hooking `MarkupJsonldModels::getExampleFields` (see Hooks section below).

### Eligible pages and templates
The module will only output JSON-LD for pages that are rendered as HTML, and have a model to output. By default, the module will only look for models on non-system templates that are configured to output as HTML, but this can be changed using hooks. Please see the Hooks section below for more information.

Admin pages and non-HTML responses (JSON, XML, etc.) are automatically excluded from JSON-LD output, as these are unlikely to be relevant contexts for JSON-LD. If you wish to include JSON-LD in these contexts, you can use hooks to modify the list of eligible templates (see Hooks section below).

### Placeholders
The module supports placeholders in the JSON-LD models, which are replaced with dynamic values when the model is output on the front end. The placeholder format uses the same single curly braces format as `WireTextTools::populatePlaceholders()`, and supports most page fields, as well as some custom placeholders provided by the module. For example:

- {page.fieldname} — any page field
- {page.fieldname|otherfieldname} — any page field with fallback to another field if the first is empty
- {page.images}, {page.images.first}, {page.images.last}, {page.images.0} — Pageimages with auto-conversion to ImageObject
- {page.files} / {page.files.first} etc. — Pagefiles → DigitalDocument
- {setting.foo} — values from setting()
- {home.fieldname} — fields from the homepage, e.g. {home.title} or {home.httpUrl}
- {input.httpHostUrl} — Some properties from $input are available* (restricted for safety), and `httpHostUrl` is also available for outputting the full homepage URL.
- {breadcrumbList} — auto-generates a BreadcrumbList array

*Available `$input` properties: url, httpUrl, httpHostUrl, scheme, urlSegment1, urlSegment2, urlSegment3, pageNum, pageNumStr, queryString, urlSegmentStr

The module uses `WireTextTools::populatePlaceholders()` to replace placeholders in the models, so you can expect the same behaviour as you would when using that method, with the addition of some custom handling for certain field types (see below). For example, a Page Reference field will resolve to the page ID by default, but you can use hooks to modify this output to include other page data, such as the title or URL.

> ⚠️ As a model must be valid JSON, placeholders should be wrapped in double quotes, even if they should resolve to an array or object. In these cases the enclosing quotes will be stripped when the placeholder is resolved, allowing the output to be an array or object as needed.

#### Custom handling of certain field types
##### Pagefile
The module resolves Pagefile placeholders into a JSON-LD object with the following properties:
```json
{
	"@context": "https://schema.org",
	"@type": "DigitalDocument",
	"name": "{your_pagefile_field.description}",
	"contentUrl": "{your_pagefile_field.httpUrl}",
	"contentSize": "{your_pagefile_field.filesizeStr}"
}
```
To change the output of a Pagefile placeholder, you can use the `MarkupJsonldModels::populatePagefile` hook (see Hooks section below).

##### Pageimage
The module resolves Pageimage placeholders into a JSON-LD object with the following properties:
```json
{
	"@context": "https://schema.org",
	"@type": "ImageObject",
	"name": "{your_pageimage_field.description}",
	"url": "{your_pageimage_field.httpUrl}",
	"width": "{your_pageimage_field.width}",
	"height": "{your_pageimage_field.height}"
}
```
To change the output of a Pageimage placeholder, you can use the `MarkupJsonldModels::populatePageimage` hook (see Hooks section below).

#### Built-in custom placeholders
Only one built-in custom placeholder is provided: {breadcrumbList}, which generates a JSON-LD BreadcrumbList based on the page's position in the site tree. This is described in more detail below, but you can also modify the output of this placeholder using hooks (see Hooks section below).

##### Breadcrumbs
Breadcrumbs can be used in a model using the {breadcrumbList} placeholder, which will be replaced with a JSON-LD BreadcrumbList based on the page's position in the site tree. For example:
```json
{
	"@context": "https://schema.org",
	"@type": "BreadcrumbList",
	"itemListElement": "{breadcrumbList}"
}
```
This will output a JSON-LD BreadcrumbList with each item containing the page title and URL. You can modify the output of each breadcrumb item, or the entire breadcrumb list, using hooks (see below).

Please note that the {breadcrumbList} placeholder should be used within a JSON-LD context as shown above.

#### Notes
- Date fields (created, modified, published, unpublished, any FieldtypeDatetime) are auto-converted to ISO 8601
- Numeric/bool placeholders are auto-unquoted in the JSON output
- If a placeholder resolves to null or empty, the placeholder text is replaced with an empty string. The surrounding JSON key/value pair is preserved — so e.g. `"name": "{page.empty}"` becomes `"name": ""` rather than being removed entirely.

## Configuration
You should start configuring your JSON-LD models by first defining a default model in the module settings, then adding models for templates in the list of eligible templates, and finally adding page-specific models where needed.

### Default JSON-LD model
The module configuration (Modules > Configuration > Markup JSON-LD Models) allows you to define a default JSON-LD model that will be used for any pages that don't have a specific model defined.

### JSON-LD Model by Template
If you wish to define a model for all pages using a specific template, edit the template and navigate to the JSON-LD Model tab to add a model for that template. This is useful for defining a default model for a specific content type, e.g. an Article template.

By default, only templates that are configured to output as HTML will show the JSON-LD Model tab. To override or amend this behaviour, you can use the `MarkupJsonldModels::getTemplates` hook (see Hooks section below).

### JSON-LD Model by Page: JSON-LD field
On installation the module will add a `jsonld_model` field to your site's Fields. If you wish to define models for specific pages, you should add this field to their templates. When editing a page with the `jsonld_model` field, you will see a textarea where you can enter the JSON-LD model for that page. This allows you to override the default and template models on a per-page basis.

Please note that the expectation is that the `jsonld_model` field will be added to a page template, and not to sub-resources such as Repeater items or Pagefile custom fields. This type of implementation has not been tested and may cause unexpected behaviour.

## Output
Although every eligible page will be assigned a JSON-LD model from one of the above sources, the module will only output JSON-LD for pages that are rendered as HTML, have a model to output, and do not already have a JSON-LD script in the `<head>`. This is to avoid conflicts with other modules or custom implementations that may be adding JSON-LD to the `<head>`.

### JSON-LD output format
If the model is defined as an array of JSON-LD objects, the module will wrap this in a @graph object to ensure valid JSON-LD output. For example, if your model is defined as:
```json
[
	{
		"@context": "https://schema.org",
		"@type": "WebPage",
		"name": "{page.title}"
	},
	{
		"@context": "https://schema.org",
		"@type": "Organization",
		"name": "{setting.organisationName}"
	}
]
```
The module will output this as:
```json
{
	"@context": "https://schema.org",
	"@graph": [
		{
			"@type": "WebPage",
			"name": "Example page title"
		},
		{
			"@type": "Organization",
			"name": "Example Organization"
		}
	]
}
```

Please note that this hard-codes https://schema.org as the outer context. If your model includes multiple objects with different contexts, you should ensure that the individual objects include their own @context property, and the module will preserve these in the output. In other words, do not define your model as a JSON array.

### $page->jsonldModel
If the page is eligible for JSON-LD output, `$page->jsonldModel` will return the resolved JSON-LD model for that page, before placeholders are replaced by their dynamic values.

### $page->jsonldOutput
If the page is eligible, the module adds a property to the page object called `jsonldOutput`, which is the final JSON-LD model after placeholders have been replaced, and is what is output in the front end. This can be used in your templates to output the JSON-LD in a custom location, or to modify it further before outputting.

Accessing this property is useful if you wish to output the model in another location, such as an API endpoint returning page data as JSON.

### Notes
- The JSON output in the field is pretty-printed for ease of editing, but is minified when saving to reduce size. The output in the front end is also minified when the model is rendered, unless logged in as a superuser, in which case it is pretty-printed to make it easier to read when viewing source.

## Hooks
The module includes several hookable methods that allow you to modify its behaviour and the output of the JSON-LD models. These should be added in your `/site/ready.php` file.

### Changing module behaviour

#### MarkupJsonldModels::getTemplates()
Change the list of templates that are eligible for JSON-LD models. In this example, we make all templates eligible except system ones, regardless of their content type:
```php
$wire->addHookBefore('MarkupJsonldModels::getTemplates', function(HookEvent $event) {
	$modelTemplates = new TemplatesArray();
	foreach($event->wire()->templates as $template) {
		if($template->flags & Template::flagSystem) {
			continue;
		}
		// $contentType = $template->contentType;
		// if($contentType && $contentType !== 'html') {
		// 	continue;
		// }
		$modelTemplates->add($template);
	}
	$event->replace = true;
	$event->return = $modelTemplates;
});
```

Add a specific template to the list of templates that are eligible for JSON-LD models:
```php
$wire->addHookAfter('MarkupJsonldModels::getTemplates', function(HookEvent $event) {
	$modelTemplates = $event->return;
	$modelTemplates->add($event->wire()->templates->get('your-template'));
	$event->return = $modelTemplates;
});
```

#### MarkupJsonldModels::getExampleFields()
Change the list of fields that are available as example placeholders:
```php
$wire->addHookAfter('MarkupJsonldModels::getExampleFields', function(HookEvent $event) {
	$fieldgroup = $event->arguments(0);
	$page = $event->arguments(1); // If the Page argument is provided we can get the field in this page's context
	if(!$fieldgroup->count()) {
		return;
	}
	$exampleFields = $event->return;
	$exampleFields->add($page ? $page->getField('your_field_name') : $fieldgroup->get('your_field_name'));
	$exampleFields->remove($page ? $page->getField('field_to_remove') : $fieldgroup->get('field_to_remove'));
	$event->return = $exampleFields;
});
```

### Modifying placeholder values and model output

#### MarkupJsonldModels::populateModel()
Populate a custom placeholder:
```php
$wire->addHookBefore('MarkupJsonldModels::populateModel', function(HookEvent $event) {
	$jsonld = $event->arguments(0);
	$page = $event->arguments(1);
	$jsonld = str_replace(
		'{test.statement}',
		'This is a test of the MarkupJsonldModels module for the page ' . $page->title,
		$jsonld
	);
	$event->arguments(0, $jsonld);
});
```

Amend the output of the JSON-LD model:
```php
$wire->addHookAfter('MarkupJsonldModels::populateModel', function(HookEvent $event) {
	$jsonld = $event->return;
	$page = $event->arguments(1);
	$jsonld = str_replace(
		'This is a test of the MarkupJsonldModels module ', // the value we set in the previous hook
		'This is a test of the MarkupJsonldModels module - modified in ready.php - ',
		$jsonld
	);
	$jsonld = str_replace(
		$page->title,
		"{$page->title} (#{$page->id})",
		$jsonld
	);
	$event->return = $jsonld;
});
```

#### MarkupJsonldModels::populatePagefile()
Change the output of a `Pagefile` placeholder:
```php
// Example model: {"your_pagefile_field": "{page.your_pagefile_field}"}
// This hook fires for any Pagefile placeholder, regardless of how it is accessed,
// e.g. {page.your_pagefile_field}, {page.your_pagefile_field.first}, {page.your_pagefile_field.0}, etc.
$wire->addHookBefore('MarkupJsonldModels::populatePagefile', function(HookEvent $event) {
	$pagefile = $event->arguments(0);
	$event->replace = true;
	$event->return = $pagefile->httpUrl;
});
```

#### MarkupJsonldModels::populatePageimage()
Change the output of a `Pageimage` placeholder:
```php
// Example model: {"your_pageimage_field": "{page.your_pageimage_field}"}
// This hook fires for any Pageimage placeholder, regardless of how it is accessed,
// e.g. {page.your_pageimage_field}, {page.your_pageimage_field.first}, {page.your_pageimage_field.0}, etc.
$wire->addHookBefore('MarkupJsonldModels::populatePageimage', function(HookEvent $event) {
	$pageimage = $event->arguments(0);
	$event->replace = true;
	$event->return = ['url' => $pageimage->httpUrl];
});
```

#### MarkupJsonldModels::getBreadcrumbListItem()
Amend the output of each breadcrumb list item in the {breadcrumbList} placeholder:
```php
$wire->addHookAfter('MarkupJsonldModels::getBreadcrumbListItem', function(HookEvent $event) {
	$page = $event->arguments(0);
	$position = $event->arguments(1);
	$breadcrumbListItem = $event->return;
	$breadcrumbListItem['name'] = $page->getUnformatted('headline|title');
	$event->return = $breadcrumbListItem;
});
```

#### MarkupJsonldModels::getBreadcrumbList()
Amend the output of the entire breadcrumb list in the {breadcrumbList} placeholder:
```php
$wire->addHookAfter('MarkupJsonldModels::getBreadcrumbList', function(HookEvent $event) {
	$breadcrumbList = $event->return;
	foreach($breadcrumbList as &$breadcrumb) {
		$breadcrumb['name'] = strtoupper($breadcrumb['name']);
	}
	$breadcrumbList[] = [
		'@type' => 'ListItem',
		'position' => count($breadcrumbList) + 1,
		'name' => 'EXTRA ITEM',
		'item' => '/extra-item',
	];
	$event->return = $breadcrumbList;
});
```

#### MarkupJsonldModels::getBreadcrumbPages()
Amend the breadcrumb pages used in the {breadcrumbList} placeholder:
```php
$wire->addHookAfter('MarkupJsonldModels::getBreadcrumbPages', function(HookEvent $event) {
	$page = $event->arguments(0);
	$breadcrumbPages = $event->return;
	$breadcrumbPages->remove($page->rootParent);
	$event->return = $breadcrumbPages;
});
```

## Example Model and Output
Here is an example model with various placeholders:
```json
{
	"@context": "https://schema.org",
	"@type": "WebPage",
	"name": "{page.title}",
	"headline": "{page.headline|page.title}",
	"url": "{page.httpUrl}",
	"image": "{page.images.first}",
	"file": "{page.files.first}"
}
```
Here is an example of how that model might be rendered on the front end after placeholder replacement:
```json
{
	"@context": "https://schema.org",
	"@type": "WebPage",
	"name": "Example page title",
	"headline": "Example page headline",
	"url": "https://example.com/example-page",
	"image": {
		"@context": "https://schema.org",
		"@type": "ImageObject",
		"name": "Example image description",
		"url": "https://example.com/image.jpg",
		"width": 800,
		"height": 600
	},
	"file": {
		"@context": "https://schema.org",
		"@type": "DigitalDocument",
		"name": "Example file",
		"contentUrl": "https://example.com/file.pdf",
		"contentSize": "15.2 kB"
	}
}
```

## Working with Agent Tools
The module has been designed in part to be used alongside Agent Tools, to allow AI agents to generate JSON-LD models for pages on the site.

### Providing context for AI agents
The `engineer_instructions` field in the module configuration can be used to provide notes and context for the AI agent to help it generate more accurate and relevant models. When generating JSON-LD models with an AI agent, you can use the module's API to create or update page-specific models, or to modify the default or template models as needed.

The expectation is the an AI agent may update the notes field itself as it learns more about the site and the types of content it contains, so this field can be used as a dynamic source of information for the agent to refer to when generating models.

### Example prompts
Here are some example prompts you could use with an AI agent to generate JSON-LD models using this module:
- "MarkupJsonldModels - review default model in the module config"
- "MarkupJsonldModels - review model for the 'home' template"
- "MarkupJsonldModels - review model for the '/example-page/' page"
- "MarkupJsonldModels - suggest a default model for the site"
- "MarkupJsonldModels - suggest a model for template 'example-template'"
- "MarkupJsonldModels - suggest a model for page /example-page/"
- "MarkupJsonldModels - review the notes in the module config"

## Caching
When `$config->debug` is not enabled, this module implements a simple caching layer to store the resolved JSON-LD models for each page, to improve performance and reduce the processing needed on each page load.

- The cache for a page is automatically cleared when a page is saved.
- When a template is saved, the cache for all pages using that template is cleared.
- When the default model in the module config is updated, the cache for all pages is cleared.

You can also trigger the cache to be cleared by clicking the **Clear cache** button on the module config screen.

> ⚠️ If you are updating models programmatically via the API, you should ensure that the cache is cleared after making changes to the models, to ensure the changes are reflected in the output. You can clear the cache by calling `$markupJsonldModels->clearCache()`.

## Troubleshooting and Debugging
When logged in as a superuser and `$config->debug` is enabled, if the JSON-LD model is not valid the invalid JSON will be logged to `markup-jsonld-models`.

If a model is not output on the front end as expected, you can check the following:
- Check the page is eligible for JSON-LD output (is rendered as HTML, has a model defined, and does not already have a JSON-LD script in the head).
- Check the JSON in the model field is valid and properly formatted.
- Check the placeholders in the model are correct and resolve to the expected values.
- Check for any hooks that may be modifying the model or placeholder values in unexpected ways.

## License
This module is licensed under MPL-2.0. See the LICENSE file for more information.

## Further Reading and Resources
- [Google Structured Data Markup Helper](https://www.google.com/webmasters/markup-helper/)
- [Schema Markup Validator](https://validator.schema.org/)
- [Schema.org JSON-LD documentation](https://schema.org/docs/jsonld.html)
- [ProcessWire Hooks documentation](https://processwire.com/docs/modules/hooks/)
- [ProcessWire API documentation](https://processwire.com/api/)


## API.md
See API.md for programmatic usage of the module via the ProcessWire API.
