/**
 * Settings page: the "reset" button next to each colour picker puts the factory colour
 * (the input's basecolor attribute) back. Collapsible sections are the built-in "toggle-rows" action.
 */
import { registerActions } from '../app/actions.js';

registerActions({
  'reset-color': (button) => {
    const input = button.parentNode.querySelector('input');
    input.value = input.getAttribute('basecolor');
  },
});

/** Theme (CSS) select: a small preview of the chosen theme next to it (preview.png of the theme folder; hidden when there is none). */
window.onPageInit(() => {
  const select = document.getElementById('cssTheme');
  const preview = document.getElementById('theme-preview');
  if (!select || !preview) return;
  const show = () => { preview.hidden = false; };
  preview.addEventListener('load', show);
  preview.addEventListener('error', () => { preview.hidden = true; });
  select.addEventListener('change', () => {
    preview.hidden = true;
    preview.src = `${preview.dataset.base}${encodeURIComponent(select.value)}/preview.png`;
  });
  if (preview.complete && preview.naturalWidth > 0) show();
});

/**
 * Unsaved changes: the sticky save bar shows the number of changed fields once a field differs from its
 * loaded value; leaving the page while the form is dirty asks for confirmation (not when the form is submitted).
 * Markup: #settings-form with a .settings-save > [data-dirty-bar] child (data-one / data-many = texts, many with %d) holding [data-dirty-count].
 */
window.onPageInit(() => {
  const form = document.getElementById('settings-form');
  const bar = form?.querySelector('[data-dirty-bar]');
  if (!form || !bar) return;
  const count = bar.querySelector('[data-dirty-count]');
  const current = (field) => (field.type === 'checkbox' || field.type === 'radio' ? String(field.checked) : field.value);
  const initial = new Map();
  for (const field of form.elements) {
    if (field.name && field.type !== 'submit' && field.type !== 'button' && field.type !== 'hidden') initial.set(field, current(field));
  }
  let dirty = 0;
  let submitting = false;
  const update = () => {
    const names = new Set();
    for (const [field, value] of initial) {
      if (current(field) !== value) names.add(field.type === 'radio' ? field.name : field);
    }
    dirty = names.size;
    bar.hidden = dirty === 0;
    bar.parentElement.classList.toggle('is-dirty', dirty > 0);
    count.textContent = dirty === 1 ? bar.dataset.one : bar.dataset.many.replace('%d', dirty);
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.addEventListener('click', () => setTimeout(update));
  form.addEventListener('submit', () => { submitting = true; });
  window.addEventListener('beforeunload', (event) => {
    if (dirty > 0 && !submitting) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
});
