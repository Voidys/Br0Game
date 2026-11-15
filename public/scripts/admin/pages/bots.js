/**
 * Bots, providers tab: "test connection" buttons (.js-test) post the provider row's key, model and URL to
 * admin?page=bots&mode=providers&action=test and show the result next to the button. Texts
 * (testing/ok/failed) and the CSRF field come from the JSON island #bots-config.
 */
import { readPageData } from '../../page/config.js';

const config = readPageData('bots-config') ?? {};
const text = config.text ?? {};

document.querySelectorAll('#bots-providers-form .js-test').forEach((button) => {
  button.addEventListener('click', async () => {
    const row = button.closest('tr');
    const out = row.querySelector('.js-result');
    const data = new FormData();
    data.append('csrf', config.csrf ?? '');
    data.append('provider', row.dataset.provider);
    data.append('api_key', row.querySelector('.js-key').value);
    data.append('model', row.querySelector('.js-model').value);
    data.append('base_url', row.querySelector('.js-url').value);
    out.textContent = text.testing;
    button.disabled = true;
    try {
      const response = await fetch('admin?page=bots&mode=providers&action=test', { method: 'POST', body: data, credentials: 'same-origin' });
      const result = await response.json();
      const badge = document.createElement('span');
      badge.className = `badge badge--${result.ok ? 'ok' : 'danger'}`;
      badge.textContent = result.ok ? text.ok : text.failed;
      const detail = document.createElement('span');
      detail.textContent = ` ${result.latency_ms || 0} ms - ${result.ok ? result.response : result.message}`;
      out.replaceChildren(badge, detail);
    } catch (error) {
      out.textContent = `${text.failed}: ${error}`;
    } finally {
      button.disabled = false;
    }
  });
});
