import { qs, qsa, on, post, serialize, pageData } from '../app/core.js';
import { registerActions } from '../app/actions.js';
import { Dialog } from '../app/dialog.js';
import { tabs } from '../app/ui.js';
import LZString from '../base/battlesim/lz-string.js';

/**
 * Battle simulator: submit helpers (more slots, swap sides, simulate), the slot tabs and the share string
 * (compressed JSON of the inputs). The texts come from the data island #sim-data; the buttons are
 * data-action="sim-*" markup (app/actions.js).
 */
const BS_LNG = pageData('sim-data');

const byId = (id) => document.getElementById(id);

/** Submits the simulator form (POST) to another action URL; returns true. */
const submitTo = (action) => {
  const form = byId('form');
  form.setAttribute('action', action);
  form.setAttribute('method', 'POST');
  form.submit();
  return true;
};

/** Reloads the form with one more attacker slot (route `game_battle_simulator_more_slots`). */
const add = () => submitTo(BS_LNG.urls.moreSlots);

/** Swaps attackers and defenders (route `game_battle_simulator_switch`). */
const switchSides = () => submitTo(BS_LNG.urls.switchSides);

/**
 * Runs the simulation: posts the form to the `send` route, opens the returned report id in the modal (report/<id>) or
 * shows a text answer (error) as alert. The button stays disabled for 2 s afterwards because the server allows one
 * simulation every few seconds. Returns false if a simulation is still pending.
 */
const check = () => {
  const button = byId('sim-submit');
  if (button.disabled) return false;
  const label = qs('span', button);
  const idle = label.textContent;
  button.disabled = true;
  button.classList.add('is-busy');
  label.textContent = button.dataset.busyLabel || idle;
  // the simulated report opens in the page's modal (formerly a full screen popup window)
  post(BS_LNG.urls.simulate, serialize(byId('form'))).then((answer) => {
    try {
      const report = JSON.parse(answer);
      Dialog.open(`report/${encodeURIComponent(report)}`, 1100);
    } catch (error) {
      Dialog.alert(answer);
    }
  }).finally(() => {
    // the server allows one simulation every few seconds
    setTimeout(() => {
      button.disabled = false;
      button.classList.remove('is-busy');
      label.textContent = idle;
    }, 2000);
  });
  return true;
};

/** The tab strip is built by the shared tabs widget (data-tabs); rebuilt after slots were added or removed (old click handlers are dropped by cloning the links). */
const rebuildTabs = () => {
  const container = byId('tabs');
  // drop the old click handlers by replacing the links
  qsa(':scope > ul a', container).forEach((link) => link.replaceWith(link.cloneNode(true)));
  delete container.dataset.tabsReady;
  tabs(container);
};

/** "Clear" button in a section header: sets the number fields of its column in this section to 0. */
on(document, 'click', '.sim-clear', (event, button) => {
  event.preventDefault();
  const cell = button.closest('th');
  const index = Array.prototype.indexOf.call(cell.parentElement.children, cell);
  let row = cell.parentElement.nextElementSibling;
  while (row) {
    const target = row.children[index];
    if (target) {
      qsa('input[type="number"]', target).forEach((input) => { input.value = 0; });
    }
    row = row.nextElementSibling;
  }
});

/** Copies via a temporary textarea and execCommand; shows the text in a prompt when even that fails. */
const fallbackCopy = (text) => {
  const el = document.createElement('textarea');
  el.value = text;
  el.setAttribute('readonly', '');
  el.style.position = 'absolute';
  el.style.left = '-9999px';
  document.body.appendChild(el);
  el.select();
  try {
    document.execCommand('copy');
    Dialog.alert(BS_LNG.shareCopied);
  } catch (error) {
    Dialog.prompt(BS_LNG.shareCopyPrompt, { value: text });
  }
  document.body.removeChild(el);
};

/**
 * Copies the current inputs of all slots as a share string: JSON {slot: {side(0 attacker/1 defender): {unitId: count}}},
 * compressed with LZString to Base64. Uses the clipboard API, falling back to a hidden textarea or a prompt.
 */
