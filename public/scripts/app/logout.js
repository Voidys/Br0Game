/**
 * Logout notice (game and account area): back to the start page after five seconds. When the template has
 * #seconds, the countdown is shown there.
 */
import { qs } from './core.js';

const output = qs('#seconds');
let seconds = 5;

window.setTimeout(() => { window.location.href = './'; }, seconds * 1000);
window.setInterval(() => {
  if (seconds === 0) return;
  seconds -= 1;
  if (output) output.textContent = seconds;
}, 1000);
