/**
 * Settings page: the "reset" button next to each colour picker puts the factory colour
 * (the input's basecolor attribute) back. Collapsible sections are the built-in "toggle-rows" action.
 */
import { registerActions } from '../app/actions.js';

registerActions({
  'reset-color': (button) => {
    const input = button.parentNode.querySelector('input');
    input.value = input.getAttribute('basecolor');
  },
});

/** Theme (CSS) select: a small preview of the chosen theme next to it (preview.png of the theme folder; hidden when there is none). */
window.onPageInit(() => {
  const select = document.getElementById('cssTheme');
  const preview = document.getElementById('theme-preview');
  if (!select || !preview) return;
  const show = () => { preview.hidden = false; };
  preview.addEventListener('load', show);
  preview.addEventListener('error', () => { preview.hidden = true; });
  select.addEventListener('change', () => {
    preview.hidden = true;
    preview.src = `${preview.dataset.base}${encodeURIComponent(select.value)}/preview.png`;
  });
  if (preview.complete && preview.naturalWidth > 0) show();
});
