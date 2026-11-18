import { qs, qsa, now, pad, pretty_number, shortly_number, getRestTimeFormat, setVisibleInterval, NotifyBox, pageData } from '../app/core.js';
import { Dialog } from '../app/dialog.js';
import { registerActions } from '../app/actions.js';
import { env } from '../app/env.js';
import { noShips } from './flotten.js';

/**
 * Fleet table page: running fleets' timers, experience / cargo sums of the chosen ships, the quick cargo
 * selection and the custom fleets saved in localStorage. Ship data (experience points, capacity per
 * type) comes from the data island #fleet-table-data; controls are data-action markup (app/actions.js).
 */
const expValues = {};
const shipStorage = {};
for (const ship of pageData('fleet-table-data').ships ?? []) {
  expValues[ship.id] = ship.expo;
  shipStorage[ship.id] = ship.capacity;
}

const byId = (id) => document.getElementById(id);
const field = (name) => document.getElementsByName(name)[0];
/** the amount field of a ship type in the selection table */
const shipInput = (id) => byId(`ship${id}_input`);
/** the amount field of a ship type in the custom fleet editor */
const editorInput = (id) => byId(`ship_${id}`);

let customFleets = {};

const storedFleets = () => JSON.parse(localStorage.getItem('custom_fleets') ?? '{}');
const storeFleets = () => localStorage.setItem('custom_fleets', JSON.stringify(customFleets));

/** "H:MM:SS" of a date, prefixed with "nT " for a plain day count above zero. */
const clock = (date, dayDiff = 0) => {
  const time = `${pad(date.getHours(), 2)}:${pad(date.getMinutes(), 2)}:${pad(date.getSeconds(), 2)}`;
  return dayDiff > 0 ? `${dayDiff}T ${time}` : time;
};

/** Refreshes the remaining flight times (`.fleets`) and the "abort" times (`.aborttime`: time until the fleet could be recalled, shown in the player's timezone with a day prefix). */
const refreshTimers = () => {
  qsa('.fleets').forEach((el) => {
    const seconds = Number(el.dataset.fleetTime) - (now().getTime() - env.startTime) / 1000;
    el.textContent = seconds <= 0 ? '-' : getRestTimeFormat(seconds);
  });

  for (const fleet of qsa('.aborttime')) {
    const current = new Date(Date.now());
    const serverBase = new Date(current.toLocaleString('en-us', { timeZone: env.serverTimezone })).getTime();
    const localBase = new Date(current.toLocaleString('en-us', { timeZone: env.localTimezone })).getTime();
    const timeOffset = localBase - serverBase;
    const cdate = new Date(now().getTime() + timeOffset);
    const startdate = new Date(new Date(1000 * parseInt(fleet.getAttribute('starttime'))).toLocaleString('en-US', { timeZone: env.localTimezone }));
    cdate.setMilliseconds(cdate.getMilliseconds() + (cdate - startdate));
    const dayDiff = Math.trunc((cdate - now()) / (1000 * 60 * 60 * 24));
    fleet.innerText = clock(cdate, dayDiff);
    // the recall question names the return time of a recall right now (the clock above)
    const recall = fleet.closest('td')?.querySelector('input[data-confirm-tpl]');
    if (recall) {
      recall.dataset.confirm = recall.dataset.confirmTpl.replace('%s', fleet.innerText);
    }
  }
};

/** Shows the experience points ("Exp: n / cap") and the total cargo space of the ships typed into the amount fields. */
const showValues = () => {
  let fpoints = 0;
  let tpoints = 0;
  for (const id in expValues) {
    const input = shipInput(id);
    if (input && input.value !== '') {
      fpoints += expValues[id] * parseInt(input.value);
      tpoints += shipStorage[id] * parseInt(input.value);
    }
  }
  const expoCount = byId('expocount');
  expoCount.innerHTML = `Exp: ${fpoints} / ${expoCount.getAttribute('expocap')} <svg class="icon" aria-hidden="true"><use href="./styles/resource/icons/lucide.svg#i-info"></use></svg>`;
  const cargo = byId('cargospace');
  cargo.innerText = `${cargo.getAttribute('data')}:${pretty_number(tpoints)}`;
};
document.addEventListener('fleet:amounts', showValues);

