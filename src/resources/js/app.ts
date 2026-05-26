import '../css/app.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';
import { i18n, applyDocumentDirection } from './lib/i18n';
import { hydrateAuthFromInitial } from './stores/auth';
import { installBfcacheGuard } from './lib/bfcacheGuard';

/**
 * Synchronous boot. Order matters:
 *   1. Hydrate the auth store from the blade-injected
 *      window.__INITIAL_AUTH__ before the router's beforeEach
 *      guard runs — otherwise the very first navigation can't
 *      tell whether the user is signed in.
 *   2. Apply RTL/LTR direction on <html> before Vue mounts so the
 *      first paint matches the user's locale (no flash of LTR
 *      when locale is Arabic).
 *   3. Install bfcacheGuard to defeat back-button restoration of
 *      a previously-authenticated shell.
 */
hydrateAuthFromInitial();
applyDocumentDirection();
installBfcacheGuard();

const app = createApp(App);
app.use(createPinia());
app.use(i18n);
app.use(router);

app.mount('#app');
