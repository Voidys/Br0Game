/**
 * Modal windows on the native <dialog> element, in the page's own document (no iframes, no popup
 * windows): the modal inherits the page's stylesheets, so it follows the player's theme.
 *
 * Dialog.open(url[, width]) loads a game page as a fragment (request header X-Game-Fragment: the
 *   server answers with the content only and skips the full page work, see RuntimeContext::$ajax),
 *   runs its scripts, keeps its forms and same-page links inside the modal.
 * Dialog.show(element | <template>[, {width}]) shows inline content (hidden markup of the page).
 * Dialog.alert(message[, callback]) themed message box. Dialog.close() closes the topmost modal.
 */
import { on } from './core.js';

const CLOSE_BUTTON = '<button type="button" class="modal__close" data-dialog-close aria-label="Close">'
  + '<svg class="icon" aria-hidden="true"><use href="./styles/resource/icons/lucide.svg#i-x"></use></svg></button>';

let contentDialog = null;
let loadToken = 0;

function sizeFor(dialog, width) {
  dialog.style.setProperty('--modal-width', width ? `${width}px` : '');
}

function closeOnBackdrop(dialog) {
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
}

function pageOf(url) {
  try {
    const parsed = new URL(url, document.baseURI);
    return `${parsed.pathname}?${(parsed.searchParams.get('page') ?? '').toLowerCase()}`;
  } catch {
    return '';
  }
}

function buildContentDialog() {
  const dialog = document.createElement('dialog');
  dialog.className = 'modal modal--content';
  dialog.innerHTML = `${CLOSE_BUTTON}<div class="modal__body" aria-live="polite"></div>`;
  closeOnBackdrop(dialog);
  dialog.addEventListener('close', () => {
    if (dialog.open) return; // the close event is async: reopened meanwhile
    loadToken++;
    dialog.querySelector('.modal__body').replaceChildren();
    delete dialog.dataset.url;
  });

  // same-page links (pagination, note details, ...) stay in the modal; others navigate the page
  dialog.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || !dialog.contains(link) || link.target || link.hasAttribute('download')
      || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey) {
      return;
    }
    const href = link.getAttribute('href');
    if (href === '' || href.startsWith('#') || href.startsWith('javascript:')) return;
    const url = new URL(href, document.baseURI);
    if (url.origin === location.origin && pageOf(url.href) === pageOf(dialog.dataset.url ?? '')) {
      event.preventDefault();
      load(dialog, url.href);
    }
  });

  // forms submit inside the modal (inline onsubmit handlers that return false keep control)
  dialog.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented || form.target || form.dataset.modalNative !== undefined) return;
    event.preventDefault();
    event.stopPropagation();
    const data = new FormData(form, event.submitter ?? undefined);
    const action = new URL(form.getAttribute('action') || dialog.dataset.url || location.href, document.baseURI);
    if ((form.getAttribute('method') ?? 'get').toLowerCase() === 'post') {
      load(dialog, action.href, { method: 'POST', body: data });
    } else {
      for (const [key, value] of data) action.searchParams.append(key, value);
      load(dialog, action.href);
    }
  });

  document.body.append(dialog);
  return dialog;
}

/** re-creates the (inert, parsed) scripts so they run; onPageInit callbacks wait for all of them */
async function runScripts(container) {
  const pending = [];
  const pageInit = window.onPageInit;
  window.onPageInit = (fn) => { pending.push(fn); };
  try {
    for (const old of [...container.querySelectorAll('script')]) {
      const script = document.createElement('script');
      for (const { name, value } of old.attributes) script.setAttribute(name, value);
      if (old.src) {
        const loaded = new Promise((resolve) => {
          script.addEventListener('load', resolve, { once: true });
          script.addEventListener('error', resolve, { once: true });
          setTimeout(resolve, 4000);
        });
        script.async = false;
        old.replaceWith(script);
        await loaded;
      } else {
        script.textContent = old.textContent;
        old.replaceWith(script);
      }
    }
    // inline module scripts run asynchronously right after insertion
    await new Promise((resolve) => { setTimeout(resolve, 0); });
  } finally {
    window.onPageInit = pageInit;
  }
  for (const fn of pending) fn();
}

async function load(dialog, url, init = {}) {
  const token = ++loadToken;
  const body = dialog.querySelector('.modal__body');
  body.setAttribute('aria-busy', 'true');
  dialog.classList.add('is-loading');
  if (!dialog.open) dialog.showModal();

  let response;
  let html;
  try {
    response = await fetch(url, { credentials: 'same-origin', ...init, headers: { 'X-Game-Fragment': '1', ...(init.headers ?? {}) } });
    html = await response.text();
  } catch {
    html = '<p class="modal__error">Network error</p>';
  }
  if (token !== loadToken) return; // closed or replaced meanwhile

  // a form that led to another page (e.g. after deleting a planet): follow it with the whole page
  if (response?.redirected && init.method === 'POST' && pageOf(response.url) !== pageOf(url)) {
    location.href = response.url;
    return;
  }

  const doc = new DOMParser().parseFromString(html, 'text/html');
  const root = doc.querySelector('[data-modal-root]') ?? doc.querySelector('#content') ?? doc.querySelector('main') ?? doc.body;
  body.replaceChildren(...[...root.childNodes].map((node) => document.importNode(node, true)));
  dialog.dataset.url = response?.url ?? url;
  dialog.classList.remove('is-loading');
  body.removeAttribute('aria-busy');
  body.scrollTop = 0;
  await runScripts(body);
  document.dispatchEvent(new CustomEvent('modal:load', { detail: { root: body } }));
}

