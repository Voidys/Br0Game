/**
 * Resources page: "Ramp up" / "Shut down" set every production selector (prod[<id>], 0..10 in steps of 10 %)
 * to 100 % resp. 0 % and submit the normal settings form.
 */
const form = document.querySelector('form.prod-form');

form?.addEventListener('click', (event) => {
	const button = event.target.closest('[data-prod-all]');
	if (!button) return;
	const value = button.dataset.prodAll;
	form.querySelectorAll('select[name^="prod["]').forEach((select) => { select.value = value; });
	form.requestSubmit();
});
