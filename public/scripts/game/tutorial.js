/**
 * Tutorial box: the header folds the box, the state is kept in localStorage ('tutorial' = 'true' means
 * folded; the default is open).
 */
import { registerActions } from '../app/actions.js';

registerActions({
  /**
   * Goal link of a tutorial step (e.g. Discord): the link opens in a new tab (default action), this tab
   * reports the goal as fulfilled with a POST to data-goal (csrf.js adds the token).
   */
  'tutorial-link': (link) => {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = link.dataset.goal;
    form.style.display = 'none';
    document.body.append(form);
    form.submit();
    return true;
  },
});

if (localStorage.getItem('tutorial') === null) {
  localStorage.setItem('tutorial', 'false');
}

const box = document.getElementById('tutorial');
const arrow = document.getElementById('tutorial-toggle');
const header = document.getElementById('tutorial-header');

if (box && arrow && header) {
  const apply = (folded) => {
    arrow.textContent = folded ? '▼' : '▲';
    box.classList.toggle('hidden', folded);
  };
  apply(localStorage.getItem('tutorial') === 'true');
  header.addEventListener('click', () => {
    const folded = localStorage.getItem('tutorial') === 'false';
    apply(folded);
    localStorage.setItem('tutorial', folded.toString());
  });
}
