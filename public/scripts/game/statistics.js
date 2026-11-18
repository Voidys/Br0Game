/**
 * Statistics page: changing "show" or "per" jumps back to the page with the player's own rank (the range select
 * is left out of the request), only the range select itself keeps an explicit range.
 */
for (const id of ['who', 'type']) {
  document.getElementById(id)?.addEventListener('change', () => {
    const range = document.getElementById('range');
    if (range) range.disabled = true;
  });
}
