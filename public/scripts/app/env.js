/**
 * Page environment of the game area. The layout renders the server clock, the language strings and the
 * flags the shared modules need into <script type="application/json" id="game-env"> (templates/game/
 * layout.base.html.twig: no inline JavaScript, so the page runs under a strict Content-Security-Policy);
 * this module, the first one main.js imports, publishes them as the globals the modules read
 * (window.serverTime, startTime, Ready, Lang, days, months, tdformat, decimalSeparator, ...).
 *
 * Keys of the island: offset (seconds), date ([y, m(1-12), d, h, i, s] of the server clock at render
 * time), gamename is taken from the document title; ready, skin, lang, headInfo, auth, days[], months[],
 * tdformat, timezone, queryString, isPlayerCardActive, decimalSeparator, numberSuffixes[], debug.
 */
import { pageData, handleErr } from './core.js';

const env = pageData('game-env');
const [year, month, day, hour, minute, second] = (env.date ?? [1970, 1, 1, 0, 0, 0]).map(Number);
const serverTimeDelta = new Date(year, month - 1, day, hour, minute, second).getTime() - Date.now();

Object.defineProperty(window, 'serverTime', { get: () => new Date(Date.now() + serverTimeDelta), configurable: true });
Object.assign(window, {
  ServerTimezoneOffset: env.offset ?? 0,
  serverTimeDelta,
  startTime: window.serverTime.getTime(),
  Gamename: document.title,
  Ready: env.ready ?? '',
  Skin: env.skin ?? '',
  Lang: env.lang ?? '',
  head_info: env.headInfo ?? '',
  auth: env.auth ?? 0,
  days: env.days ?? [],
  months: env.months ?? [],
  localtimezonestring: env.timezone ?? '',
  // timezone the server clock (serverTime) is expressed in: the game's configured timezone
  serverTimezoneString: env.serverTimezone || 'Europe/Berlin',
  tdformat: env.tdformat ?? '',
  queryString: env.queryString ?? '',
  isPlayerCardActive: env.isPlayerCardActive ?? false,
  decimalSeparator: env.decimalSeparator,
  userLang: env.lang ?? '',
  numberSuffixes: env.numberSuffixes ?? [],
  viewShortlyNumber: env.viewShortlyNumber ?? false,
  vacation: env.vacation ?? false,
});

if (env.debug) window.onerror = handleErr;