/** Number of large (25000 capacity) and small (5000 capacity) cargos needed for the typed resources; rounds up and adds one more when the remainder is above 80 % / 50 % of a ship. Base capacities, techs are not applied. */
const updateButtons = () => {
  const names = ['met_storage', 'krist_storage', 'deut_storage'];
  const typed = names.map((name) => parseInt(field(name).value.replace(/\D/g, ''), 10));
  // nothing typed: the buttons size the fleet for the whole stock (the placeholders); the form itself then sends 0
  const amounts = typed.every(Number.isNaN) ? names.map((name) => Number(field(name).dataset.stock) || 0) : typed;
  const total = amounts.reduce((sum, amount) => sum + (Number.isNaN(amount) ? 0 : amount), 0);
  let gts = Math.ceil(total / 25000);
  let kts = Math.ceil(total / 5000);
  if (total / 25000 - gts > 0.8) {
    gts += 1;
  }
  if (total / 5000 - kts > 0.5) {
    kts += 1;
  }
  byId('kt_amt').innerText = ` ( ${pretty_number(kts)} ) `;
  byId('gt_amt').innerText = ` ( ${pretty_number(gts)} ) `;
};

/** Click on the large or small cargo button: clears all ships and enters the computed number of that cargo type. */
const setAmount = (button) => {
  noShips();
  if (button.id === 'gt_select') {
    const input = shipInput(203);
    if (input !== null) {
      input.value = byId('gt_amt').innerText.replace(/\D/g, '');
    }
  } else {
    const input = shipInput(202);
    if (input) {
      input.value = byId('kt_amt').innerText.replace(/\D/g, '');
    }
  }
  showValues();
};

/** Shows the planet's current stock (short number) as placeholder of the "carry resources" fields (empty = 0), hooks them to updateButtons() and the small/large cargo buttons to setAmount(). */
const initStorage = () => {
  for (const [name, resource] of [['met_storage', 'metal'], ['krist_storage', 'crystal'], ['deut_storage', 'deuterium']]) {
    field(name).addEventListener('input', updateButtons);
    const stock = parseInt(byId(`current_${resource}`).getAttribute('data-real'), 10) || 0;
    field(name).dataset.stock = stock;
    field(name).placeholder = String(shortly_number(stock, true)).replace(/&nbsp;/g, ' ');
  }
  updateButtons();
  for (const id of ['gt_select', 'kt_select']) {
    byId(id).addEventListener('click', (event) => setAmount(event.currentTarget));
  }
};

/** Shows or hides the custom fleet table (arrow indicator). */
const toggleCustomFleet = () => {
  const table = byId('customfleet');
  const hidden = table.style.display === 'none';
  table.style.display = hidden ? 'table' : 'none';
  byId('c_fleet_span').innerText = hidden ? '▲' : '▼';
};

// ---------------------------------------------------------------------------------------- custom fleets

/** Experience and cargo points of a custom fleet ({shipId: amount}) with the per-ship `points` table. */
const fleetPoints = (fleet, points) => Object.entries(fleet).reduce((sum, [id, amount]) => (points[id] === null ? sum : sum + points[id] * parseInt(amount)), 0);

/** Experience and cargo sums of the custom fleet editor. */
const showExpoPoints = () => {
  const fleet = {};
  for (const id in expValues) {
    if (editorInput(id) !== null) {
      fleet[id] = editorInput(id).value;
    }
  }
  byId('ship_expo_points').innerText = pretty_number(fleetPoints(fleet, expValues));
  byId('ship_cargo_points').innerText = pretty_number(fleetPoints(fleet, shipStorage));
};

