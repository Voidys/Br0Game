import { qs, qsa, on, post } from '../app/core.js';

/**
 * Shipyard hangar filter bar (#hangar-filters): group filters, filters pinned per ship and a "+" menu to pin more. The
 * selected filters decide which ship cards (.infos) are visible ("All" or no selection shows everything). Adding or removing a
 * pinned ship saves the list on the server (POST to the route in #hangar-filters data-save-url, errors ignored).
 */
window.onPageInit(() => {
  const bar = qs('#hangar-filters');
  if (!bar) {
    return;
  }
  const menu = qs('#hangar-filter-menu');

  const filterButtons = () => qsa('.hangar-group-filter, .hangar-ship-filter', bar);

  /**
   * Show the union of every selected group and pinned-ship filter.
   * "All" or an empty selection shows every ship card.
   */
  function applyFilters() {
    const selected = filterButtons().filter((button) => button.classList.contains('selected'));
    const cards = qsa('.infos');
    if (selected.some((button) => button.id === 'ship3') || selected.length === 0) {
      cards.forEach((card) => { card.style.display = ''; });
      return;
    }

    cards.forEach((card) => { card.style.display = 'none'; });
    for (const button of selected) {
      const selector = button.getAttribute('data-filter-selector');
      if (selector) {
        qsa(selector).forEach((card) => { card.style.display = ''; });
        continue;
      }

      const shipId = button.dataset.shipId;
      if (shipId) {
        const card = qs(`#s${shipId}`);
        if (card) card.style.display = '';
      }
    }
  }

  /** Selects only the "All" filter and shows every card. */
  function selectAllOrNone() {
    filterButtons().forEach((button) => button.classList.remove('selected'));
    qs('#ship3', bar)?.classList.add('selected');
    applyFilters();
  }

  /** Click on a filter: "All" is exclusive; any other toggles and deselects "All"; an empty selection falls back to "All". */
  function toggleFilter(button) {
    if (button.id === 'ship3') {
      filterButtons().forEach((other) => other.classList.remove('selected'));
      button.classList.add('selected');
      applyFilters();
      return;
    }

    qs('#ship3', bar)?.classList.remove('selected');
    button.classList.toggle('selected');
    if (!filterButtons().some((other) => other.classList.contains('selected'))) {
      selectAllOrNone();
      return;
    }

    applyFilters();
  }

  /** Ship ids of the pinned filters in bar order. */
  function currentShipIds() {
    return qsa('.hangar-ship-filter', bar).map((button) => parseInt(button.getAttribute('data-ship-id'), 10));
  }

  /** Persists the pinned ship ids (fire and forget). */
  function saveShips(ids) {
    post(bar.dataset.saveUrl, { 'ships[]': ids }).catch(() => {});
  }

  /** Position of a ship in the hangar plus-menu (fleet list order). */
  function optionSortIndex(shipId) {
    let order = null;
    try {
      order = JSON.parse(menu.dataset.shipOrder);
    } catch (error) {
      order = null;
    }
    const id = parseInt(shipId, 10);
    if (Array.isArray(order)) {
      let index = order.indexOf(id);
      if (index === -1) {
        index = order.indexOf(String(id));
      }
      return index === -1 ? Number.MAX_SAFE_INTEGER : index;
    }

    return id;
  }

  /** Put a removed filter back into the plus-menu at its original fleet position. */
  function insertMenuOption(shipId, label) {
    const option = document.createElement('button');
    option.type = 'button';
    option.className = 'hangar-filter-option';
    option.setAttribute('data-ship-id', shipId);
    option.textContent = label;
    const newIndex = optionSortIndex(shipId);
    const before = qsa('.hangar-filter-option', menu)
      .find((other) => optionSortIndex(other.getAttribute('data-ship-id')) > newIndex);

    if (before) {
      before.before(option);
    } else {
      menu.append(option);
    }
  }

  on(bar, 'click', '.hangar-group-filter, .hangar-ship-filter', (event, button) => {
    if (event.target.closest('.hangar-filter-remove')) {
      return;
    }
    toggleFilter(button);
  });

  on(bar, 'click', '.hangar-filter-remove', (event, remove) => {
    event.preventDefault();
    event.stopPropagation();

    const shipId = remove.dataset.shipId;
    const button = qs(`.hangar-ship-filter[data-ship-id="${shipId}"]`, bar);
    if (!button) return;
    const wasSelected = button.classList.contains('selected');
    // label text without the nested remove span
    const label = Array.from(button.childNodes)
      .filter((node) => node.nodeType === Node.TEXT_NODE)
      .map((node) => node.textContent)
      .join('')
      .trim();

    const previous = button.previousElementSibling;
    if (previous?.classList.contains('hangar-filter-sep')) {
      previous.remove();
    }
    button.remove();
    if (!Array.from(bar.children).some((child) => child.tagName === 'BUTTON')) {
      const prevSep = qs('.hangar-filter-add-wrap', bar)?.previousElementSibling;
      if (prevSep?.classList.contains('hangar-filter-sep')) prevSep.remove();
    }
    insertMenuOption(shipId, label);

    if (wasSelected && !filterButtons().some((other) => other.classList.contains('selected'))) {
      selectAllOrNone();
    } else {
      applyFilters();
    }

    saveShips(currentShipIds());
  });

  qs('#hangar-filter-add')?.addEventListener('click', (event) => {
    event.stopPropagation();
    menu.hidden = !menu.hidden;
  });

  menu.addEventListener('click', (event) => {
    event.stopPropagation();
  });

  on(menu, 'click', '.hangar-filter-option', (event, option) => {
    event.preventDefault();

    const shipId = option.dataset.shipId;
    const label = option.textContent;
    const addWrap = qs('.hangar-filter-add-wrap', bar);
    let trailingSep = addWrap.previousElementSibling;
    if (!trailingSep?.classList.contains('hangar-filter-sep')) trailingSep = null;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'hangar-ship-filter';
    button.id = `ship-${shipId}`;
    button.setAttribute('data-ship-id', shipId);
    button.append(`${label} `);
    const remove = document.createElement('span');
    remove.className = 'hangar-filter-remove';
    remove.setAttribute('data-ship-id', shipId);
    remove.textContent = '×';
    button.append(remove);

    const makeSep = () => {
      const sep = document.createElement('span');
      sep.className = 'hangar-filter-sep';
      sep.textContent = ' | ';
      return sep;
    };

    if (trailingSep) {
      trailingSep.before(makeSep());
      trailingSep.before(button);
    } else {
      addWrap.before(button);
      addWrap.before(makeSep());
    }

    option.remove();
    menu.hidden = true;
    saveShips(currentShipIds());
  });

  document.addEventListener('click', () => {
    menu.hidden = true;
  });
});
