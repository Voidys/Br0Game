#!/usr/bin/env node
// Concurrent logged-in players for the database concurrency test (see tools/loadtest/README.md).
// Every session logs in as lt_<n>, picks an universe player and then hammers in-game pages; some actions dispatch
// fleets through the real 3-step form flow. Plain HTTP with a cookie jar, no dependencies (Node 18+).
//
//   node loadtest.mjs --base https://br0game.ddev.site --players players.tsv --sessions 60 --duration 180 --think 150 --out result.json
//
// players.tsv: username<TAB>userId<TAB>galaxy<TAB>system<TAB>planet (one test player per line, from the seed tool).

import fs from 'node:fs';

process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => (a.startsWith('--') ? [...acc, [a.slice(2), all[i + 1]]] : acc), []));
const BASE = args.base ?? 'https://br0game.ddev.site';
const SESSIONS = Number(args.sessions ?? 50);
const DURATION = Number(args.duration ?? 180) * 1000;
const THINK = Number(args.think ?? 150);
const PASSWORD = args.password ?? 'loadtest';
const players = fs.readFileSync(args.players, 'utf8').trim().split('\n').map((l) => {
  const [name, id, g, s, p] = l.split('\t');
  return { name, id, g, s, p };
});

const PAGES = [
  ['overview', 25], ['buildings', 15], ['research', 8], ['fleetTable', 10], ['galaxy', 8], ['messages', 5],
  ['resources', 8], ['statistics', 4], ['shipyard', 5], ['empire', 4], ['fleetStep1', 3],
];
const DISPATCH_SHARE = 10; // weight of the fleet dispatch action

const stats = { requests: 0, byPage: {}, status: {}, errors: [], latencies: [], dispatch: { ok: 0, refused: 0, failed: 0 }, logins: 0, loginFailures: 0 };
const ERROR_BODY = /SQLSTATE|Deadlock|Lock wait timeout|Uncaught|Fatal error|<b>Exception/i;

class Session {
  constructor(player) {
    this.player = player;
    this.jar = new Map();
    this.csrf = '';
  }

  cookies() {
    return [...this.jar].map(([k, v]) => `${k}=${v}`).join('; ');
  }

  async request(path, { method = 'GET', form = null, label = path } = {}) {
    const headers = { cookie: this.cookies(), 'user-agent': 'br0game-loadtest' };
    let body;
    if (form) {
      headers['content-type'] = 'application/x-www-form-urlencoded';
      body = new URLSearchParams({ ...form, _csrf: this.csrf }).toString();
    }
    const t0 = performance.now();
    let res;
    let text = '';
    try {
      res = await fetch(BASE + path, { method, headers, body, redirect: 'manual' });
      text = await res.text();
    } catch (e) {
      const ms = performance.now() - t0;
      record(label, 0, ms, `network: ${e.cause?.code ?? e.message}`);
      return { status: 0, text: '', location: '' };
    }
    const ms = performance.now() - t0;
    for (const c of res.headers.getSetCookie()) {
      const [pair] = c.split(';');
      const eq = pair.indexOf('=');
      const name = pair.slice(0, eq);
      const value = pair.slice(eq + 1);
      if (value === 'deleted') this.jar.delete(name); else this.jar.set(name, value);
    }
    const m = text.match(/name="csrf-token" content="([^"]+)"/);
    if (m) this.csrf = m[1];
    const bad = res.status >= 500 || ERROR_BODY.test(text) ? (text.match(ERROR_BODY)?.[0] ?? `HTTP ${res.status}`) : null;
    record(label, res.status, ms, bad ? `${bad}` : null);
    return { status: res.status, text, location: res.headers.get('location') ?? '' };
  }

  async login() {
    await this.request('/', { label: 'start' });
    let r = await this.request('/?page=login', { method: 'POST', form: { email: `${this.player.name}@loadtest.invalid`, password: PASSWORD }, label: 'login' });
    if (!r.location.includes('account')) throw new Error(`login failed for ${this.player.name}: ${r.status} ${r.location}`);
    r = await this.request('/account', { label: 'account' });
    r = await this.request('/account?page=account', { method: 'POST', form: { mode: 'send', universe: '1', userID: this.player.id, action: 'login' }, label: 'enter' });
    r = await this.request('/game?page=overview', { label: 'overview' });
    if (r.status !== 200) throw new Error(`enter failed for ${this.player.name}: ${r.status}`);
    stats.logins++;
  }

  async dispatch() {
    const target = players[Math.floor(Math.random() * players.length)];
    if (target.id === this.player.id) return;
    const spy = Math.random() < 0.3;
    let r = await this.request('/game?page=fleetStep1', { method: 'POST', form: spy ? { ship210: '1' } : { ship202: '1' }, label: 'dispatch1' });
    const token = r.text.match(/name="token" value="([^"]+)"/)?.[1];
    if (!token) { stats.dispatch.refused++; return; }
    r = await this.request('/game?page=fleetStep2', { method: 'POST', form: { token, group: '0', target_mission: '-1', galaxy: target.g, system: target.s, planet: target.p, type: '1', speed: '10' }, label: 'dispatch2' });
    const missions = [...r.text.matchAll(/name="mission" value="(\d+)"/g)].map((x) => x[1]);
    if (missions.length === 0) { stats.dispatch.refused++; return; }
    const mission = (spy && missions.includes('6')) ? '6' : (missions.includes('3') ? '3' : missions[0]);
    r = await this.request('/game?page=fleetStep3', { method: 'POST', form: { token, mission, metal: mission === '3' ? '100' : '', crystal: '', deuterium: '', staytime: '' }, label: 'dispatch3' });
    if (r.status === 200 || r.status === 302) stats.dispatch.ok++; else stats.dispatch.failed++;
  }
}

