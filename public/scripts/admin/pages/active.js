/**
 * Not yet activated accounts: the "activate" links (data-validation-id / data-validation-key) call the
 * validation endpoint, show its answer and reload the list.
 */
import { on } from '../../app/core.js';
import { Dialog } from '../../app/dialog.js';

on(document, 'click', '[data-validation-id]', async (event, link) => {
  event.preventDefault();
  const { validationId, validationKey } = link.dataset;
  const response = await fetch(`./?page=vertify&mode=json&i=${encodeURIComponent(validationId)}&k=${encodeURIComponent(validationKey)}`, { credentials: 'same-origin' });
  Dialog.alert(await response.json(), () => window.location.reload());
});
