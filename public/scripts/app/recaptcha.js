/**
 * reCAPTCHA v3 for game forms. The site key comes from the data island #captcha-config ({key}). The
 * Google script is loaded on the first form submit, so idle pages don't pay for it; the token goes into
 * a hidden field "rcaptcha" and the form is submitted for real.
 */
import { pageData } from './core.js';

const key = pageData('captcha-config').key;
let loader = null;

/** loads api.js once; resolves when grecaptcha is ready */
function loadRecaptcha() {
  loader ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = `https://www.google.com/recaptcha/api.js?render=${key}`;
    script.onload = () => window.grecaptcha.ready(resolve);
    script.onerror = reject;
    document.head.appendChild(script);
  });
  return loader;
}

if (key) {
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (form.dataset.captchaDone) return;
    event.preventDefault();
    try {
      await loadRecaptcha();
      const token = await window.grecaptcha.execute(key);
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'rcaptcha';
      input.value = token;
      form.prepend(input);
      form.dataset.captchaDone = '1';
      form.submit();
    } catch {
      window.alert('Captcha could not be loaded. Please try again.');
    }
  });
}
