/**
 * Forms that post in the background and report the server's answer in the modal dialog.
 *
 *   <form data-ajax-dialog="{{ path('admin_reshuffle_send') }}">  submit goes to that URL via fetch(POST,
 *                                                               form data); the answer text is shown in
 *                                                               Dialog.alert, closing it reloads the page
 *
 * postToDialog() is the same for page modules that validate first (sendMessages.js). The CSRF token is
 * added to fetch() by ../app/csrf.js.
 */
import { Dialog } from '../app/dialog.js';

/**
 * POST the form's fields to url, show the response in the dialog and reload the page once it is closed.
 * @param {HTMLFormElement} form
 * @param {string} url
 */
export async function postToDialog(form, url) {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: new URLSearchParams(new FormData(form)),
  });
  const text = await response.text();
  Dialog.alert(text, () => window.location.reload());
}

/** Delegated submit handler for `form[data-ajax-dialog=<url>]`: posts in the background via postToDialog() instead of navigating. */
export function initAjaxForms() {
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.ajaxDialog) return;
    event.preventDefault();
    postToDialog(form, form.dataset.ajaxDialog);
  });
}
