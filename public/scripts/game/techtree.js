/**
 * Tech tree: tabs per category, SVG connectors between the cards, hover/focus highlighting of the whole
 * requirement chain (ancestors) and the unlocks (descendants), click to pin, detail panel below the graph.
 * Data: the `techtree-data` island (nodes with requirements, labels); the cards are rendered by the template.
 */
import { pageData, ready } from '../app/core.js';

const SVG_NS = 'http://www.w3.org/2000/svg';
const STORAGE_KEY = 'techtree-tab';
const data = pageData('techtree-data');
const nodes = data.nodes || {};
const labels = data.labels || {};

/** id -> ids of the nodes that need it (global, for the "unlocks" list) */
const dependents = {};
for (const node of Object.values(nodes)) {
  for (const requirement of node.requirements) {
    (dependents[requirement.id] ||= []).push(node.id);
  }
}

const state = { panel: null, pinned: null, hovered: null };
const graphs = new Map(); // panel element -> { canvas, svg, edges: [{from, to, level, met, path, label}], cells: Map(id -> cell) }

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function svgEl(tag, attributes) {
  const node = document.createElementNS(SVG_NS, tag);
  for (const [key, value] of Object.entries(attributes || {})) node.setAttribute(key, value);
  return node;
}

/** All elements an element transitively requires (global graph, memoised). */
const ancestorCache = new Map();
function ancestorsOf(id) {
  if (!ancestorCache.has(id)) {
    const found = new Set();
    for (const requirement of nodes[id].requirements) {
      found.add(requirement.id);
      for (const up of ancestorsOf(requirement.id)) found.add(up);
    }
    ancestorCache.set(id, found);
  }
  return ancestorCache.get(id);
}

/**
 * Builds the edges of a panel from the cards that are present. `all` holds every requirement between visible cards
 * (used to trace chains); `edges` is the transitive reduction that is drawn: A -> C is skipped when another visible
 * requirement B of C already needs A.
 */
function buildGraph(panel) {
  const canvas = panel.querySelector('.tt-canvas');
  const cells = new Map();
  for (const cell of canvas.querySelectorAll('.tt-cell')) cells.set(Number(cell.dataset.id), cell);

  const svg = svgEl('svg', { class: 'tt-lines', 'aria-hidden': 'true' });
  canvas.prepend(svg);
  const all = [];
  const edges = [];
  for (const [id] of cells) {
    const visible = nodes[id].requirements.filter((r) => cells.has(r.id));
    for (const requirement of visible) {
      all.push({ from: requirement.id, to: id });
      if (visible.some((other) => other.id !== requirement.id && ancestorsOf(other.id).has(requirement.id))) continue;
      const path = svgEl('path', { class: 'tt-edge' + (requirement.met ? ' tt-met' : ' tt-unmet') });
      const label = svgEl('g', { class: 'tt-edge-label' });
      const pill = svgEl('rect', { rx: 7, height: 14 });
      const text = svgEl('text', { 'text-anchor': 'middle', y: 10.5 });
      text.textContent = String(requirement.level);
      label.append(pill, text);
      svg.append(path);
      edges.push({ from: requirement.id, to: id, level: requirement.level, met: requirement.met, path, label, pill, text });
    }
  }
  for (const edge of edges) svg.append(edge.label);
  const graph = { canvas, svg, edges, all, cells };
  graphs.set(panel, graph);
  return graph;
}

/** Gap columns of a row (between and beside its cards) as x positions. */
function gapColumns(boxes) {
  const sorted = boxes.slice().sort((a, b) => a.x - b.x);
  const xs = [Math.max(3, sorted[0].x - 4)];
  for (let i = 1; i < sorted.length; i++) xs.push((sorted[i - 1].x + sorted[i - 1].w + sorted[i].x) / 2);
  const last = sorted[sorted.length - 1];
  xs.push(last.x + last.w + 4);
  return xs;
}

/** Path through the gap columns of the rows an edge skips, with vertical tangents at every waypoint. */
function edgePath(points) {
  let d = `M${points[0].x} ${points[0].y}`;
  for (let i = 1; i < points.length; i++) {
    const a = points[i - 1];
    const b = points[i];
    if (a.straight) {
      d += `L${b.x} ${b.y}`;
    } else {
      const bend = (b.y - a.y) / 2;
      d += `C${a.x} ${a.y + bend} ${b.x} ${b.y - bend} ${b.x} ${b.y}`;
    }
  }
  return d;
}

