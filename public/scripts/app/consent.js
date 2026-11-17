/**
 * Cookie notice (templates/shared/cookie_notice.html.twig, config key cookie_consent). The choice lives in the
 * first-party cookie "cookie_consent" ("all" or "necessary", one year). Optional features (anything beyond the
 * technically necessary cookies) must check hasConsent('all') before storing or loading anything.
 */
import { cookie } from './core.js';

const NAME = 'cookie_consent';

/** The stored choice ("all", "necessary") or null while the visitor has not decided. */
export const consentChoice = () => {
  const value = cookie.get(NAME);
  return value === 'all' || value === 'necessary' ? value : null;
};

/** True when the visitor chose `level` ("all" by default; "necessary" is always granted once decided). */
export const hasConsent = (level = 'all') => {
  const choice = consentChoice();
  return level === 'necessary' ? choice !== null : choice === 'all';
};

document.addEventListener('click', (event) => {
  const button = event.target.closest?.('[data-cookie-choice]');
  if (!button) {
    return;
  }
  cookie.set(NAME, button.dataset.cookieChoice === 'all' ? 'all' : 'necessary', 365);
  button.closest('[data-cookie-notice]')?.remove();
  window.dispatchEvent(new CustomEvent('cookie-consent', { detail: consentChoice() }));
});
