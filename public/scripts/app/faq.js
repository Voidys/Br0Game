/**
 * FAQ page script (templates/game/page.questions.*.html.twig). Two modes, picked by the markup:
 *  - index ([data-faq-index]): live search over titles AND body text with highlighted hits, result count in an
 *    aria-live status, "no results" state, "/" focuses the search, Esc clears it, the query lives in the URL (?q=)
 *  - article ([data-faq-article]): copy-link buttons on the title and every heading, highlights the ?q= terms from
 *    the search, marks the current heading in the table of contents, "/" jumps to the search.
 * Data comes from two JSON islands: #faq-index (entries: c = category id, i = entry id, t = title, x = plain text,
 * u = URL; categories: id, name, icon) and #faq-i18n (texts). No framework; text goes in through textContent only.
 */
import { qs, qsa, pageData } from './core.js';

const FOLD_MARKS = /[̀-ͯ]/g;
const SVG_NS = 'http://www.w3.org/2000/svg';

const isEditable = (el) => el instanceof HTMLElement
  && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));

/** lower-case, accent-free copy of a text plus the position of every folded character in the original */
function fold(text) {
  let folded = '';
  const map = [];
  let index = 0;
  for (const char of text) {
    const plain = char.normalize('NFD').replace(FOLD_MARKS, '').toLowerCase();
    for (let k = 0; k < plain.length; k += 1) {
      map.push(index);
    }
    folded += plain;
    index += char.length;
  }
  map.push(index);
  return { folded, map };
}

function terms(query) {
  return fold(query.trim()).folded.split(/\s+/).filter(Boolean);
}

/** [start, end) ranges of the original text where one of the terms matches (merged, sorted) */
function hitRanges(text, wanted) {
  const { folded, map } = fold(text);
  const ranges = [];
  for (const term of wanted) {
    let from = 0;
    for (;;) {
      const at = folded.indexOf(term, from);
      if (at === -1) break;
      ranges.push([map[at], map[at + term.length]]);
      from = at + term.length;
    }
  }
  ranges.sort((a, b) => a[0] - b[0]);
  const merged = [];
  for (const range of ranges) {
    const last = merged[merged.length - 1];
    if (last && range[0] <= last[1]) {
      last[1] = Math.max(last[1], range[1]);
    } else {
      merged.push([...range]);
    }
  }
  return merged;
}

/** appends text to a node, wrapping the matched ranges in <mark> */
function appendHighlighted(parent, text, wanted) {
  let cursor = 0;
  for (const [start, end] of hitRanges(text, wanted)) {
    if (start > cursor) parent.append(document.createTextNode(text.slice(cursor, start)));
    const mark = document.createElement('mark');
    mark.textContent = text.slice(start, end);
    parent.append(mark);
    cursor = end;
  }
  if (cursor < text.length) parent.append(document.createTextNode(text.slice(cursor)));
}

function icon(name, className = '') {
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('class', `icon ${className}`.trim());
  svg.setAttribute('aria-hidden', 'true');
  const use = document.createElementNS(SVG_NS, 'use');
  const sprite = qs('.faq-cat__icon use, .faq-article__cat use')?.getAttribute('href')?.split('#')[0]
    ?? './styles/resource/icons/lucide.svg';
  use.setAttribute('href', `${sprite}#i-${name}`);
  svg.append(use);
  return svg;
}

const format = (text, values) => text.replace(/\{(\w+)\}/g, (_, key) => String(values[key] ?? ''));

/* ------------------------------------------------------------------ index */

