/**
 * Time displays: server clock (.servertime), countdowns (.countdown[data-time], .buildcountdown[timestamp]),
 * static durations (.statictime[timestamp]), arrival clocks (.statictimer[data-time]) and the missile
 * flight time (#irakFlyTime). All derive from absolute server time, so hidden tabs can skip ticks.
 */
import { qsa, now, pad, getFormatedDate, getFormatedTime, getRestTimeFormat } from './core.js';

const startTime = () => window.startTime ?? now().getTime();
const elapsed = () => (now().getTime() - startTime()) / 1000;

/** Writes the current server time (window.tdformat) into every `.servertime` element. */
function updateClock() {
  const text = getFormatedDate(now().getTime(), window.tdformat ?? '[H]:[i]:[s]');
  qsa('.servertime').forEach((el) => { el.textContent = text; });
}

/** Refreshes `.countdown` (data-time = absolute end in seconds since page render) and `.buildcountdown` (timestamp = remaining seconds at render time). */
function updateCountdowns() {
  qsa('.countdown').forEach((el) => {
    const rest = Number(el.dataset.time) - elapsed();
    el.textContent = rest <= 0 ? '-' : getRestTimeFormat(rest);
  });
  // timestamp = remaining seconds at render time
  qsa('.buildcountdown').forEach((el) => {
    const rest = Math.max(0, Number(el.getAttribute('timestamp')) - elapsed());
    el.textContent = getRestTimeFormat(rest, true);
  });
}

/** One-time rendering of fixed durations, arrival times (`.statictimer`, in the player's time zone, with a day prefix "nT") and the missile flight time. */
function staticTimes() {
  qsa('.statictime').forEach((el) => { el.textContent = getRestTimeFormat(el.getAttribute('timestamp')); });

  qsa('.statictimer').forEach((el) => {
    const arrival = new Date(new Date(parseInt(el.dataset.time, 10) * 1000)
      .toLocaleString('en-US', { timeZone: window.localtimezonestring }));
    const days = Math.floor((arrival - now()) / 864e5);
    el.textContent = `${days > 0 ? `${days}T ` : ''}${pad(arrival.getHours(), 2)}:${pad(arrival.getMinutes(), 2)}:${pad(arrival.getSeconds(), 2)}`;
  });

  const missile = document.getElementById('irakFlyTime');
  if (missile) missile.textContent = getFormatedTime(parseInt(missile.textContent, 10) || 0);
}

/** Clock and countdowns, run just after each full server second (a free-running 1 s interval drifted against it, so seconds were skipped or shown twice); skipped while the tab is hidden. */
function tick() {
  if (!document.hidden) {
    updateClock();
    updateCountdowns();
  }
  window.setTimeout(tick, 1000 - (now().getTime() % 1000) + 5);
}

/** Renders all time displays once and keeps clock and countdowns ticking every second (paused while the tab is hidden). */
export function initTimers() {
  updateClock();
  updateCountdowns();
  staticTimes();
  window.setTimeout(tick, 1000 - (now().getTime() % 1000) + 5);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      updateClock();
      updateCountdowns();
    }
  });
}
