/**
 * Jump gate (information dialog): "max" fills a ship type's amount, the jump button sends the fleet.
 * Markup: data-action="gate-max" data-id="<ship id>" / data-action="gate-submit" data-url="<jump route>".
 */
import { qs, qsa, post, serialize } from '../app/core.js';
import { registerActions } from '../app/actions.js';
import { Dialog } from '../app/dialog.js';

const Gate = {
  max(ID) {
    const cell = qs(`#ship${ID}_value`);
    const raw = cell.getAttribute('data-count');
    qs(`#ship${ID}_input`).value = (raw !== null && raw !== '') ? raw : cell.textContent.replace(/\D/g, '');
  },

  async submit(url) {
    const data = await post(url, serialize(qsa('.jumpgate')), 'json');
    Dialog.alert(data.message, () => {
      if (!data.error) Dialog.close();
    });
  },
};

registerActions({
  'gate-max': (button) => Gate.max(button.dataset.id),
  'gate-submit': (button) => Gate.submit(button.dataset.url),
});
