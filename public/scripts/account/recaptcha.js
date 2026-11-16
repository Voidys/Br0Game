/**
 * reCAPTCHA v3 for the account forms. The site key comes from the JSON island #captcha-config ({key}).
 * The Google script is loaded lazily on the first submit, so idle pages don't pay for it; the token
 * goes into a hidden field "rcaptcha" and the form is submitted for real.
 */
import { readPageData } from '../page/config.js';

const key = readPageData('captcha-config')?.key;
let loader = null;

/** load api.js once; resolves when grecaptcha is ready */
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
  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await loadRecaptcha();
        const token = await window.grecaptcha.execute(key);
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'rcaptcha';
        input.value = token;
        form.insertBefore(input, form.firstChild);
        form.submit();
      } catch {
        window.Dialog.alert('Captcha could not be loaded. Please try again.');
      }
    });
  });
}
