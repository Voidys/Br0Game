/** Achievements page: the filter chips ([data-filter]) set data-ach-filter on the list, CSS does the hiding. */
import { qsa, on } from '../app/core.js';

on(document, 'click', '[data-ach-filter] [data-filter]', (event, chip) => {
  const root = chip.closest('[data-ach-filter]');
  root.dataset.achFilter = chip.dataset.filter;
  qsa('[data-filter]', root).forEach((other) => other.setAttribute('aria-pressed', String(other === chip)));
});