/** Spread the attachment points of the edges along the card edge and route skipping edges through row gaps. */
function layoutEdges(graph) {
  const origin = graph.canvas.getBoundingClientRect();
  const box = new Map();
  // the cards wrap into several visual lines per depth (CSS flex-wrap): group them by their top edge
  const lines = [];
  for (const [id, cell] of graph.cells) {
    const rect = cell.querySelector('.tt-node').getBoundingClientRect();
    const b = { id, x: rect.left - origin.left, y: rect.top - origin.top, w: rect.width, h: rect.height, cx: rect.left - origin.left + rect.width / 2 };
    box.set(id, b);
    const line = lines.find((l) => Math.abs(l.y - b.y) < 4);
    if (line) line.boxes.push(b);
    else lines.push({ y: b.y, boxes: [b] });
  }
  lines.sort((a, b) => a.y - b.y);
  const lineOf = new Map();
  const rowInfo = new Map();
  lines.forEach((line, index) => {
    for (const b of line.boxes) lineOf.set(b.id, index);
    rowInfo.set(index, {
      top: Math.min(...line.boxes.map((b) => b.y)),
      bottom: Math.max(...line.boxes.map((b) => b.y + b.h)),
      gaps: gapColumns(line.boxes),
    });
  });
  const outgoing = new Map();
  const incoming = new Map();
  for (const edge of graph.edges) {
    (outgoing.get(edge.from) || outgoing.set(edge.from, []).get(edge.from)).push(edge);
    (incoming.get(edge.to) || incoming.set(edge.to, []).get(edge.to)).push(edge);
  }
  const slot = (map, key, other) => {
    const list = map.get(key).slice().sort((a, b) => box.get(other(a)).cx - box.get(other(b)).cx);
    return new Map(list.map((edge, i) => [edge, (i + 1) / (list.length + 1)]));
  };
  const outSlots = new Map([...outgoing.keys()].map((id) => [id, slot(outgoing, id, (e) => e.to)]));
  const inSlots = new Map([...incoming.keys()].map((id) => [id, slot(incoming, id, (e) => e.from)]));

  for (const edge of graph.edges) {
    const a = box.get(edge.from);
    const b = box.get(edge.to);
    const start = { x: a.x + a.w * outSlots.get(edge.from).get(edge), y: a.y + a.h };
    const end = { x: b.x + b.w * inSlots.get(edge.to).get(edge), y: b.y };
    const points = [start];
    const fromRow = lineOf.get(edge.from);
    const toRow = lineOf.get(edge.to);
    for (let row = fromRow + 1; row < toRow; row++) {
      const info = rowInfo.get(row);
      const t = ((info.top + info.bottom) / 2 - start.y) / (end.y - start.y);
      const wanted = start.x + (end.x - start.x) * t;
      const gap = info.gaps.reduce((best, x) => (Math.abs(x - wanted) < Math.abs(best - wanted) ? x : best), info.gaps[0]);
      points.push({ x: gap, y: info.top - 5 }, { x: gap, y: info.bottom + 5, straight: true });
      points[points.length - 2].straight = false;
    }
    points.push(end);
    edge.path.setAttribute('d', edgePath(points));
    const width = 8 + 6 * String(edge.level).length;
    edge.pill.setAttribute('width', String(width));
    edge.pill.setAttribute('x', String(-width / 2));
    edge.text.setAttribute('x', '0');
    edge.label.setAttribute('transform', `translate(${end.x} ${end.y - 20})`);
  }
  graph.svg.setAttribute('width', String(graph.canvas.scrollWidth));
  graph.svg.setAttribute('height', String(graph.canvas.scrollHeight));
}

let frame = 0;
function redraw() {
  if (frame) return;
  frame = requestAnimationFrame(() => {
    frame = 0;
    const graph = state.panel && graphs.get(state.panel);
    if (graph) layoutEdges(graph);
  });
}

/** Ancestors and descendants of `id` over all requirements between the visible cards. */
function chain(graph, id) {
  const walk = (key, next) => {
    const found = new Set();
    const stack = [id];
    while (stack.length) {
      const current = stack.pop();
      for (const edge of graph.all) {
        if (edge[key] === current && !found.has(edge[next])) {
          found.add(edge[next]);
          stack.push(edge[next]);
        }
      }
    }
    return found;
  };
  return { id, up: walk('to', 'from'), down: walk('from', 'to') };
}

function highlight() {
  const graph = state.panel && graphs.get(state.panel);
  if (!graph) return;
  const id = state.pinned ?? state.hovered;
  const selection = id !== null && graph.cells.has(id) ? chain(graph, id) : null;
  graph.canvas.classList.toggle('tt-focus', Boolean(selection));
  for (const [cellId, cell] of graph.cells) {
    const on = Boolean(selection) && (cellId === id || selection.up.has(cellId) || selection.down.has(cellId));
    cell.classList.toggle('tt-on', on);
    cell.querySelector('.tt-node').setAttribute('aria-pressed', String(cellId === state.pinned));
  }
  for (const edge of graph.edges) {
    let on = false;
    if (selection) {
      const upper = selection.up;
      const lower = selection.down;
      on = ((edge.to === id || upper.has(edge.to)) && upper.has(edge.from)) || ((edge.from === id || lower.has(edge.from)) && lower.has(edge.to));
    }
    edge.path.classList.toggle('tt-on', on);
    edge.label.classList.toggle('tt-on', on);
  }
}

