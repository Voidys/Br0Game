/**
 * Time displays: server clock (.servertime), countdowns (.countdown[data-time], .buildcountdown[timestamp]),
 * static durations (.statictime[timestamp]), arrival clocks (.statictimer[data-time]) and the missile
 * flight time (#irakFlyTime). All derive from absolute server time, so hidden tabs can skip ticks.
 */
import { qsa, now, pad, getFormatedDate, getFormatedTime, getRestTimeFormat, setVisibleInterval } from './core.js';

const startTime = () => window.startTime ?? now().getTime();
const elapsed = () => (now().getTime() - startTime()) / 1000;

export function updateClock() {
  const text = getFormatedDate(now().getTime(), window.tdformat ?? '[H]:[i]:[s]');
  qsa('.servertime').forEach((el) => { el.textContent = text; });
}

export function updateCountdowns() {
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

export function initTimers() {
  updateClock();
  updateCountdowns();
  staticTimes();
  setVisibleInterval(updateClock, 1000);
  setVisibleInterval(updateCountdowns, 1000);
}
