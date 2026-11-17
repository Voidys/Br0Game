/**
 * Rename / delete planet (overview actions modal, Dialog.PlanetAction): the tabs, and the two buttons
 * (data-action="rename-planet" / "delete-planet", the route in data-url). The main planet's id and the overview URL come from
 * #overview-actions-data.
 */
import { qs, post, pageData } from '../app/core.js';
import { tabs } from '../app/ui.js';
import { registerActions } from '../app/actions.js';
import { Dialog } from '../app/dialog.js';

/** the modal's content is new each time it opens, the module itself runs once: set up on every load */
const init = () => tabs(document.getElementById('planet-actions-tabs'));
init();
document.addEventListener('modal:load', init);

async function checkrename() {
  if (qs('#name').value.trim() === '') {
    return false;
  }
  const response = await post(qs('[data-action="rename-planet"]').dataset.url, { name: qs('#name').value }, 'json');
  Dialog.alert(response.message, () => {
    if (!response.error) location.reload();
  });
}

async function checkdelete() {
  const password = qs('#password').value;
  if (password === '') {
    return false;
  }
  const response = await post(qs('[data-action="delete-planet"]').dataset.url, { password }, 'json');
  Dialog.alert(response.message, () => {
    const { mainPlanet, overviewUrl } = pageData('overview-actions-data');
    if (response.ok) location.href = `${overviewUrl}?cp=${mainPlanet}`;
  });
}

registerActions({ 'rename-planet': checkrename, 'delete-planet': checkdelete });