function record(label, status, ms, error) {
  stats.requests++;
  stats.latencies.push(ms);
  const key = label.split('?')[0];
  (stats.byPage[key] ??= []).push(ms);
  stats.status[status] = (stats.status[status] ?? 0) + 1;
  if (error) stats.errors.push({ t: Date.now(), label, status, error });
}

const pick = () => {
  const total = PAGES.reduce((a, [, w]) => a + w, DISPATCH_SHARE);
  let r = Math.random() * total;
  if ((r -= DISPATCH_SHARE) < 0) return 'dispatch';
  for (const [page, w] of PAGES) if ((r -= w) < 0) return page;
  return 'overview';
};

async function worker(i, until) {
  const s = new Session(players[i % players.length]);
  try {
    await s.login();
  } catch (e) {
    stats.loginFailures++;
    console.error(e.message);
    return;
  }
  while (Date.now() < until) {
    const action = pick();
    if (action === 'dispatch') await s.dispatch();
    else await s.request(`/game?page=${action}`, { label: action });
    await new Promise((r) => setTimeout(r, Math.random() * THINK * 2));
  }
}

const pct = (arr, p) => { const a = [...arr].sort((x, y) => x - y); return a.length ? a[Math.min(a.length - 1, Math.floor(a.length * p))] : 0; };

// warm-up: one session walks the pages once, so cold caches (rebuilt after a DB restore) are not raced by the load
{
  const warm = new Session(players[0]);
  await warm.login();
  for (const [page] of PAGES) await warm.request(`/game?page=${page}`, { label: 'warmup' });
  stats.requests = 0; stats.latencies = []; stats.byPage = {}; stats.status = {}; stats.errors = []; stats.logins = 0;
}

const started = Date.now();
await Promise.all(Array.from({ length: SESSIONS }, (_, i) => new Promise((r) => setTimeout(r, i * 100)).then(() => worker(i, started + DURATION))));
const elapsed = (Date.now() - started) / 1000;

const errors5xx = Object.entries(stats.status).filter(([k]) => Number(k) >= 500 || k === '0').reduce((a, [, v]) => a + v, 0);
const summary = {
  sessions: SESSIONS, seconds: Math.round(elapsed), requests: stats.requests, rps: +(stats.requests / elapsed).toFixed(1),
  status: stats.status, errorResponses: stats.errors.length, http5xxOrNetwork: errors5xx,
  errorRate: +(stats.errors.length / stats.requests * 100).toFixed(2) + '%',
  p50: Math.round(pct(stats.latencies, 0.5)), p95: Math.round(pct(stats.latencies, 0.95)), p99: Math.round(pct(stats.latencies, 0.99)), max: Math.round(Math.max(...stats.latencies)),
  logins: stats.logins, loginFailures: stats.loginFailures, dispatch: stats.dispatch,
  perPage: Object.fromEntries(Object.entries(stats.byPage).map(([k, v]) => [k, { n: v.length, p50: Math.round(pct(v, 0.5)), p95: Math.round(pct(v, 0.95)) }])),
  errorSamples: stats.errors.slice(0, 15),
};
console.log(JSON.stringify(summary, null, 2));
if (args.out) fs.writeFileSync(args.out, JSON.stringify(summary, null, 2));
