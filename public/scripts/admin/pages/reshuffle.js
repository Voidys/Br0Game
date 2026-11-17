/**
 * Universe reshuffle: posts the form (after the data-confirm question of the button) in the background
 * to the form's action (the send route, with the selected universe field) and shows the answer in the dialog.
 */
import { postToDialog } from '../ajaxForm.js';

const form = document.getElementById('message');

form?.addEventListener('submit', (event) => {
  event.preventDefault();
  postToDialog(form, form.action);
});
