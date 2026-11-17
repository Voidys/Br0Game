/**
 * Core helpers of the game UI (native ES module, no dependencies): DOM, HTTP, cookies, number and
 * time formatting, visibility-aware intervals. Modules import what they need; nothing is published on
 * window (templates have no inline handlers).
 */

// ---------------------------------------------------------------------------------------- DOM

/** First element matching `selector` below `root`, or null. */
export const qs = (selector, root = document) => root.querySelector(selector);
/** All elements matching `selector` below `root` as a real array. */
export const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

/** delegated event listener: on(document, 'click', '.foo', (event, element) => ...) */
export function on(root, type, selector, handler, options) {
  root.addEventListener(type, (event) => {
    const target = event.target instanceof Element ? event.target.closest(selector) : null;
    if (target && root.contains(target)) {
      handler(event, target);
    }
  }, options);
}

/** Runs `fn` once the DOM is parsed (immediately when it already is). */
export function ready(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn, { once: true });
  } else {
    fn();
  }
}

/** Shows an element by resetting or setting its inline `display` (null-safe). */
export const show = (el, display = '') => { if (el) el.style.display = display; };
/** Hides an element with inline `display: none` (null-safe). */
export const hide = (el) => { if (el) el.style.display = 'none'; };
/** Toggles an element's visibility via inline display; `force` true/false sets it explicitly (null-safe). */
export const toggle = (el, force) => {
  if (!el) return;
  const visible = force ?? getComputedStyle(el).display === 'none';
  el.style.display = visible ? '' : 'none';
};

/** HTML string -> DocumentFragment */
export function html(markup) {
  const template = document.createElement('template');
  template.innerHTML = markup.trim();
  return template.content;
}

/** fade an element out and hide it afterwards (CSS transition on opacity) */
export function fadeOut(el, ms = 400) {
  if (!el) return Promise.resolve();
  return el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: ms, easing: 'ease-out' })
    .finished.then(() => hide(el));
}

// ---------------------------------------------------------------------------------------- page data

const islands = new Map();

/**
 * Data a template hands to its page module: the content of <script type="application/json" id="…">
 * (Twig: data_island('page-data', {...})). Parsed once per element; {} when the island is missing.
 */
export function pageData(id = 'page-data') {
  const element = document.getElementById(id);
  if (!element) return {};
  if (!islands.has(element)) {
    try {
      islands.set(element, JSON.parse(element.textContent));
    } catch (error) {
      console.error(`data island #${id} is not valid JSON`, error);
      islands.set(element, {});
    }
  }
  return islands.get(element);
}

// ---------------------------------------------------------------------------------------- HTTP

/** URLSearchParams from a form, an object or a query string */
function params(data) {
  if (data instanceof URLSearchParams) return data;
  if (data instanceof HTMLFormElement) return new URLSearchParams(new FormData(data));
  if (typeof data === 'string') return new URLSearchParams(data);
  const result = new URLSearchParams();
  for (const [key, value] of Object.entries(data ?? {})) {
    if (Array.isArray(value)) {
      value.forEach((item) => result.append(key, item));
    } else if (value !== undefined && value !== null) {
      result.append(key, value);
    }
  }
  return result;
}

/** serialize form fields (or a list of inputs) like jQuery's .serialize() */
export function serialize(source) {
  if (source instanceof HTMLFormElement) return params(source).toString();
  const result = new URLSearchParams();
  for (const input of source) {
    if (!input.name || input.disabled) continue;
    if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) continue;
    result.append(input.name, input.value);
  }
  return result.toString();
}

/** fetch() with same-origin cookies; throws on a non-2xx status, so callers can use try/catch. */
async function request(url, init) {
  const response = await fetch(url, { credentials: 'same-origin', ...init });
  if (!response.ok) {
    throw new Error(`HTTP ${response.status} for ${url}`);
  }
  return response;
}

/** GET `url`, resolves to the body text. Network: one request, same origin; rejects on HTTP errors. */
export const getText = async (url) => (await request(url)).text();
/** GET `url` with `Accept: application/json`, resolves to the parsed body. Rejects on HTTP errors. */
export const getJSON = async (url) => (await request(url, { headers: { Accept: 'application/json' } })).json();
/**
 * POST form-encoded data (see params()). The CSRF token is added to unsafe requests by app/csrf.js, which wraps
 * fetch. Resolves to the body text, or parsed JSON with `as = 'json'`. Rejects on HTTP errors.
 * @param {string} url
 * @param {HTMLFormElement|URLSearchParams|Object|string} data
 * @param {'text'|'json'} [as]
 */
