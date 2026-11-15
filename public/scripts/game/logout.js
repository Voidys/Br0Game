/** Logout notice: back to the start page after five seconds (the countdown shows in #seconds when the template has it). */
let seconds = 5;
const output = document.getElementById('seconds');

window.setTimeout(() => { window.location.href = './'; }, 5000);
window.setInterval(() => {
  if (seconds === 0) return;
  seconds--;
  if (output) output.textContent = seconds;
}, 1000);
