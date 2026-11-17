/**
 * Page configuration for the non-game areas (admin, account, login). The layout renders the values the
 * scripts need into <script type="application/json" id="page-config"> (no inline JavaScript, so the page
 * works under a strict Content-Security-Policy); publishEnv() (app/env.js) turns it into the globals the
 * shared modules read. Pages without that island (login) get no globals.
 */
import { pageData } from '../app/core.js';
import { publishEnv } from '../app/env.js';

if (document.getElementById('page-config')) publishEnv(pageData('page-config'));
