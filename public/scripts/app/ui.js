/**
 * Small UI widgets and the page-wide behaviours (formerly base.js, jQuery UI and tablesorter):
 * tabs, sortable tables, autocomplete, planet selector, warning banners, list filters,
 * missile destruction, mobile sidebar and the automated-click report.
 */
import { qs, qsa, on, show, hide, toggle, fadeOut, getText, getJSON, post, serialize, pretty_number } from './core.js';

// ---------------------------------------------------------------------------------------- tabs

/**
 * Tabs: a container with a list of anchor links (href="#panel-id") and the panels.
 * Markup of jQuery UI tabs works as is; auto-initialised for [data-tabs].
 */
export function tabs(container, { active = 0, onChange } = {}) {
  if (!container || container.dataset.tabsReady) return;
  container.dataset.tabsReady = '1';
  container.classList.add('tabs');
  const links = qsa(':scope > ul a[href^="#"], :scope > nav a[href^="#"]', container);
  const panels = links.map((link) => document.getElementById(link.hash.slice(1)));
  const select = (index) => {
    links.forEach((link, i) => {
      const selected = i === index;
      link.setAttribute('aria-selected', String(selected));
      link.parentElement.classList.toggle('is-active', selected);
      if (panels[i]) panels[i].hidden = !selected;
    });
    onChange?.(index, panels[index]);
  };
  links.forEach((link, index) => {
    link.setAttribute('role', 'tab');
    link.addEventListener('click', (event) => { event.preventDefault(); select(index); });
  });
  select(Math.min(active, links.length - 1));
}

// ---------------------------------------------------------------------------------------- sortable tables

function cellValue(row, index) {
  const cell = row.cells[index];
  if (!cell) return '';
  const raw = (cell.dataset.sort ?? cell.textContent).trim();
  const numeric = raw.replace(/[.,\s](?=\d{3}\b)/g, '').replace(',', '.');
  return /^-?\d+(\.\d+)?$/.test(numeric) ? parseFloat(numeric) : raw.toLowerCase();
}

/** click a header cell to sort the table body by that column (formerly tablesorter) */
export function sortableTable(table) {
  if (!table || table.dataset.sortableReady) return;
  table.dataset.sortableReady = '1';
  // last header row: tables may carry a title row above the column headings
  const head = table.tHead ? table.tHead.rows[table.tHead.rows.length - 1] : table.rows[0];
  if (!head) return;
  Array.from(head.cells).forEach((th, index) => {
    if (th.classList.contains('no-sort')) return;
    th.classList.add('sortable');
    th.tabIndex = 0;
    const sort = () => {
      const body = table.tBodies[0];
      const rows = Array.from(body.rows).filter((row) => row !== head);
      const ascending = th.getAttribute('aria-sort') !== 'ascending';
      Array.from(head.cells).forEach((cell) => cell.removeAttribute('aria-sort'));
      th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
      rows.sort((a, b) => {
        const x = cellValue(a, index);
        const y = cellValue(b, index);
        const result = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y));
        return ascending ? result : -result;
      });
      body.append(...rows);
    };
    th.addEventListener('click', sort);
    th.addEventListener('keydown', (event) => { if (event.key === 'Enter') sort(); });
  });
}

// ---------------------------------------------------------------------------------------- autocomplete

/**
 * Suggestion list under an input: source(term) resolves to [{label, value, category?}];
 * onSelect(item) runs when one is chosen. Grouped by category like the former catcomplete widget.
 */
export function autocomplete(input, { source, onSelect, minLength = 2, delay = 250 }) {
  if (!input) return;
  const list = document.createElement('ul');
  list.className = 'autocomplete';
  list.hidden = true;
  input.after(list);
  input.setAttribute('autocomplete', 'off');
  let timer;
  let items = [];
  let active = -1;

  const render = () => {
    list.innerHTML = '';
    let category = null;
    items.forEach((item, index) => {
      if (item.category && item.category !== category) {
        category = item.category;
        list.insertAdjacentHTML('beforeend', `<li class="autocomplete__category">${category}</li>`);
      }
      const li = document.createElement('li');
      li.className = 'autocomplete__item' + (index === active ? ' is-active' : '');
      li.textContent = item.label ?? item.value;
      li.addEventListener('mousedown', (event) => { event.preventDefault(); choose(index); });
      list.append(li);
    });
    list.hidden = items.length === 0;
  };
  const choose = (index) => {
    const item = items[index];
    if (!item) return;
    input.value = item.value ?? item.label;
    items = [];
    render();
    onSelect?.(item);
  };

  input.addEventListener('input', () => {
    window.clearTimeout(timer);
    const term = input.value.trim();
    if (term.length < minLength) { items = []; render(); return; }
    timer = window.setTimeout(async () => {
      try { items = await source(term); } catch { items = []; }
      active = -1;
      render();
    }, delay);
  });
  input.addEventListener('keydown', (event) => {
    if (list.hidden) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      active = (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      render();
    } else if (event.key === 'Enter' && active >= 0) {
      event.preventDefault();
      choose(active);
    } else if (event.key === 'Escape') {
      items = [];
      render();
    }
  });
  input.addEventListener('blur', () => window.setTimeout(() => { items = []; render(); }, 150));
}

