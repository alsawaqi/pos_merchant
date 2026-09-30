/** Actual built Vue app and API client; mock only the local HTTP boundary. */
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const build = path.resolve(process.env.MERCHANT_BUILD || 'public/build');
const manifest = JSON.parse(await readFile(path.join(build, 'manifest.json'), 'utf8'));
const entry = manifest['resources/js/app.ts'];
const auth = { authenticated: true, user: { id: 1, name: 'Local QA', email: 'local@example.invalid',
    user_type: 'merchant', status: 'active', company_id: 1, locale: 'en', roles: ['merchant_super_admin'], permissions: [] } };
const html = '<!doctype html><html><head><meta name="csrf-token" content="test-only"><meta name="viewport" content="width=device-width, initial-scale=1">' +
    (entry.css || []).map(file => '<link rel="stylesheet" href="/build/' + file + '">').join('') +
    '</head><body><div id="app"></div><script>window.__INITIAL_AUTH__=' + JSON.stringify(auth) +
    '</script><script type="module" src="/build/' + entry.file + '"></script></body></html>';
const server = createServer(async (req, res) => {
    if (req.url.startsWith('/build/')) {
        const file = path.resolve(build, decodeURIComponent(req.url.slice(7).split('?')[0]));
        assert.ok(file.startsWith(build + path.sep));
        res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'application/javascript');
        res.end(await readFile(file));
    } else { res.setHeader('Content-Type', 'text/html'); res.end(html); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:' + server.address().port;
let browserServer;
try {
    browserServer = await chromium.launchServer({ host: '127.0.0.1', headless: true,
        ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
    const browser = await chromium.connect(browserServer.wsEndpoint());
    for (const width of [1440, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', msg => { if (msg.type() === 'error' && !msg.text().includes('403')) errors.push(msg.text()); });
        await page.route('**/api/**', route => route.fulfill({ json: { data: [], unread: 0 } }));
        await page.route('**/auth/**', route => route.fulfill({ json: { user: auth.user, session: { idle_timeout_minutes: 60, csrf_token: 'test-only' } } }));
        await page.route('**/auth/profile', route => { console.log('REFUSED', route.request().method()); return route.fulfill({ status: 403, json: { message: 'Account suspended.', code: 'company_suspended' } }); });
        await page.goto(origin + '/profile');
        await page.locator('form:has(input[type="email"]) button[type="submit"]').first().waitFor();
        assert.equal(await page.title(), 'MITHQAL Merchant Portal');
        await page.locator('form:has(input[type="email"]) button[type="submit"]').first().click();
        try {
            await page.getByRole('heading', { name: 'Account suspended', exact: true }).waitFor({ timeout: 5000 });
        } catch (error) {
            console.error('STATE', await page.locator('body').innerText(), errors);
            throw error;
        }
        assert.equal(await page.locator('form:has(input[type="email"]) button[type="submit"]').count(), 0, 'Old interactive portal removed');
        assert.equal(await page.locator('vite-error-overlay').count(), 0);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        assert.deepEqual(errors, []);
        if (process.env.EVIDENCE_DIR) {
            await mkdir(process.env.EVIDENCE_DIR, { recursive: true });
            await page.screenshot({ path: path.join(process.env.EVIDENCE_DIR, 'merchant-suspended-' + width + '.png') });
        }
        console.log('PASS profile -> refused save -> Account suspended; viewport=' + width + '; title=' + await page.title() + '; no runtime errors/overlay/overflow');
        await page.close();
    }
} finally {
    if (browserServer) await browserServer.kill();
    await new Promise(resolve => server.close(resolve));
}