function initIndex(root) {
  const data = pageData('faq-index');
  const i18n = pageData('faq-i18n');
  const input = qs('[data-faq-input]', root);
  const clear = qs('[data-faq-clear]', root);
  const status = qs('[data-faq-status]', root);
  const results = qs('[data-faq-results]', root);
  const categories = qs('[data-faq-categories]', root);
  if (!input || !results || !categories) return;

  const categoryById = new Map((data.categories ?? []).map((category) => [category.id, category]));
  const entries = (data.entries ?? []).map((entry, order) => ({
    ...entry,
    order,
    title: fold(entry.t).folded,
    body: fold(entry.x).folded,
  }));

  function search(query) {
    const wanted = terms(query);
    const found = [];
    for (const entry of entries) {
      let titleHits = 0;
      let bodyHits = 0;
      let all = true;
      for (const term of wanted) {
        const inTitle = entry.title.includes(term);
        const inBody = entry.body.includes(term);
        if (!inTitle && !inBody) { all = false; break; }
        if (inTitle) titleHits += 1;
        if (inBody) bodyHits += 1;
      }
      if (all) {
        const exact = entry.title.startsWith(wanted[0]) ? 2 : 0;
        found.push({ entry, score: titleHits * 10 + exact + Math.min(bodyHits, 5) });
      }
    }
    found.sort((a, b) => b.score - a.score || a.entry.order - b.entry.order);
    return { wanted, found: found.map((item) => item.entry) };
  }

  function snippet(entry, wanted) {
    const ranges = hitRanges(entry.x, wanted);
    if (!ranges.length) return entry.x.slice(0, 140);
    const [start] = ranges[0];
    const from = Math.max(0, start - 55);
    const to = Math.min(entry.x.length, start + 120);
    return `${from > 0 ? '…' : ''}${entry.x.slice(from, to).trim()}${to < entry.x.length ? '…' : ''}`;
  }

  function resultItem(entry, wanted, query) {
    const item = document.createElement('li');
    item.className = 'faq-result';

    const link = document.createElement('a');
    link.className = 'faq-result__link';
    link.href = `${entry.u}?q=${encodeURIComponent(query)}`;
    appendHighlighted(link, entry.t, wanted);

    const category = categoryById.get(entry.c);
    const meta = document.createElement('p');
    meta.className = 'faq-result__cat';
    if (category) {
      meta.append(icon(category.icon), document.createTextNode(category.name));
    }

    const text = document.createElement('p');
    text.className = 'faq-result__snippet';
    appendHighlighted(text, snippet(entry, wanted), wanted);

    item.append(link, meta, text);
    return item;
  }

  function emptyState(query) {
    const box = document.createElement('li');
    box.className = 'faq-empty';
    const title = document.createElement('strong');
    title.textContent = format(i18n.noResults ?? '', { q: query });
    const hint = document.createElement('p');
    hint.textContent = i18n.noResultsHint ?? '';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'faq-btn';
    button.textContent = i18n.showAll ?? '';
    button.addEventListener('click', () => { setQuery(''); input.focus(); });
    box.append(title, hint, button);
    return box;
  }

  let lastQuery = null;

  function render(query) {
    if (query === lastQuery) return;
    lastQuery = query;
    clear.hidden = query === '';
    const active = terms(query).length > 0;
    categories.hidden = active;
    results.hidden = !active;
    results.replaceChildren();
    if (!active) {
      status.textContent = '';
      return;
    }
    const { wanted, found } = search(query);
    if (!found.length) {
      results.append(emptyState(query.trim()));
      status.textContent = format(i18n.noResults ?? '', { q: query.trim() });
      return;
    }
    results.append(...found.map((entry) => resultItem(entry, wanted, query.trim())));
    status.textContent = found.length === 1
      ? (i18n.resultsOne ?? '')
      : format(i18n.resultsMany ?? '', { n: found.length });
  }

  function writeUrl(query) {
    const url = new URL(location.href);
    if (query.trim()) url.searchParams.set('q', query.trim());
    else url.searchParams.delete('q');
    if (url.hash === '#search') url.hash = '';
    history.replaceState(history.state, '', url);
  }

  let urlTimer = 0;

  function setQuery(query) {
    input.value = query;
    render(query);
    writeUrl(query);
  }

  input.addEventListener('input', () => {
    render(input.value);
    clearTimeout(urlTimer);
    urlTimer = setTimeout(() => writeUrl(input.value), 250);
  });

  clear.addEventListener('click', () => { setQuery(''); input.focus(); });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      if (input.value) setQuery('');
      else input.blur();
    } else if (event.key === 'Enter') {
      event.preventDefault();
      qs('.faq-result__link', results)?.click();
    }
  });

  qs('form', root)?.addEventListener('submit', (event) => event.preventDefault());

  document.addEventListener('keydown', (event) => {
    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !isEditable(event.target)) {
      event.preventDefault();
      input.focus();
      input.select();
    }
  });

  const params = new URLSearchParams(location.search);
  input.value = params.get('q') ?? '';
  render(input.value);
  if (location.hash === '#search') input.focus();
}

