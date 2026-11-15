/** Installer: the language select in the top bar reloads the page with ?lang=<code>. */
document.addEventListener('change', (event) => {
  const select = event.target;
  if (!(select instanceof HTMLSelectElement) || !select.hasAttribute('data-lang-select')) return;
  const url = new URL(window.location.href);
  url.searchParams.set('lang', select.value);
  window.location.href = url.toString();
});
