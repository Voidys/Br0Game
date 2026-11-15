/**
 * Tech tree: a click on a group header (.techb) opens or closes the boxes (.techi) that follow it, up to
 * the next header.
 */
import { on } from '../app/core.js';

on(document, 'click', '#techtree .techb', (event, header) => {
  const open = !header.classList.contains('tt-open');
  header.classList.toggle('tt-open', open);
  for (let element = header.nextElementSibling; element && !element.classList.contains('techb'); element = element.nextElementSibling) {
    if (element.classList.contains('techi')) {
      element.classList.toggle('tt-open', open);
    }
  }
});

