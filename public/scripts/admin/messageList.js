import { qs, on } from '../app/core.js';
import { autocomplete } from '../app/ui.js';

function gotoPage(page) {
  qs('#side').value = page;
  qs('form').submit();
}

const source = async (term) => {
  const response = await fetch(`admin?page=autoComplete&term=${encodeURIComponent(term)}`, { credentials: 'same-origin' });
  return response.json();
};

for (const id of ['sender', 'receiver']) {
  const input = document.getElementById(id);
  if (input) autocomplete(input, { source });
}

on(document, 'click', '.toggle', (event, link) => {
  event.preventDefault();
  const content = document.getElementById(`contentID${link.closest('tr').dataset.messageid}`);
  if (content) content.style.display = getComputedStyle(content).display === 'none' ? '' : 'none';
});

Object.assign(window, { gotoPage });