// ---------------------------------------------------------------------------------------- page behaviours

/** "drop admin access" / "drop rebuild protection" links in the warning banners */
function warningBanners() {
  const drop = (url) => getText(url).then(() => qsa('.globalWarning').forEach((el) => fadeOut(el)));
  on(document, 'click', '#drop-admin', (event) => { event.preventDefault(); drop('admin?page=logout'); });
  on(document, 'click', '#drop-rebuild-protection', (event) => { event.preventDefault(); drop('game?page=rebuildProtectionAjax'); });

  // "x new messages" notice after login: close button (it is shown once per login anyway)
  on(document, 'click', '[data-login-notice-close]', (event, button) => {
    const notice = button.closest('[data-login-notice]');
    notice?.classList.add('is-leaving');
    window.setTimeout(() => notice?.remove(), 200);
  });
}

function planetSelector() {
  on(document, 'change', '#planetSelector, [data-planet-selector]', (event, select) => {
    document.location = `?${window.queryString ?? ''}&cp=${select.value}`;
  });
}

/**
 * List filters of the former header script: #gl1..#gl3 switch planet/moon rows (.planetb / .planetb1),
 * #<n>h / #<n>s hide/show the rows #h<n+1>..#h<n+99> of a section (buildings, fleet, defense, ...).
 */
function listFilters() {
  const planets = () => qsa('.planetb');
  const moons = () => qsa('.planetb1');
  const select = (button) => {
    qsa('#gl1, #gl2, #gl3').forEach((el) => el.classList.toggle('selected', el === button));
  };
  on(document, 'click', '#gl1', (event, button) => { planets().forEach(hide); moons().forEach((el) => show(el)); select(button); });
  on(document, 'click', '#gl2', (event, button) => { moons().forEach((el) => toggle(el)); planets().forEach((el) => show(el)); select(button); });
  on(document, 'click', '#gl3', (event, button) => { planets().forEach((el) => show(el)); moons().forEach((el) => show(el)); select(button); });

  on(document, 'click', '[id$="h"], [id$="s"]', (event, button) => {
    const match = /^(\d+)([hs])$/.exec(button.id);
    if (!match) return;
    const base = parseInt(match[1], 10);
    const hiding = match[2] === 'h';
    for (let i = base + 1; i <= base + 99; i++) {
      const row = document.getElementById(`h${i}`);
      if (row) hiding ? hide(row) : show(row);
    }
    hide(button);
    show(document.getElementById(`${base}${hiding ? 's' : 'h'}`));
  });
}

/** information page of the missile silo: destroy interplanetary/anti-ballistic missiles */
export async function DestroyMissiles() {
  const data = await getJSON(`?page=information&mode=destroyMissiles&${serialize(qsa('.missile'))}`);
  const target502 = document.getElementById('missile_502');
  const target503 = document.getElementById('missile_503');
  if (target502) target502.textContent = pretty_number(data[0]);
  if (target503) target503.textContent = pretty_number(data[1]);
  qsa('.missile').forEach((input) => { input.value = ''; });
}

/** number inputs are cleared on click (players type a fresh amount) */
function clearNumberInputs() {
  on(document, 'click', 'input[type="number"]', (event, input) => { input.value = ''; });
}

/** mobile: the menu button opens the sidebar as a drawer */
function sidebarToggle() {
  on(document, 'click', '[data-sidebar-toggle]', () => document.body.classList.toggle('sidebar-open'));
  on(document, 'click', '.sidebar-backdrop', () => document.body.classList.remove('sidebar-open'));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') document.body.classList.remove('sidebar-open');
  });
}

/** the side panel sticks below the top bar; its height varies (resource row, wrapping), so measure it */
function topbarHeight() {
  const bar = qs('.topbar');
  if (!bar || !('ResizeObserver' in window)) return;
  new ResizeObserver(([entry]) => {
    document.documentElement.style.setProperty('--topbar-h', `${entry.borderBoxSize[0].blockSize}px`);
  }).observe(bar);
}

/**
 * Clicks that were not made by a person (synthetic events before any trusted click) are reported,
 * as before; the game uses this to spot automation.
 */
function automationReport() {
  let humanInteraction = false;
  document.addEventListener('click', (event) => {
    if (event.isTrusted) {
      humanInteraction = true;
    } else if (!humanInteraction && event.target instanceof Element) {
      post('game?page=report', {
        hi: humanInteraction,
        u: document.location.href,
        o: event.target.outerHTML,
        ts: event.timeStamp,
      }).catch(() => {});
    }
  }, true);
}

export function initUi() {
  warningBanners();
  planetSelector();
  listFilters();
  clearNumberInputs();
  sidebarToggle();
  topbarHeight();
  automationReport();
  qsa('[data-tabs]').forEach((el) => tabs(el));
  qsa('table.sortable, table[data-sortable]').forEach(sortableTable);
}

Object.assign(window, { DestroyMissiles });
window.App = Object.assign(window.App ?? {}, { tabs, sortableTable, autocomplete });

export { qs };
