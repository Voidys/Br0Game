/**
 * Tooltips for elements with data-tooltip-content (HTML). ".tooltip" follows the pointer,
 * ".tooltip_sticky" is anchored to its trigger so its content (links, buttons) can be used: it opens on
 * hover/focus, stays open while the pointer travels from trigger to popup (close delay), a click/tap/Enter pins
 * it, Esc, a click outside or a second click closes it.
 */
import { ready, getRestTimeFormat } from './core.js';

const SELECTOR = '.tooltip, .tooltip_sticky';
let tip;
let sticky = false;
let current = null;
let pinned = false;
let timer = 0;

/** The shared tooltip element (#tooltip), created on first use. */
function element() {
  if (!tip) {
    tip = document.getElementById('tooltip') ?? Object.assign(document.createElement('div'), { id: 'tooltip' });
    tip.classList.add('tip');
    tip.setAttribute('role', 'tooltip');
    if (!tip.isConnected) document.body.append(tip);
  }
  return tip;
}

/** Shows the tooltip with the source's data-tooltip-content at the pointer position. */
function open(source, x, y) {
  const box = element();
  current = source;
  box.innerHTML = source.getAttribute('data-tooltip-content') ?? '';
  // fixed durations in injected content (e.g. the demolish time) are rendered here, timers.js only sees the page
  box.querySelectorAll('.statictime[timestamp]').forEach((el) => { el.textContent = getRestTimeFormat(el.getAttribute('timestamp')); });
  box.style.display = 'block';
  box.classList.toggle('tooltip_sticky_div', source.classList.contains('tooltip_sticky'));
  if (source.classList.contains('tooltip_sticky')) anchor(source); else place(x, y);
}

/** Positions a sticky tooltip directly under its trigger (no gap), above it when there is no room, clamped to the viewport. */
function anchor(source) {
  const box = element();
  const rect = source.getBoundingClientRect();
  const width = box.offsetWidth;
  const height = box.offsetHeight;
  const left = Math.min(Math.max(8, rect.left), Math.max(8, window.innerWidth - width - 8));
  let top = rect.bottom - 1;
  if (top + height > window.innerHeight - 8 && rect.top - height + 1 >= 8) top = rect.top - height + 1;
  box.style.left = `${left + window.scrollX}px`;
  box.style.top = `${Math.max(8, top) + window.scrollY}px`;
}

/** Positions the tooltip next to (x, y), flipping to the other side when it would leave the viewport. */
function place(x, y) {
  const box = element();
  const width = box.offsetWidth;
  const height = box.offsetHeight;
  let left = x + 18;
  let top = y + 18;
  if (left + width > window.innerWidth - 12) left = x - width - 18;
  if (top + height > window.innerHeight - 12) top = y - height - 18;
  box.style.left = `${Math.max(8, left) + window.scrollX}px`;
  box.style.top = `${Math.max(8, top) + window.scrollY}px`;
}

/** Hides the tooltip and resets its state. */
function close() {
  clearTimeout(timer);
  if (!tip) return;
  tip.style.display = 'none';
  tip.classList.remove('tooltip_sticky_div');
  pinned = false;
  current = null;
}

/** Closes after a short delay so the pointer can move from the trigger into the popup. */
function closeSoon() {
  clearTimeout(timer);
  timer = setTimeout(close, 250);
}

/** Registers the delegated listeners (hover, click/tap pin, keyboard, outside click). */
export function initTooltips() {
  const sourceOf = (target) => (target instanceof Element ? target.closest(SELECTOR) : null);
  const inTip = (target) => target instanceof Element && target.closest('#tooltip') !== null;
  const isSticky = (el) => el?.classList.contains('tooltip_sticky');

  // make sticky triggers reachable by keyboard
  const prepare = (root) => root.querySelectorAll?.('.tooltip_sticky:not([tabindex])').forEach((el) => {
    el.setAttribute('tabindex', '0');
    el.setAttribute('role', 'button');
    el.setAttribute('aria-haspopup', 'true');
  });
  prepare(document);
  new MutationObserver((records) => records.forEach((r) => r.addedNodes.forEach((n) => {
    if (n instanceof Element) {
      if (n.matches('.tooltip_sticky:not([tabindex])')) prepare({ querySelectorAll: () => [n] });
      prepare(n);
    }
  }))).observe(document.body, { childList: true, subtree: true });

  document.addEventListener('mouseover', (event) => {
    if (inTip(event.target)) {
      clearTimeout(timer);
      return;
    }
    const source = sourceOf(event.target);
    if (!source) return;
    clearTimeout(timer);
    if (source === current || pinned) return;
    open(source, event.clientX, event.clientY);
  });

  document.addEventListener('mousemove', (event) => {
    if (current && !isSticky(current) && !pinned) place(event.clientX, event.clientY);
  });

  document.addEventListener('mouseout', (event) => {
    if (!current || pinned) return;
    const to = event.relatedTarget;
    if (to instanceof Element && (current.contains(to) || inTip(to))) return;
    if (current.contains(event.target) || inTip(event.target)) {
      if (isSticky(current)) closeSoon(); else close();
    }
  });

  document.addEventListener('click', (event) => {
    const source = sourceOf(event.target);
    if (source) {
      if (source.matches('a:not([href])') || isSticky(source)) event.preventDefault();
      if (current === source && tip?.style.display === 'block' && (pinned || !isSticky(source))) {
        close();
      } else {
        open(source, event.clientX, event.clientY);
        pinned = true;
      }
      return;
    }
    if (!inTip(event.target)) close();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && current) {
      close();
      return;
    }
    const source = sourceOf(event.target);
    if ((event.key === 'Enter' || event.key === ' ') && source && isSticky(source) && !(event.target instanceof HTMLInputElement)) {
      event.preventDefault();
      source.click();
    }
  });

  document.addEventListener('focusin', (event) => {
    const source = sourceOf(event.target);
    if (source && isSticky(source) && source !== current && !pinned) open(source, 0, 0);
  });

  window.addEventListener('resize', () => { if (current && isSticky(current)) anchor(current); });
}

ready(() => element());
