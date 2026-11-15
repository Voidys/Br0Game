/**
 * Live resource counters of the top bar (formerly topnav.js). resourceTicker() is called per resource
 * by the layout; elements are matched by data-ticker="current_<name>" (or the legacy #id).
 */
import { qsa, now, pretty_number, shortly_number, setVisibleInterval } from './core.js';

function updateTooltip(element, amount, full) {
  const content = element.getAttribute('data-tooltip-content') ?? '';
  if (!content.startsWith('<')) {
    if (amount !== null) element.setAttribute('data-tooltip-content', pretty_number(amount));
    return;
  }
  const holder = document.createElement('div');
  holder.innerHTML = content;
  const current = holder.querySelector('.res_current');
  if (current) {
    if (amount !== null) current.textContent = pretty_number(amount);
    current.classList.toggle('res_current_warn', element.classList.contains('res_current_warn'));
    current.classList.toggle('colorNegative', full);
  }
  element.setAttribute('data-tooltip-content', holder.innerHTML);
}

export function resourceTicker(config, init) {
  if (init === true) {
    setVisibleInterval(() => resourceTicker(config), 1000);
  }

  let elements = qsa(`[data-ticker="${config.valueElem}"]`);
  if (!elements.length) {
    elements = qsa(`#${CSS.escape(config.valueElem)}`);
  }

  const seconds = (now().getTime() - (window.startTime ?? now().getTime())) / 1000;
  const amount = Math.max(0, Math.floor(parseFloat(config.available) + parseFloat(config.production) / 3600 * seconds));
  const limit = config.limit[1];

  for (const element of elements) {
    if (element.classList.contains('res_current_max')) continue;
    if (amount < limit) {
      if (amount >= limit * 0.9) element.classList.add('res_current_warn');
      if (window.viewShortlyNumber || element.classList.contains('tooltip')) {
        updateTooltip(element, amount, false);
        element.innerHTML = shortly_number(amount);
      } else {
        element.innerHTML = pretty_number(amount);
      }
    } else {
      element.classList.add('colorNegative', 'res_current_max');
      updateTooltip(element, null, true);
    }
  }
}

/** current amount of a resource as rendered (data-real of #current_<name>) */
export function getRessource(name) {
  return parseInt(document.getElementById(`current_${name}`)?.dataset.real ?? '0', 10);
}

window.resourceTicker = resourceTicker;
window.getRessource = getRessource;
