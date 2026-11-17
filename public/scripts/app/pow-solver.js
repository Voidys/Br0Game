/**
 * Proof-of-work solver of the bot check (native ES module, no dependencies; runs in the Web Worker pow-worker.js,
 * or on the main thread when no worker is available). Finds the number N with sha256(salt + N) == challenge,
 * N in 0..maxnumber, using crypto.subtle (needs a secure context, i.e. https or localhost).
 */
const BATCH = 512;
const encoder = new TextEncoder();

/** "ab12..." -> Uint8Array */
function hexToBytes(hex) {
  return Uint8Array.from(hex.match(/../g) ?? [], (pair) => parseInt(pair, 16));
}

/** whether a digest equals the target bytes */
function sameBytes(digest, target) {
  const bytes = new Uint8Array(digest);
  if (bytes.length !== target.length) return false;
  for (let i = 0; i < bytes.length; i += 1) {
    if (bytes[i] !== target[i]) return false;
  }
  return true;
}

/**
 * @param {{salt: string, challenge: string, maxnumber: number}} challenge the server's challenge
 * @returns {Promise<number>} the solution
 * @throws {Error} 'nocrypto' without crypto.subtle, 'unsolvable' when no number matches
 */
export async function solve({ salt, challenge, maxnumber }) {
  const subtle = globalThis.crypto?.subtle;
  if (!subtle) throw new Error('nocrypto');
  const target = hexToBytes(challenge);
  for (let start = 0; start <= maxnumber; start += BATCH) {
    const end = Math.min(start + BATCH - 1, maxnumber);
    const hashes = [];
    for (let n = start; n <= end; n += 1) {
      hashes.push(subtle.digest('SHA-256', encoder.encode(salt + n)));
    }
    const digests = await Promise.all(hashes);
    for (let i = 0; i < digests.length; i += 1) {
      if (sameBytes(digests[i], target)) return start + i;
    }
  }
  throw new Error('unsolvable');
}
