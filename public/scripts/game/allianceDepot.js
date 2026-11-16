import { on, pretty_number } from '../app/core.js';

/**
 * Alliance Depot page: shows the deuterium cost (hours times the fleet's hourly cost, data-hourly of the form) next to
 * the hours select (data-depot-hours) and updates it when the choice changes.
 */
on(document, 'change', '[data-depot-hours]', (event, select) => {
  const form = select.closest('form');
  const output = form?.querySelector('[data-depot-cost]');
  if (output) {
    output.textContent = pretty_number(Number(select.value) * Number(form.dataset.hourly));
  }
});