export const Dialog = {
  /** a game page as modal content; width in px (optional), the height follows the content */
  open(url, width) {
    contentDialog ??= buildContentDialog();
    sizeFor(contentDialog, width);
    load(contentDialog, url);
    return false;
  },

  /** inline element (moved into a <dialog>, put back on close) or a <template> (cloned) as modal */
  show(element, { width } = {}) {
    if (!element) return false;
    const dialog = document.createElement('dialog');
    dialog.className = 'modal modal--inline';
    sizeFor(dialog, width);
    // the body scrolls, the dialog itself does not (the close button sits on its edge)
    dialog.innerHTML = `${CLOSE_BUTTON}<div class="modal__body"></div>`;
    const body = dialog.querySelector('.modal__body');
    closeOnBackdrop(dialog);

    if (element instanceof HTMLTemplateElement) {
      body.append(element.content.cloneNode(true));
      dialog.addEventListener('close', () => dialog.remove());
    } else {
      const placeholder = document.createComment('dialog-placeholder');
      element.before(placeholder);
      const previousDisplay = element.style.display;
      const wasHidden = element.hidden;
      element.style.display = '';
      element.hidden = false;
      body.append(element);
      dialog.addEventListener('close', () => {
        element.style.display = previousDisplay;
        element.hidden = wasHidden;
        placeholder.replaceWith(element);
        dialog.remove();
      });
    }
    document.body.append(dialog);
    dialog.showModal();
    return false;
  },

  /** closes the topmost dialog */
  close() {
    const open = Array.from(document.querySelectorAll('dialog[open]')).pop();
    open?.close();
    return false;
  },

  /** themed message box (text, not HTML); callback runs after it was closed */
  alert(message, callback) {
    const dialog = document.createElement('dialog');
    dialog.className = 'modal modal--alert';
    dialog.setAttribute('role', 'alertdialog');
    dialog.innerHTML = '<p class="modal__message"></p><div class="modal__actions"><button type="button" class="button" data-dialog-close autofocus>OK</button></div>';
    dialog.querySelector('.modal__message').textContent = typeof message === 'string' ? message : String(message ?? '');
    dialog.addEventListener('close', () => {
      dialog.remove();
      if (typeof callback === 'function') callback();
    });
    document.body.append(dialog);
    dialog.showModal();
    return false;
  },

  info(id) {
    return Dialog.open(`game?page=information&id=${id}`, 590);
  },

  PM(ids, subject = '', message = '', group = '') {
    const receivers = [].concat(ids).map((value) => `receivers[]=${encodeURIComponent(value)}`).join('&');
    const query = new URLSearchParams({
      subject: typeof subject === 'string' ? subject : '',
      message: typeof message === 'string' ? message : '',
      groupid: group ? String(group) : '',
    });
    return Dialog.open(`game?page=messages&mode=write&${receivers}&${query}`, 650);
  },

  Forward: (id) => Dialog.open(`game?page=messages&mode=forward&id=${id}`, 450),
  Playercard: (id) => (window.isPlayerCardActive === true || window.isPlayerCardActive === 'true')
    && Dialog.open(`game?page=playerCard&id=${id}`, 650),
  Buddy: (id) => Dialog.open(`game?page=buddyList&mode=request&id=${id}`, 650),
  PlanetAction: () => Dialog.open('game?page=overview&mode=actions', 400),
  /** achievement texts are inline <template>s on the achievements page */
  ach: (id) => Dialog.show(document.getElementById(`achievement-info-${id}`), { width: 480 }),
};

on(document, 'click', '[data-dialog-close]', (event) => {
  event.preventDefault();
  event.target.closest('dialog')?.close();
});

/** links with data-dialog="url" [data-dialog-width] open as modal */
on(document, 'click', '[data-dialog]', (event, link) => {
  event.preventDefault();
  Dialog.open(link.dataset.dialog || link.href, link.dataset.dialogWidth);
});

/**
 * battle reports (links with target="_blank" in messages, battle hall, expedition texts) open as modal
 * inside the game; ctrl/cmd/shift click and middle click still open a new tab
 */
on(document, 'click', 'a[href*="raport="]', (event, link) => {
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || !/\/game$/.test(location.pathname)) return;
  const url = new URL(link.getAttribute('href'), document.baseURI);
  const isReport = (url.searchParams.get('page') === 'raport' || /\/CombatReport$/.test(url.pathname)) && url.searchParams.has('raport');
  if (!isReport || url.origin !== location.origin) return;
  event.preventDefault();
  Dialog.open(url.href, 1100);
});

window.Dialog = Dialog;
