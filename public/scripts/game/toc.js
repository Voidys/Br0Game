/**
 * Table of contents for long text pages (rules): collects the h2 headings of the first `.rich-text` block, gives them ids
 * and inserts a link list in front of the text. Pages with fewer than four headings are left alone.
 */
const text = document.querySelector('.rich-text');
const headings = text ? [...text.querySelectorAll('h2')] : [];
if (text && headings.length >= 4) {
  const nav = document.createElement('nav');
  nav.className = 'toc';
  nav.setAttribute('aria-label', text.dataset.tocLabel || 'Contents');
  const list = document.createElement('ol');
  headings.forEach((heading, index) => {
    heading.id ||= `sec-${index + 1}`;
    const item = document.createElement('li');
    const link = document.createElement('a');
    link.href = `#${heading.id}`;
    link.textContent = heading.textContent.trim();
    item.append(link);
    list.append(item);
  });
  nav.append(list);
  text.before(nav);
}
