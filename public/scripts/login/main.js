/**
 * Start page: language select, menu toggle (narrow screens), universe start countdowns, universe
 * based form urls, screenshot viewer. Native ES module; LoginConfig is defined by layout.base.
 */
import '../app/csrf.js';
import { qs, qsa, on, ready, cookie } from '../app/core.js';

const Login = {
  setLanguage(lang, query) {
    cookie.set('lang', lang);
    document.location.href = document.location.href + (query ?? '');
  },
};

function fbLinks() {
  return qsa('.fb_login');
}

function updateUrls(select) {
  const universe = select.value;
  const form = select.closest('form');
  const config = window.LoginConfig;
  let base = config.basePath;
  if (config.unisWildcast && universe != 0) {
    base = config.basePath.replace('://', `://uni${universe}.`);
  } else if (universe != 0) {
    base = `${config.basePath}uni${universe}/`;
  }
  if (form) form.setAttribute('action', base + form.dataset.action);
  for (const link of fbLinks()) {
    link.setAttribute('href', base + link.dataset.href);
  }
}

/** minimal image viewer for the screenshots page (native <dialog>) */
function openGallery(links, index) {
  const dialog = document.createElement('dialog');
  dialog.className = 'modal modal--image';
  dialog.innerHTML = `
    <button type="button" class="modal__close" data-dialog-close aria-label="Close">&times;</button>
    <button type="button" class="modal__nav modal__nav--prev" aria-label="Previous">&lsaquo;</button>
    <img class="modal__image" alt="">
    <button type="button" class="modal__nav modal__nav--next" aria-label="Next">&rsaquo;</button>`;
  const img = qs('img', dialog);
  let current = index;
  const render = () => { img.src = links[current].href; };
  const step = (delta) => { current = (current + delta + links.length) % links.length; render(); };
  qs('.modal__nav--prev', dialog).addEventListener('click', () => step(-1));
  qs('.modal__nav--next', dialog).addEventListener('click', () => step(1));
  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft') step(-1);
    if (event.key === 'ArrowRight') step(1);
  });
  dialog.addEventListener('close', () => dialog.remove());
  document.body.append(dialog);
  render();
  dialog.showModal();
}

/** "In: 3d 04h 12m 09s" until the universe opens (texts from data attributes) */
function countdowns() {
  for (const element of qsa('.uni-countdown[data-start-timestamp]')) {
    const target = Number(element.dataset.startTimestamp);
    const render = () => {
      let rest = target - Math.floor(Date.now() / 1000);
      if (rest <= 0) {
        element.textContent = element.dataset.textDone ?? '';
        return false;
      }
      const days = Math.floor(rest / 86400); rest %= 86400;
      const hours = Math.floor(rest / 3600); rest %= 3600;
      const pad = (value) => String(value).padStart(2, '0');
      element.textContent = `${element.dataset.textIn ?? ''} ${days}d ${pad(hours)}h ${pad(Math.floor(rest / 60))}m ${pad(rest % 60)}s`.trim();
      return true;
    };
    if (render()) {
      const timer = setInterval(() => { if (!render()) clearInterval(timer); }, 1000);
    }
  }
}

ready(() => {
  on(document, 'change', '[data-language-select]', (event, select) => {
    const url = new URL(window.location.href);
    url.searchParams.set('lang', select.value);
    cookie.set('lang', select.value);
    window.location.href = url.toString();
  });

  // narrow screens: the toggle opens the menu; Escape, a click outside or a wider window closes it
  const menuButton = qs('[data-site-menu]');
  const setMenu = (open) => {
    document.body.classList.toggle('nav-open', open);
    menuButton?.setAttribute('aria-expanded', String(open));
  };
  on(document, 'click', '[data-site-menu]', () => setMenu(!document.body.classList.contains('nav-open')));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.body.classList.contains('nav-open')) {
      setMenu(false);
      menuButton?.focus();
    }
  });
  document.addEventListener('click', (event) => {
    if (document.body.classList.contains('nav-open') && !event.target.closest('.site-header')) setMenu(false);
  });
  window.matchMedia('(width > 60rem)').addEventListener('change', (event) => { if (event.matches) setMenu(false); });

  countdowns();

  on(document, 'click', 'a.gallery', (event, link) => {
    event.preventDefault();
    const links = qsa('a.gallery');
    openGallery(links, links.indexOf(link));
  });

  if (window.LoginConfig?.isMultiUniverse) {
    for (const select of qsa('.changeAction')) {
      updateUrls(select);
      select.addEventListener('change', () => updateUrls(select));
    }
  } else {
    for (const link of fbLinks()) {
      link.setAttribute('href', window.LoginConfig.basePath + link.dataset.href);
    }
  }
});

window.Login = Login;
