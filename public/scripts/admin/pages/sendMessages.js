/**
 * Global message: live recipient summary and character counter, and the background send. Texts come from
 * the form's data attributes (data-empty-text, data-confirm-text); the answer of the server is shown in
 * the dialog and closing it reloads the page.
 */
import { Dialog } from '../../app/dialog.js';
import { postToDialog } from '../ajaxForm.js';

const form = document.getElementById('message');
const selects = ['ma-mode', 'ma-universe', 'ma-lang'].map((id) => document.getElementById(id)).filter(Boolean);

/** "mode · universe · language" as currently selected */
const recipientSummary = () => selects
  .map((select) => (select.selectedOptions.length ? select.selectedOptions[0].textContent.trim() : ''))
  .filter(Boolean)
  .join(' · ');

const summary = document.getElementById('ma-summary');
const update = () => { summary.textContent = recipientSummary(); };
selects.forEach((select) => select.addEventListener('change', update));
update();

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (form.elements.text.value.length === 0) {
    Dialog.alert(form.dataset.emptyText);
    return;
  }
  if (!(await Dialog.confirm(`${form.dataset.confirmText}\n${recipientSummary()}`))) return;
  postToDialog(form, 'admin?page=sendMessages&action=send&ajax=1');
});
