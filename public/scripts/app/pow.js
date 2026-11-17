/**
 * Bot check of the public forms (registration, lost password): self-hosted proof of work, no interaction and no
 * third party. A form with data-pow="<challenge url>" gets its challenge once it becomes visible or focused; a Web
 * Worker (main thread as fallback) hashes sha256(salt + N) until it matches, the solution goes into the hidden field
 * "pow" on submit (see App\Security\ProofOfWork). Submit waits for the solution and for the minimum fill time of the
 * challenge; a short "verifying" note shows only when that takes long. A solution is single use, so every submit after
 * a refused one (back button, bfcache) gets a new one. Texts: data-pow-text (verifying), data-pow-error (no crypto).
 * Without JavaScript the server refuses the form (honeypot field "website" must stay empty as well).
 */
import { qsa, ready } from './core.js';
import { solve } from './pow-solver.js';

const SLOW_MS = 600;
/** a challenge lives 10 minutes on the server; older solutions are replaced */
const MAX_AGE_MS = 8 * 60 * 1000;
const forms = new WeakMap();

/** solves in a Web Worker; falls back to this thread when workers are unavailable or fail */
function solveChallenge(challenge) {
  return new Promise((resolve, reject) => {
    const fallback = () => solve(challenge).then(resolve, reject);
    let worker;
    try {
      worker = new Worker(new URL('./pow-worker.js', import.meta.url), { type: 'module' });
    } catch {
      fallback();
      return;
    }
    worker.onmessage = (event) => {
      worker.terminate();
      if (event.data.error) reject(new Error(event.data.error));
      else resolve(event.data.number);
    };
    worker.onerror = () => {
      worker.terminate();
      fallback();
    };
    worker.postMessage(challenge);
  });
}

/** fetches and solves one challenge; resolves {payload, notBefore, at} */
async function obtain(url) {
  const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
  if (!response.ok) throw new Error('challenge');
  const challenge = await response.json();
  const fetched = Date.now();
  const number = await solveChallenge(challenge);
  const payload = btoa(JSON.stringify({
    salt: challenge.salt,
    challenge: challenge.challenge,
    signature: challenge.signature,
    number,
  }));
  return { payload, notBefore: fetched + challenge.wait * 1000 + 300, at: fetched, used: false };
}

/** starts solving for a form unless a usable solution is already underway */
function begin(form, force = false) {
  const current = forms.get(form);
  if (!force && current && !current.used && Date.now() - current.started < MAX_AGE_MS) return current;
  const state = { started: Date.now(), used: false };
  state.promise = obtain(form.dataset.pow);
  state.promise.catch(() => {});
  forms.set(form, state);
  return state;
}

function setStatus(form, text) {
  const note = form.querySelector('[data-pow-status]');
  if (!note) return;
  note.textContent = text;
  note.hidden = text === '';
}

async function submit(form, submitter) {
  const timer = setTimeout(() => setStatus(form, form.dataset.powText ?? ''), SLOW_MS);
  try {
    const state = begin(form);
    const solution = await state.promise;
    const wait = solution.notBefore - Date.now();
    if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait));
    state.used = true;
    form.querySelector('input[name="pow"]').value = solution.payload;
    form.dataset.powGo = '1';
    setStatus(form, '');
    form.requestSubmit(submitter ?? undefined);
  } catch (error) {
    forms.delete(form);
    setStatus(form, error.message === 'nocrypto' ? (form.dataset.powError ?? '') : (form.dataset.powText ?? ''));
    delete form.dataset.powGo;
  } finally {
    clearTimeout(timer);
    delete form.dataset.powBusy;
  }
}

function init(form) {
  const field = form.querySelector('input[name="pow"]');
  if (!field) return;
  field.value = '';
  const start = () => { begin(form).promise.catch(() => {}); };
  form.addEventListener('focusin', start, { once: true });
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        observer.disconnect();
        start();
      }
    });
    observer.observe(form);
  } else {
    start();
  }
  form.addEventListener('submit', (event) => {
    if (form.dataset.powGo) {
      delete form.dataset.powGo;
      return;
    }
    event.preventDefault();
    if (form.dataset.powBusy) return;
    form.dataset.powBusy = '1';
    submit(form, event.submitter);
  });
}

ready(() => {
  qsa('form[data-pow]').forEach(init);
  // restored from the back/forward cache: the old solution is used up
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    qsa('form[data-pow]').forEach((form) => {
      delete form.dataset.powBusy;
      delete form.dataset.powGo;
      setStatus(form, '');
      begin(form, true);
    });
  });
});
