/**
 * Lightweight rich text editor (no dependencies). Enhances <textarea data-rte="rich|basic">:
 * toolbar + contenteditable area, the textarea stays the form field and is kept in sync.
 *
 *   rich   CMS pages, news: paragraph/h2/h3, bold, italic, strike, lists, quote, code block, link, hr, source view
 *   basic  board posts and comments: bold, italic, strike, lists, quote, code block, link
 *
 * Pasted HTML is reduced to the same tags; the server cleans everything again (App\Content\HtmlContent).
 * Usage: import { initRte } from '../app/rte.js'; initRte(root?) — or just load the module, it enhances
 * every [data-rte] on DOMContentLoaded. Styles: css/app/rte.css.
 */

const TOOLS = {
  p: { label: '¶', title: 'Paragraph', block: 'p', rich: true },
  h2: { label: 'H2', title: 'Heading', block: 'h2', rich: true },
  h3: { label: 'H3', title: 'Subheading', block: 'h3', rich: true },
  bold: { label: 'B', title: 'Bold (Ctrl+B)', command: 'bold', state: true, cls: 'rte-b' },
  italic: { label: 'I', title: 'Italic (Ctrl+I)', command: 'italic', state: true, cls: 'rte-i' },
  strike: { label: 'S', title: 'Strikethrough', command: 'strikeThrough', state: true, cls: 'rte-s' },
  ul: { label: '•', title: 'Bullet list', command: 'insertUnorderedList', state: true },
  ol: { label: '1.', title: 'Numbered list', command: 'insertOrderedList', state: true },
  quote: { label: '❝', title: 'Quote', block: 'blockquote' },
  code: { label: '</>', title: 'Code block', block: 'pre' },
  link: { label: 'Link', title: 'Link (Ctrl+K)', action: 'link', cls: 'rte-link' },
  unlink: { label: 'Link', title: 'Remove link', command: 'unlink', cls: 'rte-unlink' },
  hr: { label: '―', title: 'Divider', command: 'insertHorizontalRule', rich: true },
  clear: { label: 'Tx', title: 'Clear formatting', action: 'clear', cls: 'rte-clear' },
  source: { label: 'HTML', title: 'Edit HTML', action: 'source', rich: true },
};
const LAYOUT = {
  rich: [['p', 'h2', 'h3'], ['bold', 'italic', 'strike'], ['ul', 'ol', 'quote', 'code'], ['link', 'unlink', 'hr'], ['clear', 'source']],
  basic: [['bold', 'italic', 'strike'], ['ul', 'ol', 'quote', 'code'], ['link', 'unlink'], ['clear']],
};
const ALLOWED = {
  basic: new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'S', 'STRIKE', 'DEL', 'U', 'CODE', 'PRE', 'A', 'UL', 'OL', 'LI', 'BLOCKQUOTE']),
};
ALLOWED.rich = new Set([...ALLOWED.basic, 'H2', 'H3', 'H4', 'HR', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD']);

/** reduce HTML to the allowed tags (pasted content, source view) */
function cleanHtml(html, mode) {
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  const allowed = ALLOWED[mode];
  const walk = (node) => {
    for (const child of [...node.childNodes]) {
      if (child.nodeType === Node.COMMENT_NODE) { child.remove(); continue; }
      if (child.nodeType !== Node.ELEMENT_NODE) continue;
      walk(child);
      let tag = child.tagName;
      if (tag === 'H1') tag = 'H2';
      if ((tag === 'H2' || tag === 'H3' || tag === 'H4') && !allowed.has(tag)) tag = 'P';
      if (tag === 'DIV') tag = 'P';
      if (['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'TEMPLATE'].includes(tag)) { child.remove(); continue; }
      if (!allowed.has(tag)) { child.replaceWith(...child.childNodes); continue; }
      const clean = doc.createElement(tag);
      if (tag === 'A') {
        const href = child.getAttribute('href') ?? '';
        if (/^(https?:|mailto:|\/|\.\/|#)/i.test(href)) clean.setAttribute('href', href);
      }
      clean.append(...child.childNodes);
      child.replaceWith(clean);
    }
  };
  walk(doc.body);
  return doc.body.innerHTML.trim();
}

/** Toolbar button for a tool definition (label, tooltip and aria-label from `tool`). */
function button(name, tool) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = `rte__btn ${tool.cls ?? ''}`.trim();
  b.dataset.tool = name;
  b.textContent = tool.label;
  b.title = tool.title;
  b.setAttribute('aria-label', tool.title);
  return b;
}

/**
 * Turns one textarea[data-rte] into the editor: toolbar (layout by mode), contenteditable area (accessible name from the
 * textarea's label), HTML source toggle in rich mode, Ctrl+B/I/K shortcuts, paste cleaning. The textarea stays the form
 * field: it is hidden and receives the cleaned HTML on every change and before submit ('' for an empty
 * editor); form reset restores the original value. Runs once per textarea.
 */
function enhance(textarea) {
  if (textarea.dataset.rteReady) return;
  textarea.dataset.rteReady = '1';
  const mode = textarea.dataset.rte === 'rich' ? 'rich' : 'basic';

  const wrap = document.createElement('div');
  wrap.className = `rte rte--${mode}`;
  const toolbar = document.createElement('div');
  toolbar.className = 'rte__toolbar';
  toolbar.setAttribute('role', 'toolbar');
  for (const group of LAYOUT[mode]) {
    const g = document.createElement('span');
    g.className = 'rte__group';
    for (const name of group) g.append(button(name, TOOLS[name]));
    toolbar.append(g);
  }
  const area = document.createElement('div');
  area.className = 'rte__area prose';
  area.contentEditable = 'true';
  area.setAttribute('role', 'textbox');
  area.setAttribute('aria-multiline', 'true');
  const label = textarea.id && document.querySelector(`label[for="${textarea.id}"]`);
  if (label) area.setAttribute('aria-label', label.textContent.trim());
  if (textarea.placeholder) area.dataset.placeholder = textarea.placeholder;
  area.innerHTML = cleanHtml(textarea.value, mode) || '<p><br></p>';

  textarea.hidden = true;
  textarea.classList.add('rte__source');
  textarea.before(wrap);
  wrap.append(toolbar, area, textarea);

  let sourceMode = false;
  const sync = () => {
    if (sourceMode) return;
    const html = cleanHtml(area.innerHTML, mode);
    textarea.value = html === '<p><br></p>' || html === '<br>' ? '' : html;
    wrap.classList.toggle('is-empty', textarea.value === '');
  };
  const exec = (command, value = null) => {
    area.focus();
    document.execCommand(command, false, value);
    sync();
    updateState();
  };
  const updateState = () => {
    for (const b of toolbar.querySelectorAll('[data-tool]')) {
      const tool = TOOLS[b.dataset.tool];
      let active = false;
      try {
        if (tool.state) active = document.queryCommandState(tool.command);
        else if (tool.block) active = (document.queryCommandValue('formatBlock') || '').toLowerCase() === tool.block;
      } catch { /* not focused */ }
      b.classList.toggle('is-active', active && !sourceMode);
      b.disabled = sourceMode && b.dataset.tool !== 'source';
    }
  };
  const link = () => {
    const current = document.getSelection()?.anchorNode?.parentElement?.closest('a')?.getAttribute('href') ?? 'https://';
    const url = window.prompt(TOOLS.link.title, current);
    if (url === null) return;
    if (url === '' || url === 'https://') { exec('unlink'); return; }
    if (!/^(https?:|mailto:|\/|\.\/|#)/i.test(url)) { exec('createLink', `https://${url}`); return; }
    exec('createLink', url);
  };
  const toggleSource = () => {
    sourceMode = !sourceMode;
    wrap.classList.toggle('is-source', sourceMode);
    if (sourceMode) {
      sync();
      textarea.hidden = false;
      textarea.focus();
    } else {
      area.innerHTML = cleanHtml(textarea.value, mode) || '<p><br></p>';
      textarea.hidden = true;
      sync();
    }
    toolbar.querySelector('[data-tool="source"]')?.classList.toggle('is-active', sourceMode);
    updateState();
  };

  toolbar.addEventListener('mousedown', (event) => {
    if (event.target.closest('[data-tool]')) event.preventDefault(); // keep the selection in the area
  });
  toolbar.addEventListener('click', (event) => {
    const b = event.target.closest('[data-tool]');
    if (!b) return;
    const tool = TOOLS[b.dataset.tool];
    if (tool.action === 'link') link();
    else if (tool.action === 'source') toggleSource();
    else if (tool.action === 'clear') { exec('removeFormat'); exec('formatBlock', '<p>'); }
    else if (tool.block) {
      const current = (document.queryCommandValue('formatBlock') || '').toLowerCase();
      exec('formatBlock', `<${current === tool.block ? 'p' : tool.block}>`);
    } else exec(tool.command);
  });
  area.addEventListener('input', sync);
  area.addEventListener('keyup', updateState);
  area.addEventListener('mouseup', updateState);
  area.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); link(); }
  });
  area.addEventListener('paste', (event) => {
    const html = event.clipboardData?.getData('text/html');
    const text = event.clipboardData?.getData('text/plain') ?? '';
    event.preventDefault();
    if (html) document.execCommand('insertHTML', false, cleanHtml(html, mode));
    else document.execCommand('insertText', false, text);
    sync();
  });
  textarea.form?.addEventListener('submit', () => { if (sourceMode) toggleSource(); sync(); });
  textarea.form?.addEventListener('reset', () => setTimeout(() => { area.innerHTML = cleanHtml(textarea.value, mode) || '<p><br></p>'; sync(); }));

  document.execCommand('defaultParagraphSeparator', false, 'p');
  sync();
}

/** Enhances every textarea[data-rte] below `root` (also called automatically once the DOM is ready). */
export function initRte(root = document) {
  for (const textarea of root.querySelectorAll('textarea[data-rte]')) enhance(textarea);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => initRte(), { once: true });
} else {
  initRte();
}
