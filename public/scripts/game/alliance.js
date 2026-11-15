/**
 * Alliance pages: member list formatting (points, registration date), the rank selects of the member
 * administration, the application answer buttons and the "new rank" dialog.
 * Markup: select.rankSelect (change), data-action="set-field", data-action="dialog-show" (built-in);
 * registration cells carry data-with-time to show the clock time as well.
 */
import { qsa, on, post, serialize, NotifyBox, pretty_number } from '../app/core.js';
import { registerActions } from '../app/actions.js';

const months = window.months ?? [];

/** rank select of a member (admin): saved at once */
on(document, 'change', '.rankSelect', async (event, select) => {
  NotifyBox(await post('game?page=alliance&mode=admin&action=rank&ajax=1', serialize([select]), 'json'));
});

registerActions({
  /**
   * Circular message / diplomacy request of the #message form: posts it to data-url (JSON answer {message, error}),
   * then data-after="close" closes the dialog, "reload" reloads the page; data-empty: text for an empty message.
   */
  'alliance-message-send': async (button) => {
    const form = document.getElementById('message');
    if (form.elements.text.value === '') {
      window.Dialog.alert(button.dataset.empty);
      return;
    }
    const data = await post(button.dataset.url, serialize(form), 'json');
    window.Dialog.alert(data.message, () => {
      if (data.error) return;
      if (button.dataset.after === 'reload') window.location.reload();
      else window.Dialog.close();
    });
  },
  /** answer buttons of an application: the pressed button's value goes into the hidden field data-field */
  'set-field': (button) => {
    button.form.elements[button.dataset.field].value = button.value;
  },
});

window.onPageInit(() => {
  qsa('.points').forEach((cell) => { cell.innerHTML = pretty_number(cell.innerHTML, 0); });
  qsa('.registerTime').forEach((cell) => {
    const date = new Date(cell.innerHTML * 1000);
    const day = `${date.getDate()}. ${months[date.getMonth()]} ${date.getFullYear()}`;
    // the member administration also shows the time
    cell.innerHTML = cell.dataset.withTime !== undefined
      ? `${day} ${date.getHours()}:${date.getMinutes()}:${date.getSeconds()}`
      : day;
  });
});
