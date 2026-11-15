/**
 * Team editor: add, delete and reorder rows of #team-form. Row markup comes from
 * <template id="team-row-template">; buttons are marked data-team-add / -up / -down / -delete, the
 * counter data-team-count and the empty hint data-team-empty. Changes are announced as an "input"
 * event so form[data-track-dirty] notices them.
 */
const form = document.getElementById('team-form');
const body = form.querySelector('[data-team-rows]');
const empty = form.querySelector('[data-team-empty]');
const count = form.querySelector('[data-team-count]');
const template = document.getElementById('team-row-template');

const rows = () => body.querySelectorAll('[data-team-row]');

function refresh() {
  count.textContent = rows().length;
  empty.hidden = rows().length > 0;
  form.dispatchEvent(new Event('input', { bubbles: true }));
}

form.querySelector('[data-team-add]').addEventListener('click', () => {
  body.appendChild(template.content.cloneNode(true));
  [...rows()].at(-1).querySelector('input[type=text]').focus();
  refresh();
});

body.addEventListener('click', (event) => {
  const button = event.target.closest('button');
  const row = button?.closest('[data-team-row]');
  if (!row) return;
  if (button.hasAttribute('data-team-delete')) {
    row.remove();
  } else if (button.hasAttribute('data-team-up') && row.previousElementSibling) {
    body.insertBefore(row, row.previousElementSibling);
  } else if (button.hasAttribute('data-team-down') && row.nextElementSibling) {
    body.insertBefore(row.nextElementSibling, row);
  } else {
    return;
  }
  refresh();
});