/** Loads a custom fleet into the amount fields of the editor (an empty name clears them) and updates its sums. */
const showFleet = (name) => {
  byId('cfleet_name').value = name;
  const fleet = customFleets[name] || {};
  for (const id in fleet) {
    const input = editorInput(id);
    if (input !== null) {
      input.value = fleet[id];
    }
  }
  if (name === '') {
    for (const id in expValues) {
      if (editorInput(id) !== null) {
        editorInput(id).value = 0;
      }
    }
  }
  showExpoPoints();
};

/** Appends an option for a custom fleet to the selector. */
const addFleetOption = (name) => {
  const option = document.createElement('option');
  option.value = name;
  option.innerText = name;
  byId('cfleet_select').appendChild(option);
};

/** The mission of the last dispatch ({ships, target, mission, label}), remembered by the dispatch page (flotten.js); null when there is none. */
const lastFleet = () => {
  try {
    const last = JSON.parse(localStorage.getItem('last_fleet') ?? 'null');
    return last && last.ships && Object.keys(last.ships).length ? last : null;
  } catch (error) {
    return null;
  }
};

const chip = (label, action, data = {}) => {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'chip';
  button.dataset.action = action;
  Object.assign(button.dataset, data);
  button.textContent = label;
  return button;
};

/** Rebuilds the quick fleets: the last mission and one chip per stored custom fleet; the hint shows while there are none. */
const showFleetLinks = () => {
  const list = byId('customfleets');
  list.replaceChildren();
  const last = lastFleet();
  if (last) {
    const total = Object.values(last.ships).reduce((sum, amount) => sum + Number(amount), 0);
    const [galaxy, system, planet] = last.target;
    const button = chip(`${last.label} [${galaxy}:${system}:${planet}] · ${pretty_number(total)}`, 'last-fleet');
    button.classList.add('chip--last');
    list.append(button);
  }
  for (const name of Object.keys(storedFleets())) {
    list.append(chip(name, 'custom-fleet', { name }));
  }
  byId('customfleets-hint').hidden = list.childElementCount > 0;
};

/** Saves the current ship amounts under the typed name as a custom fleet in localStorage (`custom_fleets`); an empty name is refused. */
const saveFleet = () => {
  const amounts = {};
  for (const id in expValues) {
    if (editorInput(id) !== null) {
      amounts[id] = editorInput(id).value;
    }
  }
  const name = byId('cfleet_name').value;
  const table = byId('customfleet');
  if (name.trim() === '') {
    Dialog.alert(table.getAttribute('data-noname'));
    return;
  }
  if (!(name in customFleets)) {
    addFleetOption(name);
  }
  byId('cfleet_select').value = name;
  customFleets[name] = amounts;
  storeFleets();
  showFleetLinks();
  NotifyBox(table.getAttribute('data-saved') || 'OK');
};

/** Deletes the selected custom fleet after confirmation. */
const removeFleet = async () => {
  const name = byId('cfleet_select').value;
  const confirmed = await Dialog.confirm(byId('customfleet').getAttribute('data-delconf').replace('%s', name), { danger: true });

  if (confirmed === false) {
    return;
  }

  byId('cfleet_name').value = '';
  const option = qs(`#cfleet_select option[value='${name}']`);
  if (option) {
    option.remove();
    delete customFleets[name];
    showFleet(byId('cfleet_select').value);
  }

  storeFleets();
  showFleetLinks();
};

/** Loads the stored custom fleets (localStorage), fills the selector and connects the editor controls. */
const initCustomFleets = () => {
  customFleets = storedFleets();
  for (const name in customFleets) {
    showFleet(name);
    break;
  }
  Object.keys(customFleets).forEach(addFleetOption);
  if (Object.keys(customFleets).length > 0 && byId('customfleet').style.display === 'none') {
    toggleCustomFleet();
  }

  byId('cfleet_select').addEventListener('change', () => showFleet(byId('cfleet_select').value));
  byId('cf_save').addEventListener('click', saveFleet);
  byId('cf_del').addEventListener('click', removeFleet);
  for (const id in expValues) {
    editorInput(id)?.addEventListener('input', showExpoPoints);
  }
  showExpoPoints();
};

