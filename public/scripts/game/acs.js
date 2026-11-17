/**
 * Fleet table, ACS group box: "change" next to the group name asks for a new name and saves it
 * (data-action="acs-rename", data-url / data-fleet / data-prompt / data-name on the link).
 */
import { registerActions } from '../app/actions.js';
import { Dialog } from '../app/dialog.js';

/** asks for a name, saves it with the server and shows it; the server answers "" when it worked */
async function rename(link) {
  const name = await Dialog.prompt(link.dataset.prompt, { value: link.dataset.name });
  if (name === null) return;
  const response = await fetch(`${link.dataset.url}?fleetID=${encodeURIComponent(link.dataset.fleet)}&acsName=${encodeURIComponent(name)}`, {
    method: 'POST',
    credentials: 'same-origin',
  });
  const data = await response.json();
  if (data !== '') {
    Dialog.alert(data);
    return;
  }
  document.getElementById('acsName').textContent = name;
}

registerActions({ 'acs-rename': rename });
