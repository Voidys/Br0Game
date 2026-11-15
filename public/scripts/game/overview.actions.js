/**
 * Rename / delete planet (overview actions modal, Dialog.PlanetAction): the tabs, and the two buttons
 * (data-action="rename-planet" / "delete-planet"). The main planet's id comes from #overview-actions-data.
 */
import { qs, post, pageData } from '../app/core.js';
import { tabs } from '../app/ui.js';
import { registerActions } from '../app/actions.js';

/** the modal's content is new each time it opens, the module itself runs once: set up on every load */
const init = () => tabs(document.getElementById('planet-actions-tabs'));
init();
document.addEventListener('modal:load', init);

async function checkrename() {
  if (qs('#name').value.trim() === '') {
    return false;
  }
  const response = await post('game?page=overview&mode=rename', { name: qs('#name').value }, 'json');
  window.Dialog.alert(response.message, () => {
    if (!response.error) location.reload();
  });
}

async function checkdelete() {
  const password = qs('#password').value;
  if (password === '') {
    return false;
  }
  const response = await post('game?page=overview', { mode: 'delete', password }, 'json');
  window.Dialog.alert(response.message, () => {
    if (response.ok) location.href = `game?page=overview&cp=${pageData('overview-actions-data').mainPlanet}`;
  });
}

registerActions({ 'rename-planet': checkrename, 'delete-planet': checkdelete });