/** Quick link "custom fleet": clears the ships and enters the stored amounts of that fleet. */
const applyCustomFleet = (name) => {
  noShips();
  const fleet = storedFleets()[name];
  for (const id in fleet) {
    const input = shipInput(id);
    if (input) {
      input.value = fleet[id];
    }
  }
  showValues();
};

/** Quick chip "last mission": the ships (as far as the planet still has them), the target and the mission of the last dispatch go into the form. */
const applyLastFleet = () => {
  const last = lastFleet();
  if (!last) {
    return;
  }
  noShips();
  for (const [id, amount] of Object.entries(last.ships)) {
    const input = shipInput(id);
    if (input) {
      input.value = Math.min(Number(amount), Number(byId(`ship${id}_value`)?.dataset.count ?? 0));
    }
  }
  const form = byId('fleet-form');
  const [galaxy, system, planet, type] = last.target;
  Object.entries({ galaxy, system, planet, type, target_mission: last.mission }).forEach(([name, value]) => {
    form.elements[name].value = value;
  });
  showValues();
};

/** Continue without a ship: shows the reason above the table and focuses the first amount field (returns false to stop the submit). */
const validateFleet = () => {
  const form = byId('fleet-form');
  const chosen = Array.from(form.querySelectorAll('input[name^="ship"]')).some((input) => Number(input.value) > 0);
  if (chosen) {
    return true;
  }
  let notice = byId('fleet-notice');
  if (!notice) {
    notice = document.createElement('div');
    notice.id = 'fleet-notice';
    notice.className = 'globalWarning';
    notice.setAttribute('role', 'alert');
    notice.append(document.createElement('span'));
    document.querySelector('.content').prepend(notice);
  }
  notice.querySelector('span').textContent = form.dataset.emptyMsg;
  notice.scrollIntoView({ block: 'nearest' });
  form.querySelector('input[name^="ship"]')?.focus();
  return false;
};

/** Sorts the ship rows by name, speed, cargo capacity or available amount (a second click on the same column reverses). */
const sortShips = (button) => {
  const key = button.dataset.key;
  const body = byId('ship-rows');
  const head = button.closest('td');
  const descending = head.getAttribute('aria-sort') === 'ascending';
  const value = (row) => (key === 'name' ? row.dataset.name : Number(row.dataset[key]));
  const rows = Array.from(body.querySelectorAll('.ship-row')).sort((a, b) => {
    const result = key === 'name' ? value(a).localeCompare(value(b)) : value(a) - value(b);
    return descending ? -result : result;
  });
  body.append(...rows);
  body.querySelectorAll('.fleet-select__head > td').forEach((cell) => cell.setAttribute('aria-sort', 'none'));
  head.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
};

/** Empties the three "resources to take along" fields. */
const clearResources = () => {
  ['met_storage', 'krist_storage', 'deut_storage'].forEach((name) => { field(name).value = ''; });
  updateButtons();
};

/** Fills ship amounts from the URL hash (`#ship_input[<id>]=<amount>`), e.g. from a link in a report or the simulator. */
const fillShips = () => {
  for (const { groups } of document.location.hash.matchAll(/ship_input\[(?<shiptype>[0-9]+)\]=(?<shipamount>[0-9]+)/g)) {
    const input = qs(`input#ship${groups.shiptype}_input`);
    if (input !== null) {
      input.value = groups.shipamount;
    }
  }
  showValues();
};

window.onPageInit(() => {
  setVisibleInterval(refreshTimers, 500);
});

window.onPageInit(() => {
  showValues();
  for (const id in expValues) {
    shipInput(id)?.addEventListener('input', () => setTimeout(showValues, 100));
  }
  initStorage();
  updateButtons();
  initCustomFleets();
  showFleetLinks();
  fillShips();
});

registerActions({
  'toggle-custom-fleet': () => toggleCustomFleet(),
  'custom-fleet': (link) => applyCustomFleet(link.dataset.name),
  'last-fleet': () => applyLastFleet(),
  'fleet-validate': () => validateFleet(),
  'sort-ships': (button) => sortShips(button),
  'clear-resources': () => clearResources(),
});
