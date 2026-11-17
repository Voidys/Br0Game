/**
 * Page environment: server clock, language strings and flags the shared modules need. Game layout:
 * <script type="application/json" id="game-env"> (templates/game/layout.base.html.twig); admin and account
 * layouts: id="page-config" (page/config.js calls publishEnv() with it). No inline JavaScript, so the page
 * runs under a strict Content-Security-Policy.
 *
 * publishEnv() returns the values (modules import `env` instead of reading window.*) and still sets the
 * globals app/core.js, timers and dialog read (window.serverTime, startTime, days, months, tdformat, ...).
 *
 * Keys of the island: offset (seconds), date ([y, m(1-12), d, h, i, s] of the server clock at render
 * time), ready, lang, days[], months[], tdformat, timezone, serverTimezone, urls{}, isPlayerCardActive,
 * decimalSeparator, numberSuffixes[], viewShortlyNumber, vacation, confirmText (account/admin), debug.
 * `gamename` is the document title.
 */
import { pageData, handleErr } from './core.js';

export function publishEnv(data = {}) {
  const [year, month, day, hour, minute, second] = (data.date ?? [1970, 1, 1, 0, 0, 0]).map(Number);
  const serverTimeDelta = new Date(year, month - 1, day, hour, minute, second).getTime() - Date.now();
  Object.defineProperty(window, 'serverTime', { get: () => new Date(Date.now() + serverTimeDelta), configurable: true });

  const values = {
    ServerTimezoneOffset: data.offset ?? 0,
    startTime: window.serverTime.getTime(),
    Gamename: document.title,
    Ready: data.ready ?? '',
    Lang: data.lang ?? '',
    days: data.days ?? [],
    months: data.months ?? [],
    localtimezonestring: data.timezone ?? '',
    // timezone the server clock (serverTime) is expressed in: the game's configured timezone
    serverTimezoneString: data.serverTimezone || 'Europe/Berlin',
    tdformat: data.tdformat ?? '',
    confirmText: data.confirmText ?? '',
    // route URLs the shared modules open (information popup, planet actions, ...), rendered by the layout with path()
    urls: data.urls ?? {},
    isPlayerCardActive: data.isPlayerCardActive ?? false,
    decimalSeparator: data.decimalSeparator,
    numberSuffixes: data.numberSuffixes ?? [],
    viewShortlyNumber: data.viewShortlyNumber ?? false,
    vacation: data.vacation ?? false,
  };
  Object.assign(window, values);
  if (data.debug) window.onerror = handleErr;

  return {
    startTime: values.startTime,
    gamename: values.Gamename,
    ready: values.Ready,
    lang: values.Lang,
    tdformat: values.tdformat,
    days: values.days,
    months: values.months,
    decimalSeparator: values.decimalSeparator,
    serverTimezone: values.serverTimezoneString,
    localTimezone: values.localtimezonestring,
  };
}

/** The published values of this page (game layout); empty on pages without a game-env island. */
export const env = document.getElementById('game-env') ? publishEnv(pageData('game-env')) : {};
