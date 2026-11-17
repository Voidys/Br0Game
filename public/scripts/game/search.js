import { qs, on, getText, hide, show } from '../app/core.js';

/**
 * Player/alliance search page: live results while typing. Requests the result route (`data-result-url` of the search bar, ajax) on every
 * relevant key press or type change and swaps the result table into the page.
 */
const IGNORED_KEYS = [91, 18, 20, 188, 17, 40, 35, 13, 27, 36, 45, 37, 93, 107, 110, 111, 108, 106, 109, 34, 33, 190, 39, 16, 32, 9, 38];

/** Runs the search for the current type and text; modifier/navigation keys (IGNORED_KEYS, by keyCode) and Enter do not trigger a request. */
async function instant(event) {
  if (event.type === 'keyup') {
    if (event.keyCode === 13) {
      event.preventDefault();
    }
    if (IGNORED_KEYS.includes(event.keyCode)) {
      return;
    }
  }

  show(qs('#loading'));
  const data = await getText(`${qs('.search-bar').dataset.resultUrl}?type=${qs('#type').value}&search=${qs('#searchtext').value}&ajax=1`);
  qs('#resulttable')?.remove();
  qs('#content > table:not(.hack)')?.insertAdjacentHTML('afterend', data);
  hide(qs('#loading'));
}

window.onPageInit(() => {
  on(document, 'keyup', '#searchtext', instant);
  on(document, 'change', '#type', instant);
});
