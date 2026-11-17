/** Tutorial box: folding is the shared accordion (<details>, scripts/game/overview.js); this file only handles goal links. */
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