const generateShareString = () => {
  const slots = parseInt(byId('slots').value, 10) || 1;
  const data = {};
  for (let slot = 0; slot < slots; slot++) {
    data[slot] = { 0: {}, 1: {} };
    qsa(`[name^="battleinput[${slot}]"]`).forEach((input) => {
      const match = input.getAttribute('name').match(/^battleinput\[\d+\]\[(\d)\]\[(\d+)\]$/);
      if (match) {
        const [, side, id] = match;
        data[slot][parseInt(side, 10)][id] = parseFloat(input.value === '' ? 0 : input.value);
      }
    });
  }
  try {
    const encoded = LZString.compressToBase64(JSON.stringify(data));
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(encoded).then(
        () => Dialog.alert(BS_LNG.shareCopied),
        () => fallbackCopy(encoded),
      );
    } else {
      fallbackCopy(encoded);
    }
  } catch (error) {
    Dialog.alert(BS_LNG.shareError);
  }
};

/**
 * Fills the form from a share string (#shareInput): decodes it (invalid input gives an alert), removes extra slot tabs,
 * clones the first slot for every slot the string needs (field names renumbered, defense/resources only in slot 1), rebuilds
 * the tabs, sets the hidden slot count and writes the unit counts into the matching inputs.
 */
const loadShareString = () => {
  const encoded = byId('shareInput').value;
  if (!encoded) {
    Dialog.alert(BS_LNG.shareLoadNoInput);
    return;
  }
  let data;
  try {
    data = JSON.parse(LZString.decompressFromBase64(encoded));
  } catch (error) {
    data = null;
  }
  if (data === null || typeof data !== 'object') {
    Dialog.alert(BS_LNG.shareLoadInvalid);
    return;
  }

  const container = byId('tabs');
  const list = qs(':scope > ul', container);

  // determine required slots
  const slotsNeeded = Math.max(1, ...Object.keys(data).map((key) => parseInt(key, 10) + 1));

  // remove extra tabs first (keep first)
  const currentSlots = qsa(':scope > div', container).length;
  for (let i = currentSlots - 1; i >= 1; i--) {
    list.children[i]?.remove();
    byId(`tabs-${i}`)?.remove();
  }

  // clone template (tabs-0) to create needed slots
  const baseLabel = qs('li a', list).textContent.replace(/\s*\d+\s*$/, '') || 'ACS Slot';
  for (let i = 1; i < slotsNeeded; i++) {
    const slot = byId('tabs-0').cloneNode(true);
    slot.id = `tabs-${i}`;
    qsa('[name]', slot).forEach((control) => {
      control.setAttribute('name', control.getAttribute('name').replace(/battleinput\[0\]/g, `battleinput[${i}]`));
      if (control.tagName === 'INPUT') {
        control.value = 0;
      }
    });
    // defense and defender resources exist in the first slot only
    qsa('[data-first-slot-only]', slot).forEach((part) => part.remove());
    container.append(slot);
    list.insertAdjacentHTML('beforeend', `<li><a href="#tabs-${i}">${baseLabel} ${i + 1}</a></li>`);
  }
  rebuildTabs();

  // update slots hidden value
  byId('slots').value = slotsNeeded;

  // populate inputs
  for (let slot = 0; slot < slotsNeeded; slot++) {
    const slotData = data[slot] || { 0: {}, 1: {} };
    for (const side of [0, 1]) {
      for (const [id, count] of Object.entries(slotData[side] || {})) {
        qsa(`[name="battleinput[${slot}][${side}][${id}]"]`).forEach((control) => { control.value = count; });
      }
    }
  }
};

registerActions({
  'sim-add-slot': () => add(),
  'sim-switch-sides': () => switchSides(),
  'sim-send': () => check(),
  'sim-load-share': () => loadShareString(),
  'sim-generate-share': () => generateShareString(),
});
