/**
 * Web Worker of the bot check: receives the challenge ({salt, challenge, maxnumber}), answers {number} or {error}.
 * Module worker, loaded from our own origin (CSP script-src 'self').
 */
import { solve } from './pow-solver.js';

self.onmessage = async (event) => {
  try {
    self.postMessage({ number: await solve(event.data) });
  } catch (error) {
    self.postMessage({ error: error.message });
  }
};