function chips(parent, items) {
  if (!items.length) {
    parent.append(el('span', 'tt-none', labels.none));
    return;
  }
  for (const item of items) {
    const chip = el('a', 'tt-chip' + (item.met === undefined ? '' : item.met ? ' colorPositive' : ' colorNeutral'));
    chip.href = '#';
    chip.dataset.action = 'dialog-info';
    chip.dataset.id = String(item.id);
    chip.textContent = item.text;
    parent.append(chip);
  }
}

function renderDetail() {
  const detail = document.getElementById('tt-detail');
  const id = state.pinned ?? state.hovered;
  detail.replaceChildren();
  if (id === null) {
    detail.append(el('p', 'tt-hint', labels.hint));
    return;
  }
  const node = nodes[id];
  const head = el('div', 'tt-detail-head');
  head.append(el('strong', 'tt-detail-name', node.name));
  head.append(el('span', 'tt-state tt-state-' + node.status, `${labels.status[node.status]}${node.level > 0 ? ` · ${labels.level} ${node.level}` : ''}`));
  detail.append(head);

  const needs = el('div', 'tt-detail-list');
  needs.append(el('span', 'tt-detail-title', labels.requirements + ':'));
  chips(needs, node.requirements.map((r) => ({ id: r.id, met: r.met, text: `${nodes[r.id].name} (${labels.level} ${r.own}/${r.level})` })));
  detail.append(needs);

  const unlocks = el('div', 'tt-detail-list');
  unlocks.append(el('span', 'tt-detail-title', labels.unlocks + ':'));
  chips(unlocks, (dependents[id] || []).map((dep) => ({ id: dep, text: nodes[dep].name })));
  detail.append(unlocks);
}

function select(id, pin) {
  if (pin) state.pinned = id;
  else state.hovered = id;
  highlight();
  renderDetail();
}

function showPanel(group, focusTab) {
  const root = document.getElementById('tt-root');
  for (const tab of root.querySelectorAll('.tt-tab')) {
    const active = tab.dataset.group === group;
    tab.setAttribute('aria-selected', String(active));
    tab.tabIndex = active ? 0 : -1;
    if (active && focusTab) tab.focus();
  }
  for (const panel of root.querySelectorAll('.tt-panel')) {
    panel.hidden = panel.dataset.group !== group;
    if (!panel.hidden) state.panel = panel;
  }
  state.pinned = null;
  state.hovered = null;
  if (!graphs.has(state.panel)) buildGraph(state.panel);
  layoutEdges(graphs.get(state.panel));
  highlight();
  renderDetail();
  try { localStorage.setItem(STORAGE_KEY, group); } catch (error) { /* storage unavailable */ }
}

ready(() => {
  const root = document.getElementById('tt-root');
  if (!root || !Object.keys(nodes).length) return;
  const tabs = [...root.querySelectorAll('.tt-tab')];
  let start = tabs[0].dataset.group;
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (tabs.some((tab) => tab.dataset.group === saved)) start = saved;
  } catch (error) { /* storage unavailable */ }

  root.addEventListener('click', (event) => {
    const tab = event.target.closest('.tt-tab');
    if (tab) {
      showPanel(tab.dataset.group, false);
      return;
    }
    const card = event.target.closest('.tt-node');
    if (card) {
      const id = Number(card.dataset.id);
      select(state.pinned === id ? null : id, true);
    } else if (!event.target.closest('.tt-detail, .tt-info')) {
      select(null, true);
    }
  });
  root.addEventListener('keydown', (event) => {
    const card = event.target.closest('.tt-node');
    if (card && (event.key === 'Enter' || event.key === ' ')) {
      card.click();
      event.preventDefault();
      return;
    }
    const tab = event.target.closest('.tt-tab');
    if (tab && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
      const next = tabs[(tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      showPanel(next.dataset.group, true);
      event.preventDefault();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && state.pinned !== null) select(null, true);
  });
  // hover and keyboard focus highlight (pointerover/out bubble, unlike mouseenter)
  root.addEventListener('pointerover', (event) => {
    const card = event.target.closest('.tt-node');
    if (card && event.pointerType !== 'touch') select(Number(card.dataset.id), false);
  });
  root.addEventListener('pointerout', (event) => {
    if (event.target.closest('.tt-node') && event.pointerType !== 'touch') select(null, false);
  });
  root.addEventListener('focusin', (event) => {
    const card = event.target.closest('.tt-node');
    if (card) select(Number(card.dataset.id), false);
  });
  root.addEventListener('focusout', (event) => {
    if (event.target.closest('.tt-node')) select(null, false);
  });

  showPanel(start, false);
  const observer = new ResizeObserver(redraw);
  for (const canvas of root.querySelectorAll('.tt-canvas')) observer.observe(canvas);
  window.addEventListener('resize', redraw);
  document.fonts?.ready.then(redraw);
});
