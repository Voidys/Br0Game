/*
 * Board (account?page=board): voting via fetch, thread collapse, inline reply/edit forms,
 * (delete confirmations are data-confirm buttons, asked by ../app/actions.js). Native ES module, no dependencies.
 */
import { initRte } from '../app/rte.js';

const root = document.querySelector('[data-board]');

if (root) {
  const csrf = root.dataset.csrf;
  const voteUrl = root.dataset.voteUrl;

  // -------------------------------------------------------------- voting
  root.addEventListener('click', async (event) => {
    const btn = event.target.closest('.board-vote__btn');
    if (!btn || btn.disabled) return;
    const box = btn.closest('[data-vote]');
    const current = Number(box.dataset.value);
    const dir = Number(btn.dataset.dir);
    const next = current === dir ? 0 : dir;

    const body = new URLSearchParams({ csrf, type: box.dataset.type, id: box.dataset.id, value: String(next) });
    box.classList.add('is-busy');
    try {
      const response = await fetch(voteUrl, { method: 'POST', body, credentials: 'same-origin' });
      const data = await response.json();
      if (!data.ok) throw new Error(data.error || 'error');
      box.dataset.value = String(data.value);
      box.querySelector('.board-vote__score').textContent = data.score;
      for (const b of box.querySelectorAll('.board-vote__btn')) {
        const on = Number(b.dataset.dir) === data.value;
        b.classList.toggle('is-on', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      }
    } catch (error) {
      box.classList.add('has-error');
      setTimeout(() => box.classList.remove('has-error'), 1500);
    } finally {
      box.classList.remove('is-busy');
    }
  });

  // -------------------------------------------------------------- thread collapse
  root.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-collapse]');
    if (!toggle) return;
    const comment = toggle.closest('[data-comment]');
    const depth = Number(comment.dataset.depth);
    const collapsed = comment.classList.toggle('is-collapsed');
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggle.textContent = collapsed ? '[+]' : '[−]';
    let cutoff = collapsed ? depth : Infinity;
    for (let el = comment.nextElementSibling; el?.matches('[data-comment]'); el = el.nextElementSibling) {
      const d = Number(el.dataset.depth);
      if (d <= depth) break;
      if (d > cutoff) {
        el.hidden = true;
        continue;
      }
      cutoff = el.classList.contains('is-collapsed') ? d : Infinity;
      el.hidden = false;
    }
  });

  // -------------------------------------------------------------- inline reply / edit
  const template = document.getElementById('board-reply-tpl');
  let openForm = null;

  function closeForm() {
    openForm?.remove();
    openForm = null;
  }

  function openFor(comment, edit) {
    closeForm();
    const form = template.content.firstElementChild.cloneNode(true);
    const content = comment.querySelector('.board-comment__content');
    const area = form.querySelector('textarea');
    if (edit) {
      form.querySelector('[name=mode]').value = 'saveComment';
      const idField = form.querySelector('[name=id]');
      idField.disabled = false;
      idField.value = comment.id.replace('c', '');
      form.querySelector('[name=parent_id]').disabled = true;
      form.querySelector('[name=post_id]').disabled = true;
      area.value = comment.querySelector('[data-comment-body]')?.innerHTML ?? '';
    } else {
      form.querySelector('[name=parent_id]').value = comment.id.replace('c', '');
    }
    form.querySelector('[data-cancel]').addEventListener('click', closeForm);
    content.append(form);
    initRte(form);
    form.querySelector('[contenteditable]')?.focus();
    openForm = form;
  }

  root.addEventListener('click', (event) => {
    const reply = event.target.closest('[data-reply]');
    const edit = event.target.closest('[data-edit]');
    if (!reply && !edit || !template) return;
    openFor((reply ?? edit).closest('[data-comment]'), Boolean(edit));
  });
}
