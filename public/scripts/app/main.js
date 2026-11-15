/**
 * Entry module of the game UI. Loads the native core (no jQuery), starts the page-wide widgets and
 * runs the per-page init code that templates register with window.onPageInit(fn).
 *
 * Page scripts are ES modules too (loaded after this one, in document order) and import what they
 * need from ./core.js, ./dialog.js, ./ui.js, ...; functions used by inline on*="" handlers are put
 * on window by the page module.
 */
import './core.js';
import './dialog.js';
import { initTooltips } from './tooltip.js';
import { initTimers } from './timers.js';
import './ticker.js';
import { initUi } from './ui.js';

const queue = window.__pageInit ?? [];

/** run fn once the DOM is ready and all modules are loaded */
window.onPageInit = (fn) => {
  if (queue.started) {
    fn();
  } else {
    queue.push(fn);
  }
};

// DOMContentLoaded fires after all deferred page modules ran (ready() would fire too early here,
// the document is already "interactive" while modules execute)
const boot = () => {
  initTooltips();
  initTimers();
  initUi();
  queue.started = true;
  for (const fn of queue.splice(0)) {
    try {
      fn();
    } catch (error) {
      console.error(error);
    }
  }
};

if (document.readyState === 'complete') {
  boot();
} else {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
}