export async function post(url, data, as = 'text') {
  const response = await request(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: params(data),
  });
  return as === 'json' ? response.json() : response.text();
}

// ---------------------------------------------------------------------------------------- cookies

/** Cookie access (URI-encoded names/values). `set` writes a Lax cookie for the whole site, `days` defaults to 365. */
export const cookie = {
  get(name) {
    const match = document.cookie.split('; ').find((part) => part.startsWith(`${encodeURIComponent(name)}=`));
    return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null;
  },
  set(name, value, days = 365) {
    const expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
  },
  remove(name) {
    document.cookie = `${encodeURIComponent(name)}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  },
};

// ---------------------------------------------------------------------------------------- numbers

/** PHP-style number_format: rounds to `decimals`, `dec` is the decimal and `sep` the thousands separator; non-numeric input counts as 0. */
function number_format(number, decimals, dec, sep) {
  const n = Number(String(number).replace(/[^0-9+\-Ee.]/g, ''));
  const value = Number.isFinite(n) ? n : 0;
  const precision = Number.isFinite(+decimals) ? Math.abs(decimals) : 0;
  const parts = (precision ? (Math.round(value * 10 ** precision) / 10 ** precision).toFixed(precision) : String(Math.round(value))).split('.');
  parts[0] = parts[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
  return parts.join(dec);
}

/** Writes a large number without exponent notation ("1e+21" style output of String()) as far as precision allows; other values unchanged. */
function removeE(value) {
  const text = String(value);
  if (!text.includes('e+')) return text;
  const exponent = parseInt(text.replace(/\S+.?e\+/g, ''), 10);
  return Number.isNaN(exponent) || exponent === 0 ? text : parseFloat(text).toPrecision(Math.min(exponent + 1, 21));
}

/** Number for display, rounded to `dec` decimals, with the player's separators (`window.decimalSeparator` 0 = "1.234,5", else "1,234.5"). */
export function pretty_number(value, dec = 0) {
  const number = dec == 0 ? removeE(Math.round(value)) : value;
  return window.decimalSeparator == 0 ? number_format(number, dec, ',', '.') : number_format(number, dec, '.', ',');
}

/** Abbreviated number (12.3 K, 4 M, ...) using the suffixes of `window.numberSuffixes` (defaults K, M, B, T, ...); one decimal below 100 in a unit (`significant`: three significant digits, 8,98 B / 89,8 B / 898 B). Returns HTML (non-breaking space). */
export function shortly_number(number, significant = false) {
  const units = Array.isArray(window.numberSuffixes) && window.numberSuffixes.length
    ? window.numberSuffixes
    : ['', 'K', 'M', 'B', 'T', 'Q', 'Q+', 'S', 'S+', 'O', 'N'];
  const negate = number < 0 ? -1 : 1;
  let value = Math.abs(number);
  let key = 0;
  while (value >= 1000 && key < units.length - 1) {
    key++;
    value /= 1000;
  }
  const decimal = value !== Math.floor(value) && key !== 0 && value !== 0 && value < 100;
  if (significant && key !== 0) return pretty_number(negate * value, value < 10 ? 2 : (value < 100 ? 1 : 0)) + `&nbsp;${units[key]}`;
  return pretty_number(negate * value, decimal ? 1 : 0) + (key !== 0 ? `&nbsp;${units[key]}` : '');
}

// ---------------------------------------------------------------------------------------- time

/** Left-pads the number to `size` digits with zeros. */
export const pad = (num, size) => String(num).padStart(size, '0');

/** Zero-pads (or pads with `prefix`) an integer to `size` characters; a minus sign goes in front of the zeros. */
export function dezInt(num, size, prefix = '0') {
  const minus = num < 0 ? '-' : '';
  const digits = String(Math.abs(parseInt(num, 10)));
  const fill = prefix.repeat(Math.max(0, size - digits.length));
  return prefix === '0' ? minus + fill + digits : fill + minus + digits;
}

/** current server time (window.serverTime is defined by the layout) */
export const now = () => (window.serverTime instanceof Date ? window.serverTime : new Date());

/**
 * Formats a point in time with the placeholders [d] [D] [m] [M] [j] [Y] [y] [G] [H] [i] [s] (day, weekday name,
 * month, month name, ... as in the game's date formats). Weekday/month names come from `window.days` / `window.months`.
 * @param {number} timestamp milliseconds since the epoch (not seconds)
 * @param {string} format
 */
export function getFormatedDate(timestamp, format) {
  const time = new Date(timestamp + (window.ServerTimezoneOffset ?? 0) * 1000);
  const days = window.days ?? [];
  const months = window.months ?? [];
  return format
    .replace('[d]', dezInt(time.getDate(), 2))
    .replace('[D]', days[time.getDay()] ?? '')
    .replace('[m]', dezInt(time.getMonth() + 1, 2))
    .replace('[M]', months[time.getMonth()] ?? '')
    .replace('[j]', String(time.getDate()))
    .replace('[Y]', String(time.getFullYear()))
    .replace('[y]', String(time.getFullYear()).slice(2))
    .replace('[G]', String(time.getHours()))
    .replace('[H]', dezInt(time.getHours(), 2))
    .replace('[i]', dezInt(time.getMinutes(), 2))
    .replace('[s]', dezInt(time.getSeconds(), 2));
}

/** Seconds as HH:MM:SS (hours not capped at 24). */
export function getFormatedTime(time) {
  const hours = Math.floor(time / 3600);
  const minutes = Math.floor((time % 3600) / 60);
  const seconds = time % 60;
  return `${dezInt(hours, 2)}:${dezInt(minutes, 2)}:${dezInt(seconds, 2)}`;
}

/**
 * Remaining time as "1Y 2M 3W 4d 05h 06m 07s": leading zero units are dropped, at most three units are shown
 * when `shorten` is true. Rounds up so a second shows until it has elapsed. JS twin of the PHP helper of the same name.
 * @param {number} secs remaining seconds
 * @param {boolean} [shorten]
 */
export function getRestTimeFormat(secs, shorten) {
  // ceil keeps the displayed second until it actually elapses
  let rest = Math.max(0, Math.ceil(Number(secs) - 1e-9));
  const thresholds = [31536000, 2592000, 604800, 86400, 3600, 60, 1]; // y, m, w, d, h, m, s
  const labels = ['Y', 'M', 'W', 'd', 'h', 'm', 's'];
  const amounts = [];
  let longest = -1;
  thresholds.forEach((threshold, index) => {
    const inside = Math.floor(rest / threshold);
    if (inside === 0 && longest === index - 1) {
      longest = index;
    }
    rest -= inside * threshold;
    amounts[index] = inside;
  });
  const parts = [];
  const start = longest + 1;
  for (let i = start; i < 7 && (i - start < 3 || shorten !== true); i++) {
    parts.push(i > 3 ? pad(amounts[i], 2) + labels[i] : amounts[i] + labels[i]);
  }
  return parts.join(' ');
}

// ---------------------------------------------------------------------------------------- intervals

const visibleIntervals = new Map();
let visibilityBound = false;

/** setInterval that skips work while the tab is hidden (callbacks derive state from absolute time) */
export function setVisibleInterval(fn, ms) {
  const id = window.setInterval(() => { if (!document.hidden) fn(); }, ms);
  visibleIntervals.set(id, fn);
  if (!visibilityBound) {
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) visibleIntervals.forEach((callback) => callback());
    });
    visibilityBound = true;
  }
  return id;
}

/** Stops an interval created by setVisibleInterval(). */
export function clearVisibleInterval(id) {
  window.clearInterval(id);
  visibleIntervals.delete(id);
}

// ---------------------------------------------------------------------------------------- misc

/** formerly a popup window; now the page's modal (scripts/app/dialog.js), height follows the content */
export function OpenPopup(url, name, width) {
  const target = url.startsWith('?') ? `game${url}` : url;
  return window.Dialog ? window.Dialog.open(target, width) : (location.href = target);
}

/**
 * short notice in the corner (formerly the #tooltip "notify" box). `options.kind` ('success' | 'error') tints it,
 * `options.top` shows it under the top bar, `options.ms` is how long it stays before fading (default 600).
 */
export function NotifyBox(text, options = {}) {
  const box = document.createElement('div');
  box.className = 'notify';
  if (options.kind) box.classList.add(`notify--${options.kind}`);
  if (options.top) box.classList.add('notify--top');
  box.setAttribute('role', options.kind === 'error' ? 'alert' : 'status');
  box.innerHTML = text;
  document.body.append(box);
  window.setTimeout(() => fadeOut(box, 1000).then(() => box.remove()), options.ms ?? 600);
}

/** window.onerror handler: logs the error and tells the player (alert); returning true suppresses the browser's own report. */
export function handleErr(message, url, line, col, error) {
  const text = `There is an error at this page.\nError: ${message}\nURL: ${url}\nLine: ${line}\nColumn: ${col}\n`
    + (error ? `Error Object: ${error.stack}\n` : '');
  console.error(text);
  window.Dialog?.alert(`${text}\nClick OK to continue viewing this page.`);
  return true;
}
