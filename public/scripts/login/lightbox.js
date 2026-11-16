/**
 * Image lightbox for the screenshot gallery (own modal, not the generic text dialog). Links `a.gallery` open their
 * full-size `href` in a near full-viewport <dialog class="lightbox">: dark backdrop, close button, Esc, backdrop click,
 * previous/next buttons, arrow keys and swipe within the gallery, "n / total" caption. <dialog>.showModal() traps the focus;
 * it goes back to the thumbnail on close. Labels come from data-lightbox-close/-prev/-next on the gallery container.
 */
import { qs, qsa, on } from '../app/core.js';

const SWIPE_DISTANCE = 50;

/** builds the dialog, shows the image of `links[index]` and wires the controls */
function open(links, index, labels) {
  const dialog = document.createElement('dialog');
  dialog.className = 'lightbox';
  dialog.setAttribute('aria-label', labels.close);
  dialog.innerHTML = `
    <button type="button" class="lightbox__btn lightbox__close" data-lb="close"></button>
    <button type="button" class="lightbox__btn lightbox__nav lightbox__nav--prev" data-lb="prev">&lsaquo;</button>
    <figure class="lightbox__figure"><img class="lightbox__image" alt=""><figcaption class="lightbox__caption"></figcaption></figure>
    <button type="button" class="lightbox__btn lightbox__nav lightbox__nav--next" data-lb="next">&rsaquo;</button>`;
  qs('[data-lb=close]', dialog).setAttribute('aria-label', labels.close);
  qs('[data-lb=prev]', dialog).setAttribute('aria-label', labels.prev);
  qs('[data-lb=next]', dialog).setAttribute('aria-label', labels.next);
  qs('[data-lb=close]', dialog).textContent = '×';
  const image = qs('img', dialog);
  const caption = qs('figcaption', dialog);
  let current = index;

  const render = () => {
    const link = links[current];
    const thumb = qs('img', link);
    image.src = link.href;
    image.alt = thumb?.alt ?? '';
    caption.textContent = `${current + 1} / ${links.length}`;
    dialog.classList.toggle('lightbox--single', links.length < 2);
  };
  const step = (delta) => {
    current = (current + delta + links.length) % links.length;
    render();
  };

  dialog.addEventListener('click', (event) => {
    const action = event.target.closest('[data-lb]')?.dataset.lb;
    if (action === 'close' || event.target === dialog) dialog.close();
    if (action === 'prev') step(-1);
    if (action === 'next') step(1);
  });
  dialog.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft') step(-1);
    if (event.key === 'ArrowRight') step(1);
  });

  let startX = null;
  dialog.addEventListener('pointerdown', (event) => { startX = event.pointerType === 'mouse' ? null : event.clientX; });
  dialog.addEventListener('pointerup', (event) => {
    if (startX !== null && Math.abs(event.clientX - startX) > SWIPE_DISTANCE) step(event.clientX < startX ? 1 : -1);
    startX = null;
  });

  dialog.addEventListener('close', () => {
    dialog.remove();
    document.documentElement.classList.remove('lightbox-open');
    links[current]?.focus({ preventScroll: true });
  });
  document.body.append(dialog);
  document.documentElement.classList.add('lightbox-open');
  render();
  dialog.showModal();
  qs('[data-lb=close]', dialog).focus();
}

/** delegated: clicking an `a.gallery` opens the lightbox over all galleries links of the page */
export function initLightbox() {
  on(document, 'click', 'a.gallery', (event, link) => {
    event.preventDefault();
    const links = qsa('a.gallery');
    const holder = link.closest('[data-lightbox-close]')?.dataset ?? {};
    open(links, links.indexOf(link), {
      close: holder.lightboxClose ?? 'Close',
      prev: holder.lightboxPrev ?? 'Previous',
      next: holder.lightboxNext ?? 'Next',
    });
  });
}
