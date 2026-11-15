/**
 * CSRF protection of the front end (native ES module, no dependencies). The server puts the session's
 * token into <meta name="csrf-token"> (Twig csrf_meta()); this module makes every state-changing
 * request carry it, so templates and page scripts need no per-form code:
 *
 *   - forms with method="post": hidden field "_csrf" added when the form is submitted (also for
 *     programmatic form.submit(), requestSubmit() and new FormData(form));
 *   - fetch(): header "X-CSRF-Token" on every same-origin request that is not GET/HEAD;
 *   - navigator.sendBeacon(): "_csrf" query parameter (beacons cannot set headers);
 *   - links with data-method="post": clicking submits an invisible POST form to the link's URL (query
 *     string kept, so existing ?page=...&mode=... handlers work); data-confirm="..." is honoured by the
 *     handlers that run before this one, a declined confirmation cancels the click.
 *
 * Server side: App\EventListener\CsrfListener / App\Security\CsrfPolicy.
 */

const FIELD = '_csrf';
const HEADER = 'X-CSRF-Token';
const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

/** the session's CSRF token ('' when the page has none, e.g. static error pages) */
export function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

const sameOrigin = (url) => {
  try {
    return new URL(url, document.baseURI).origin === window.location.origin;
  } catch {
    return false;
  }
};

/** add the hidden token field to a POST form that goes to our own origin */
function addField(form) {
  const token = csrfToken();
  if (!token || (form.method || '').toLowerCase() !== 'post' || !sameOrigin(form.action)) return;
  let input = form.querySelector(`input[name="${FIELD}"]`);
  if (!input) {
    input = document.createElement('input');
    input.type = 'hidden';
    input.name = FIELD;
    form.appendChild(input);
  }
  input.value = token;
}

// the submit event is the normal path, "formdata" covers requestSubmit()/new FormData(form),
// submit() fires neither and is wrapped
document.addEventListener('submit', (event) => {
  if (event.target instanceof HTMLFormElement) addField(event.target);
}, true);
document.addEventListener('formdata', (event) => {
  const form = event.target;
  const token = csrfToken();
  if (form instanceof HTMLFormElement && token && (form.method || '').toLowerCase() === 'post' && sameOrigin(form.action)) {
    event.formData.set(FIELD, token);
  }
});
const nativeSubmit = HTMLFormElement.prototype.submit;
HTMLFormElement.prototype.submit = function submit() {
  addField(this);
  return nativeSubmit.call(this);
};

// fetch: header for unsafe same-origin requests
const nativeFetch = window.fetch.bind(window);
window.fetch = (input, init = {}) => {
  const request = input instanceof Request ? input : null;
  const method = (init.method ?? request?.method ?? 'GET').toUpperCase();
  const url = request ? request.url : String(input);
  const token = csrfToken();
  if (!token || SAFE_METHODS.includes(method) || !sameOrigin(url)) return nativeFetch(input, init);
  const headers = new Headers(init.headers ?? request?.headers ?? {});
  if (!headers.has(HEADER)) headers.set(HEADER, token);
  return nativeFetch(input, { ...init, headers });
};

// sendBeacon: query parameter
if (navigator.sendBeacon) {
  const nativeBeacon = navigator.sendBeacon.bind(navigator);
  navigator.sendBeacon = (url, data) => {
    const token = csrfToken();
    if (!token || !sameOrigin(url)) return nativeBeacon(url, data);
    const target = new URL(url, document.baseURI);
    target.searchParams.set(FIELD, token);
    return nativeBeacon(target.toString(), data);
  };
}

// links that change state: data-method="post". On window = after the page's own click handlers
// (confirm dialogs), which cancel the event when the user declines.
window.addEventListener('click', (event) => {
  const link = event.target instanceof Element ? event.target.closest('a[data-method="post"]') : null;
  if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey) return;
  event.preventDefault();
  const form = document.createElement('form');
  form.method = 'post';
  form.action = link.href;
  form.style.display = 'none';
  document.body.appendChild(form);
  form.submit();
});
