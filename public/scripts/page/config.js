/**
 * Page configuration for the non-game areas (admin, account, login). The layout renders the values the
 * scripts need into <script type="application/json" id="page-config"> (no inline JavaScript, so the page
 * works under a strict Content-Security-Policy); this module publishes them as the globals the shared
 * modules read (window.serverTime, ServerTimezoneOffset, days, months, tdformat, confirmText, ...).
 *
 * Keys of the island: offset (seconds), date ([y, m(1-12), d, h, i, s] of the server clock at render
 * time), ready, skin, lang, headInfo, days[], months[], tdformat, confirmText, auth, queryString,
 * timezone, debug.
 */
import { handleErr } from '../app/core.js';

const element = document.getElementById('page-config');
const config = element ? JSON.parse(element.textContent) : {};

/** readPageData(id) returns the parsed JSON island <script type="application/json" id="..."> or null */
export function readPageData(id) {
  const node = document.getElementById(id);
  return node ? JSON.parse(node.textContent) : null;
}

if (element) {
  const [year, month, day, hour, minute, second] = (config.date ?? [1970, 1, 1, 0, 0, 0]).map(Number);
  const serverTimeDelta = new Date(year, month - 1, day, hour, minute, second).getTime() - Date.now();
  Object.defineProperty(window, 'serverTime', { get: () => new Date(Date.now() + serverTimeDelta), configurable: true });
  Object.assign(window, {
    ServerTimezoneOffset: config.offset ?? 0,
    startTime: window.serverTime.getTime(),
    Gamename: document.title,
    Ready: config.ready ?? '',
    Skin: config.skin ?? '',
    Lang: config.lang ?? '',
    head_info: config.headInfo ?? '',
    days: config.days ?? [],
    months: config.months ?? [],
    tdformat: config.tdformat ?? '',
    confirmText: config.confirmText ?? '',
    auth: config.auth ?? 0,
    queryString: config.queryString ?? '',
    localtimezonestring: config.timezone ?? '',
  });
  if (config.debug) window.onerror = handleErr;
}
