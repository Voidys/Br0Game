/**
 * Player card dialog: the two block checkboxes (no trade / no messages) save their state right away.
 * Markup: data-action="player-block" data-id="<player id>" data-url="<block route>".
 */
import { registerActions } from '../app/actions.js';

registerActions({
  'player-block': async (checkbox) => {
    const trade = document.getElementById('blockTrade').checked ? 1 : 0;
    const dm = document.getElementById('blockDm').checked ? 1 : 0;
    const response = await fetch(`${checkbox.dataset.url}?id=${encodeURIComponent(checkbox.dataset.id)}&blockTrade=${trade}&blockDm=${dm}&ajax=1`, {
      method: 'POST',
      credentials: 'same-origin',
    });
    await response.json();
    const loading = document.getElementById('loading');
    if (loading) loading.style.display = '';
  },
});
