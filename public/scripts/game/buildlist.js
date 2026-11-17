import { qs, setVisibleInterval, clearVisibleInterval, now, getRestTimeFormat } from '../app/core.js';
import { env } from '../app/env.js';

/**
 * Buildings page: countdown and progress bar of the building under construction. `data-time` of the #progressbar is the
 * remaining seconds at render time, of #time the total duration; in vacation mode (.timer[data-umode=1]) the
 * remaining time is frozen. When it reaches zero the "ready" text is shown, the cancel command removed and the
 * page title reset; until then the title shows the remaining time.
 */
let resttime = 0;
let time = 0;
let interval = 0;
let buildname = '';
let umode = false;
let startValue = 0;

/** Sets the progress bar between its start value (share already done at render time) and 100 from the remaining seconds. */
function setProgress(rest) {
  const bar = qs('#progressbar');
  if (!bar || time <= 0) return;
  const done = resttime > 0 ? Math.min(Math.max(1 - rest / resttime, 0), 1) : 1;
  bar.value = startValue + (100 - startValue) * done;
}

/** One tick (1 s): updates the remaining-time text, page title and progress bar; at zero stops the interval and shows the finished state. */
function Buildlist() {
  let rest = resttime;
  if (!umode) {
    rest = resttime - (now().getTime() - env.startTime) / 1000;
  }
  const timeBox = qs('#time');
  if (rest <= 0) {
    clearVisibleInterval(interval);
    if (timeBox) timeBox.textContent = env.ready;
    qs('#command')?.remove();
    document.title = `${env.ready} - ${env.gamename}`;
    setProgress(0);
    return;
  }
  document.title = `${getRestTimeFormat(rest)} - ${buildname} - ${env.gamename}`;
  if (timeBox) timeBox.textContent = getRestTimeFormat(rest);
  setProgress(rest);
}

window.onPageInit(() => {
  const timer = qs('.timer');
  const bar = qs('#progressbar');
  time = Number(qs('#time')?.dataset.time);
  resttime = Number(bar?.dataset.time);
  umode = Number(timer?.dataset.umode) === 1;
  buildname = (qs('.build-queue__item.is-active .build-queue__name')?.textContent ?? '').replace(/\s+/g, ' ').trim();

  if (bar && time > 0) {
    startValue = Math.max(100 - (resttime / time) * 100, 0.01);
    bar.max = 100;
    bar.value = startValue;
  }
  interval = setVisibleInterval(Buildlist, 1000);
  Buildlist();
});
