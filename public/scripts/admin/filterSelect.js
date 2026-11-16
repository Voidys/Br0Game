/**
 * Live filter for <select> lists (replaces the old filterlist.js). The select is named, the controls
 * point at it by name inside the same form:
 *
 *   <input type="search" data-filter-select="id_1">     filters the options while typing (regular
 *                                                      expression, case-insensitive, on the option text)
 *   <button type="button" data-filter-reset="id_1">     clears the filter (and the search field)
 *   <a data-filter-select="id_1" data-filter-pattern="^A">  applies a fixed pattern (alphabet bar)
 *   <select data-submit-on-dblclick>                    submits the form on a double click of an option
 *
 * An invalid pattern keeps the current list. Enter in a filter field never submits the form.
 */

const originals = new WeakMap();

/** the select a control points at (name looked up in the control's form) */
function targetOf(control, name) {
  const select = control.form?.elements[name] ?? control.closest('form')?.elements[name];
  return select instanceof HTMLSelectElement ? select : null;
}

/** options of the select as first seen, so later filters always start from the full list */
function allOptions(select) {
  if (!originals.has(select)) {
    originals.set(select, [...select.options].map((option) => ({ text: option.text, value: option.value || option.text })));
  }
  return originals.get(select);
}

/** Shows only the options whose text matches `pattern` (case-insensitive regular expression); '' restores all. An invalid expression leaves the list unchanged. */
export function filterSelect(select, pattern) {
  const options = allOptions(select);
  let expression;
  try {
    expression = new RegExp(pattern, 'i');
  } catch {
    return;
  }
  select.options.length = 0;
  for (const option of options) {
    if (expression.test(option.text)) select.add(new Option(option.text, option.value));
  }
}

/** Delegated listeners for the controls described in the file header (typing, reset, fixed patterns, double click submit). */
export function initFilterSelect() {
  document.addEventListener('input', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.dataset.filterSelect || input.dataset.filterPattern !== undefined) return;
    const select = targetOf(input, input.dataset.filterSelect);
    if (select) filterSelect(select, input.value);
  });

  document.addEventListener('keydown', (event) => {
    const input = event.target;
    if (event.key === 'Enter' && input instanceof HTMLInputElement && input.dataset.filterSelect && input.dataset.filterPattern === undefined) {
      event.preventDefault();
    }
  });

  document.addEventListener('click', (event) => {
    const control = event.target instanceof Element ? event.target.closest('[data-filter-pattern], [data-filter-reset]') : null;
    if (!control) return;
    event.preventDefault();
    const name = control.dataset.filterPattern !== undefined ? control.dataset.filterSelect : control.dataset.filterReset;
    const select = targetOf(control, name);
    if (!select) return;
    if (control.dataset.filterPattern !== undefined) {
      filterSelect(select, control.dataset.filterPattern);
    } else {
      filterSelect(select, '');
      const search = control.closest('form')?.querySelector(`input[data-filter-select="${name}"]`);
      if (search) search.value = '';
    }
  });

  document.addEventListener('dblclick', (event) => {
    const select = event.target instanceof Element ? event.target.closest('select[data-submit-on-dblclick]') : null;
    if (select?.value) select.form.submit();
  });
}
