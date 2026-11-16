/**
 * Fleet table, ACS group box: "change" next to the group name asks for a new name and saves it
 * (data-action="acs-rename", data-fleet / data-prompt / data-name on the link).
 */
import { registerActions } from '../app/actions.js';

/** asks for a name, saves it with the server and shows it; the server answers "" when it worked */
async function rename(link) {
  const name = await window.Dialog.prompt(link.dataset.prompt, { value: link.dataset.name });
  if (name === null) return;
  const response = await fetch(`?page=fleetTable&action=acs&fleetID=${encodeURIComponent(link.dataset.fleet)}&acsName=${encodeURIComponent(name)}`, {
    method: 'POST',
    credentials: 'same-origin',
  });
  const data = await response.json();
  if (data !== '') {
    window.Dialog.alert(data);
    return;
  }
  document.getElementById('acsName').textContent = name;
}

registerActions({ 'acs-rename': rename });
