/** rename / delete planet (overview actions modal, Dialog.PlanetAction); tabs: inline in the template */
import { qs, getJSON, post } from '../app/core.js';

async function checkrename() {
  if (qs('#name').value.trim() === '') {
    return false;
  }
  const response = await getJSON(`game?page=overview&mode=rename&name=${encodeURIComponent(qs('#name').value)}`);
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
    if (response.ok) location.href = `game?page=overview&cp=${window.mainPlanet}`;
  });
}

Object.assign(window, { checkrename, checkdelete });
