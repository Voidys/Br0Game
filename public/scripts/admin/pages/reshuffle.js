/**
 * Universe reshuffle: posts the form (after the data-confirm question of the button) in the background
 * to admin?page=reshuffle&action=send&universe=<selected universe> and shows the answer in the dialog.
 */
import { postToDialog } from '../ajaxForm.js';

const form = document.getElementById('message');

form?.addEventListener('submit', (event) => {
  event.preventDefault();
  const universe = form.elements.universe.value;
  postToDialog(form, `admin?page=reshuffle&action=send&universe=${universe}`);
});
