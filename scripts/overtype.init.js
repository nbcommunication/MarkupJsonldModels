document.addEventListener('DOMContentLoaded', () => {

	const textarea = document.querySelector('#Inputfield_engineer_instructions');
	if (textarea) {

		const editor = document.createElement('div');
		editor.style.width = '100%';
		editor.style.height = '500px';
		textarea.style.display = 'none';
		textarea.parentNode.insertBefore(editor, textarea.nextSibling);

		const otEditor = new OverType(editor, {
			value: textarea.value,
			showStats: true,
			toolbar: true,
			onChange: (value) => {
				textarea.value = value;
			}
		});
	}
});
