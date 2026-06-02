document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('textarea[name="jsonld_model"]')?.forEach(textarea => cm6.initTextareaEditor(textarea));
});
