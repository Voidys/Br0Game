/**
 * Admin message list: pager links submit the list form, sender/receiver fields autocomplete user names (GET
 * `admin?page=autoComplete&term=`, JSON), and the "toggle" link shows or hides the message text of its row.
 */
import { qs, on } from '../app/core.js';
import { autocomplete } from '../app/ui.js';

/** pager links (data-goto-page) set the page field and submit the list form */
on(document, 'click', '[data-goto-page]', (event, link) => {
  event.preventDefault();
  qs('#side').value = link.dataset.gotoPage;
  qs('form').submit();
});

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
