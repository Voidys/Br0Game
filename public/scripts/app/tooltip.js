/**
 * Tooltips for elements with data-tooltip-content (HTML). ".tooltip" follows the pointer,
 * ".tooltip_sticky" stays put so its content (links, buttons) can be used. On touch devices a tap
 * toggles the tooltip, a tap elsewhere closes it.
 */
import { ready } from './core.js';

const SELECTOR = '.tooltip, .tooltip_sticky';
let tip;
let sticky = false;
let current = null;

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
  box.style.display = 'block';
  place(x, y);
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

/** Hides the tooltip and resets its sticky state. */
function close() {
  if (!tip) return;
  tip.style.display = 'none';
  tip.classList.remove('tooltip_sticky_div');
  sticky = false;
  current = null;
}

/** Registers the delegated listeners: hover/move/leave for pointer devices, tap to toggle for touch devices; sticky tooltips stay open while the pointer is over them. */
export function initTooltips() {
  const touch = window.matchMedia('(pointer: coarse)').matches;

  if (touch) {
    document.addEventListener('pointerdown', (event) => {
      const source = event.target instanceof Element ? event.target.closest(SELECTOR) : null;
      if (source) {
        event.preventDefault();
        if (current === source && tip?.style.display === 'block') {
          close();
        } else {
          open(source, event.clientX, event.clientY);
        }
        return;
      }
      if (!(event.target instanceof Element && event.target.closest('#tooltip'))) close();
    });
    return;
  }

  document.addEventListener('mouseover', (event) => {
    const source = event.target instanceof Element ? event.target.closest(SELECTOR) : null;
    if (!source || source === current) return;
    open(source, event.clientX, event.clientY);
    if (source.classList.contains('tooltip_sticky')) {
      sticky = true;
      element().classList.add('tooltip_sticky_div');
    }
  });

  document.addEventListener('mousemove', (event) => {
    if (current && !sticky) place(event.clientX, event.clientY);
  });

  // close once the pointer has left both the source and (for sticky tooltips) the tooltip itself
  document.addEventListener('mouseout', (event) => {
    if (!current) return;
    const inside = (el) => el instanceof Element && (current.contains(el) || (sticky && el.closest('#tooltip')));
    if (inside(event.relatedTarget)) return;
    if (inside(event.target)) close();
  });
}

ready(() => element());
