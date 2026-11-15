/**
 * Admin area behaviour (native module, no jQuery). The shared UI modules (dialog, tooltips, timers,
 * sortable tables, sidebar drawer) are booted by ../app/main.js; this adds:
 *
 *   [data-menu-filter]      filters the side navigation as you type; "/" focuses it, Enter opens the
 *                           first match, Esc clears it
 *   [data-admin-universe]   switches the emulated universe and reloads the current page with ?uni=
 *   [data-confirm]          asks before following a link / submitting a form (text = attribute value,
 *                           empty = the generic question)
 *   form[data-track-dirty]  marks the form while it has unsaved changes and warns before leaving
 *   [data-table-filter]     input that filters the rows of the table given by its value (a selector)
 *
 * toggleBlock(id) stays for inline handlers of older admin markup.
 */

const confirmText = () => window.confirmText || '';

function menuFilter() {
  const input = document.querySelector('[data-menu-filter]');
  if (!input) return;
  const links = [...document.querySelectorAll('.admin-nav__link')];
  const groups = [...document.querySelectorAll('.admin-nav__group')];
  const empty = document.querySelector('.admin-nav__empty');
  const labels = new Map(links.map((link) => [link, link.querySelector('.admin-nav__label')]));
  const original = new Map(links.map((link) => [link, labels.get(link).textContent]));

  const apply = () => {
    const term = input.value.trim().toLowerCase();
    let visible = 0;
    links.forEach((link) => {
      const text = original.get(link);
      const index = term ? text.toLowerCase().indexOf(term) : -1;
      // an entry also matches by the names of its tabs (e.g. "moon" finds the players entry)
      const match = !term || index !== -1 || (link.dataset.keywords || '').toLowerCase().includes(term);
      link.parentElement.hidden = !match;
      const label = labels.get(link);
      label.textContent = text;
      if (term && index !== -1) {
        const mark = document.createElement('mark');
        mark.textContent = text.slice(index, index + term.length);
        label.replaceChildren(text.slice(0, index), mark, text.slice(index + term.length));
      }
      if (match) visible += 1;
    });
    groups.forEach((group) => { group.hidden = !group.querySelector('li:not([hidden])'); });
    if (empty) empty.hidden = visible > 0;
  };

  input.addEventListener('input', apply);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      const first = links.find((link) => !link.parentElement.hidden);
      if (first) first.click();
    } else if (event.key === 'Escape') {
      input.value = '';
      apply();
      input.blur();
    }
  });
  document.addEventListener('keydown', (event) => {
    const target = event.target;
    const typing = target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      document.body.classList.add('sidebar-open');
      input.focus();
    }
  });
}

function universeSwitch() {
  const select = document.querySelector('[data-admin-universe]');
  if (!select) return;
  select.addEventListener('change', () => {
    const url = new URL(window.location.href);
    url.searchParams.set('uni', select.value);
    window.location.href = url.toString();
  });
}

function confirmations() {
  document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('a[data-confirm], button[data-confirm], input[data-confirm]') : null;
    if (trigger && !window.confirm(trigger.dataset.confirm || confirmText())) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.hasAttribute('data-confirm') && !window.confirm(form.dataset.confirm || confirmText())) {
      event.preventDefault();
    }
  }, true);
}

function dirtyForms() {
  const forms = [...document.querySelectorAll('form[data-track-dirty]')];
  if (!forms.length) return;
  let submitting = false;
  forms.forEach((form) => {
    const mark = () => form.classList.add('form-dirty');
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('submit', () => { submitting = true; });
  });
  window.addEventListener('beforeunload', (event) => {
    if (!submitting && forms.some((form) => form.classList.contains('form-dirty'))) {
      event.preventDefault();
    }
  });
}

function tableFilters() {
  document.querySelectorAll('[data-table-filter]').forEach((input) => {
    const table = document.querySelector(input.dataset.tableFilter);
    if (!table) return;
    const rows = [...table.tBodies].flatMap((body) => [...body.rows]);
    input.addEventListener('input', () => {
      const term = input.value.trim().toLowerCase();
      rows.forEach((row) => { row.hidden = term !== '' && !row.textContent.toLowerCase().includes(term); });
    });
  });
}

/** show/hide the element with the given id (older admin markup) */
function toggleBlock(id) {
  const element = document.getElementById(id);
  if (element) element.style.display = getComputedStyle(element).display === 'none' ? '' : 'none';
  return false;
}

Object.assign(window, { toggleBlock });

const init = () => {
  menuFilter();
  universeSwitch();
  confirmations();
  dirtyForms();
  tableFilters();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
