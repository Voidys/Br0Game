/**
 * In-page links (href="#id"). The layouts set <base href> to the site/universe root, so the browser resolves "#id"
 * against that and leaves the page (e.g. the admin settings jump list went to the start page). This scrolls to the
 * target on the current page instead, updates the address bar and moves focus there. Links whose click was already
 * handled (tabs, dialogs: defaultPrevented) and "#" alone are left alone.
 */
document.addEventListener('click', (event) => {
  if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
    return;
  }
  const link = event.target.closest?.('a[href^="#"]');
  const id = link?.getAttribute('href').slice(1);
  if (!id) {
    return;
  }
  const target = document.getElementById(decodeURIComponent(id));
  if (!target) {
    return;
  }
  event.preventDefault();
  history.pushState(history.state, '', `${location.pathname}${location.search}#${id}`);
  target.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  if (!target.matches('a, button, input, select, textarea, [tabindex]')) {
    target.setAttribute('tabindex', '-1');
  }
  target.focus({ preventScroll: true });
});
