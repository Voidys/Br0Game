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
