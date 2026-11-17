import { qs, on, getText, hide, show } from '../app/core.js';

/**
 * Player/alliance search page: live results while typing, and the Search button / Enter run the same search. Requests the result route
 * (`data-result-url` of the form, ajax) and swaps the result table into `#search-results`. An empty text clears the results and shows the hint.
 */
const MIN_LENGTH = 2;
let pending = 0;

/** Runs the search for the current type and text. */
async function instant(event) {
  if (event.type === 'submit') {
    event.preventDefault();
  }
  const form = qs('#search-form');
  const results = qs('#search-results');
  const text = qs('#searchtext').value.trim();
  if (text.length < MIN_LENGTH) {
    results.innerHTML = `<p class="hint search-hint">${form.dataset.hint}</p>`;
    return;
  }

  const request = ++pending;
  show(qs('#loading'));
  const data = await getText(`${form.dataset.resultUrl}?type=${encodeURIComponent(qs('#type').value)}&search=${encodeURIComponent(text)}&ajax=1`);
  if (request === pending) {
    results.innerHTML = data;
    hide(qs('#loading'));
  }
}

window.onPageInit(() => {
  on(document, 'input', '#searchtext', instant);
  on(document, 'change', '#type', instant);
  on(document, 'submit', '#search-form', instant);
});
