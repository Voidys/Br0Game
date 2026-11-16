import { qs, qsa, show, hide, setVisibleInterval, clearVisibleInterval, getRestTimeFormat } from '../app/core.js';
import { registerActions } from '../app/actions.js';

/**
 * Repair dock page: countdown and progress bar of the running repair, "repair all" button. `data-time` of #time is the
 * remaining seconds at render time; in vacation mode (.timer[data-umode=1]) the time is frozen. When the repair is done the
 * "ready" text, the deploy button (#deployRepaired) are shown and the progress bar is hidden.
 */
let resttime = 0;
let time = 0;
let interval = 0;
let umode = false;
let startValue = 0;

/** Fills every amount field with its maximum (data-max). */
function repairAll() {
  for (const field of qsa('.numfield')) {
    field.value = field.dataset.max;
  }
}

/** Sets the progress bar between its start value and 100 from the remaining seconds. */
function setProgress(rest) {
  const bar = qs('#progressbar');
  if (!bar || time <= 0) return;
  const done = resttime > 0 ? Math.min(Math.max(1 - rest / resttime, 0), 1) : 1;
  bar.value = startValue + (100 - startValue) * done;
}

/** One tick (1 s): updates remaining time, page title and progress bar; at zero stops the interval and shows the finished state. */
function Repairlist() {
  let rest = resttime;
  if (!umode) {
    rest = resttime - (window.serverTime.getTime() - window.startTime) / 1000;
  }
  const timeBox = qs('#time');
  if (rest <= 0) {
    clearVisibleInterval(interval);
    if (timeBox) timeBox.textContent = window.Ready;
    qs('#command')?.remove();
    document.title = `${window.Ready} - ${window.Gamename}`;
    show(qs('#deployRepaired'));
    hide(qs('#progress'));
    return;
  }
  document.title = `${getRestTimeFormat(rest)} - ${window.Gamename}`;
  if (timeBox) timeBox.textContent = getRestTimeFormat(rest);
  setProgress(rest);
}

registerActions({ 'repair-all': repairAll });

window.onPageInit(() => {
  if (!qs('#time')) return;
  const timer = qs('.timer');
  const bar = qs('#progressbar');
  time = Number(qs('#time').dataset.time);
  resttime = Number(bar?.dataset.time);
  umode = Number(timer?.dataset.umode) === 1;

  if (bar && time > 0) {
    startValue = Math.max((time / (resttime + time)) * 100, 0.01);
    bar.max = 100;
    bar.value = startValue;
  }
  interval = setVisibleInterval(Repairlist, 1000);
  Repairlist();
});