/* ------------------------------------------------------------------ article */

function initArticle(root) {
  const i18n = pageData('faq-i18n');
  const live = qs('[data-faq-status]', root);
  const body = qs('[data-faq-body]', root);
  if (!body) return;

  /* copy-link buttons: the page title and every heading with an id */
  async function copy(url, button) {
    try {
      await navigator.clipboard.writeText(url);
    } catch {
      const area = document.createElement('textarea');
      area.value = url;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.append(area);
      area.select();
      try { document.execCommand('copy'); } catch { /* the address bar still has the link */ }
      area.remove();
    }
    button.classList.add('is-copied');
    button.querySelector('use')?.setAttribute('href', button.querySelector('use').getAttribute('href').replace(/#i-.*$/, '#i-check'));
    if (live) live.textContent = i18n.linkCopied ?? '';
    setTimeout(() => {
      button.classList.remove('is-copied');
      button.querySelector('use')?.setAttribute('href', button.querySelector('use').getAttribute('href').replace(/#i-.*$/, '#i-link'));
      if (live) live.textContent = '';
    }, 2200);
  }

  function addCopyButton(heading, hash) {
    const wrap = document.createElement('div');
    wrap.className = 'faq-heading';
    heading.replaceWith(wrap);
    wrap.append(heading);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'faq-anchor';
    button.setAttribute('aria-label', i18n.copyLink ?? 'Copy link');
    button.title = i18n.copyLink ?? 'Copy link';
    button.append(icon('link'));
    button.addEventListener('click', () => {
      const url = new URL(location.href);
      url.search = '';
      url.hash = hash;
      history.replaceState(history.state, '', hash ? `#${hash}` : location.pathname + location.search);
      copy(url.href, button);
    });
    wrap.append(button);
  }

  const title = qs('[data-faq-title]', root);
  if (title) addCopyButton(title, '');
  qsa('h2[id]', body).forEach((heading) => addCopyButton(heading, heading.id));

  /* terms of the search that led here: highlight and scroll to the first hit */
  const query = new URLSearchParams(location.search).get('q') ?? '';
  const wanted = terms(query);
  if (wanted.length) {
    const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT, {
      acceptNode: (node) => (node.parentElement?.closest('mark, button, script, style')
        ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
    });
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    for (const node of nodes) {
      if (!hitRanges(node.nodeValue, wanted).length) continue;
      const fragment = document.createDocumentFragment();
      appendHighlighted(fragment, node.nodeValue, wanted);
      node.replaceWith(fragment);
    }
    if (!location.hash) {
      qs('mark', body)?.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }
    // the breadcrumb back to the FAQ keeps the search
    qsa('[data-faq-index-link]', root).forEach((link) => {
      link.href = `${link.getAttribute('href').split('?')[0]}?q=${encodeURIComponent(query.trim())}`;
    });
  }

  /* current heading in the table of contents */
  const tocLinks = qsa('[data-faq-toc] a', root);
  if (tocLinks.length && 'IntersectionObserver' in window) {
    const byId = new Map(tocLinks.map((link) => [link.getAttribute('href').slice(1), link]));
    const visible = new Set();
    const observer = new IntersectionObserver((changes) => {
      for (const change of changes) {
        if (change.isIntersecting) visible.add(change.target.id);
        else visible.delete(change.target.id);
      }
      const current = [...byId.keys()].find((id) => visible.has(id));
      tocLinks.forEach((link) => link.removeAttribute('aria-current'));
      if (current) byId.get(current).setAttribute('aria-current', 'true');
    }, { rootMargin: '-15% 0px -70% 0px' });
    byId.forEach((_, id) => { const heading = document.getElementById(id); if (heading) observer.observe(heading); });
  }

  /* "/" opens the search on the index */
  const indexUrl = qs('[data-faq-index-link]', root)?.getAttribute('href')?.split('?')[0];
  document.addEventListener('keydown', (event) => {
    if (indexUrl && event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !isEditable(event.target)) {
      event.preventDefault();
      location.assign(`${indexUrl}#search`);
    }
  });
}

const index = qs('[data-faq-index]');
const article = qs('[data-faq-article]');
if (index) initIndex(index);
if (article) initArticle(article);
