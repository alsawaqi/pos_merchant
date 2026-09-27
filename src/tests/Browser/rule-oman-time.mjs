/** Real built Vue pages; only the HTTP boundary is supplied. No Vue/action doubles. */
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const build = path.resolve(process.env.MERCHANT_BUILD || 'public/build');
const manifest = JSON.parse(await readFile(path.join(build, 'manifest.json'), 'utf8'));
const entry = manifest['resources/js/app.ts'];
const auth = { authenticated: true, user: { id: 1, name: 'P1 Test', email: 'test@example.invalid', user_type: 'merchant', status: 'active', company_id: 1, locale: 'en', roles: ['SuperAdmin'], permissions: ['discounts.view', 'discounts.manage', 'loyalty.view', 'loyalty.manage'] } };
const html = `<!doctype html><html><head><meta name="csrf-token" content="test-only"><meta name="viewport" content="width=device-width, initial-scale=1">${(entry.css || []).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app"></div><script>window.__INITIAL_AUTH__=${JSON.stringify(auth)}</script><script type="module" src="/build/${entry.file}"></script></body></html>`;
const server = createServer(async (request, response) => {
    try {
        if (request.url.startsWith('/build/')) {
            const file = path.resolve(build, decodeURIComponent(request.url.slice(7).split('?')[0]));
            assert.ok(file.startsWith(build + path.sep));
            response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'application/javascript');
            response.end(await readFile(file));
        } else { response.setHeader('Content-Type', 'text/html'); response.end(html); }
    } catch { response.writeHead(404); response.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
let failed = 0;
let completed = 0;
try {
    // Explicitly run outside Oman to detect accidental use of browser-local time.
    for (const timezoneId of ['UTC', 'America/New_York']) {
        for (const kind of ['discounts', 'offers', 'loyalty']) {
          for (const scenario of ['create', 'edit', 'status']) {
            console.log(`CASE ${timezoneId} ${kind} ${scenario}`);
            let browserServer;
            try {
                browserServer = await chromium.launchServer({ host: '127.0.0.1', headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
                const browser = await chromium.connect(browserServer.wsEndpoint());
            const context = await browser.newContext({ timezoneId, locale: 'en-US', viewport: { width: 1440, height: 1100 } });
            context.setDefaultTimeout(10000);
            const page = await context.newPage();
            const writes = [];
            const rows = scenario === 'create' ? [] : [{
                id: 1, uuid: 'p1-rule', name: `OMAN P1 ${kind}`, scope: 'order', amount_type: 'percent', amount: '10.000',
                type: kind === 'loyalty' ? 'spend_based' : 'spend_get', config: kind === 'offers' ? { min_subtotal_baisas: 1000, reward_type: 'percent_off', reward_value: 10, reward_product_id: null } : {}, branch_scope_json: null, max_per_order: null, auto_apply: true, status: 'active', currently_active: false,
                validity_start: '2026-09-27T13:00:00+00:00', validity_end: '2026-09-27T14:00:00+00:00',
            }];
            const api = kind === 'loyalty' ? '/api/loyalty/rules' : `/api/${kind}`;
            await page.clock.setFixedTime(new Date('2026-09-27T12:59:59Z'));
            await page.route('**/auth/**', route => route.fulfill({ json: { user: auth.user, session: { idle_timeout_minutes: 60, csrf_token: 'test-only' } } }));
            await page.route('**/api/**', async route => {
                const request = route.request(); const pathname = new URL(request.url()).pathname;
                if (pathname === api || pathname.startsWith(api + '/')) {
                    if (request.method() === 'GET') { await route.fulfill({ json: { data: rows } }); return; }
                    const payload = request.postDataJSON(); writes.push(payload);
                    const row = { id: 1, uuid: 'p1-rule', scope: 'order', amount_type: 'percent', amount: '10.000', status: 'active', currently_active: false, config: payload.config_json || payload.config || {}, ...payload,
                        validity_start: payload.validity_start ? new Date(/(?:Z|[+-]\d\d:\d\d)$/i.test(payload.validity_start) ? payload.validity_start : payload.validity_start + 'Z').toISOString() : null,
                        validity_end: payload.validity_end ? new Date(/(?:Z|[+-]\d\d:\d\d)$/i.test(payload.validity_end) ? payload.validity_end : payload.validity_end + 'Z').toISOString() : null };
                    rows.splice(0, rows.length, row);
                    await route.fulfill({ status: request.method() === 'POST' ? 201 : 200, json: { data: row } }); return;
                }
                if (pathname === '/api/loyalty/shortfalls') { await route.fulfill({ json: { data: [], meta: { total: 0, last_page: 1, current_page: 1 } } }); return; }
                await route.fulfill({ json: { data: [] } });
            });
                await page.goto(`${origin}/${kind}`);
                if (scenario === 'status') {
                    const row = page.locator('tr').filter({ hasText: `OMAN P1 ${kind}` });
                    await row.waitFor({ timeout: 10000 });
                    assert.match(await row.innerText(), /Scheduled/);
                    await page.clock.setFixedTime(new Date('2026-09-27T13:00:00Z'));
                    await row.getByText(/Active/).waitFor({ timeout: 10000 }); assert.match(await row.innerText(), /Active/);
                    await page.clock.setFixedTime(new Date('2026-09-27T14:00:01Z'));
                    await row.getByText(/Expired/).waitFor({ timeout: 10000 }); assert.match(await row.innerText(), /Expired/);
                    assert.equal(writes.length, 0, 'reading the list never rewrites stored rows');
                    console.log(`PASS ${timezoneId} ${kind} ${scenario}: same-instant status without writes`);
                    continue;
                }
                if (scenario === 'edit') {
                    await page.locator('tr').filter({ hasText: `OMAN P1 ${kind}` }).getByRole('button', { name: 'Edit', exact: true }).click({ timeout: 10000 });
                    const existing = page.locator('form input[type="datetime-local"]');
                    assert.equal(await existing.count(), 2);
                    assert.equal(await existing.nth(0).inputValue(), '2026-09-27T17:00');
                    assert.equal(await existing.nth(1).inputValue(), '2026-09-27T18:00');
                } else {
                    await page.getByRole('button', { name: kind === 'loyalty' ? 'Add rule' : kind === 'discounts' ? 'Add discount' : 'Add offer', exact: true }).click({ timeout: 10000 });
                }
                const form = page.locator(kind === 'loyalty' ? '#loyalty-rule-form' : kind === 'discounts' ? '#discount-form' : '#offer-form');
                await form.locator('input[type="text"]').first().fill(`OMAN P1 ${kind}`);
                if (kind === 'offers') await form.getByRole('button', { name: 'Spend & get', exact: true }).click();
                if (kind === 'discounts') await form.locator('input[inputmode="decimal"]').fill('10');
                const dates = form.locator('input[type="datetime-local"]');
                assert.equal(await dates.count(), 2, `${kind} exposes both validity inputs`);
                await dates.nth(0).fill('2026-09-27T17:00');
                await dates.nth(1).fill('2026-09-27T18:00');
                if (kind !== 'loyalty') {
                    assert.ok((await form.innerText()).includes('Daily hours (optional)'));
                    assert.ok((await form.innerText()).includes('Repeats every day, Oman time. Leave empty for all day.'));
                }
                await page.getByRole('button', { name: 'Save', exact: true }).click();
                await page.waitForFunction(() => !document.querySelector('form input[type="datetime-local"]'), undefined, { timeout: 10000 });
                assert.equal(writes.length, 1, 'create passes through the real API client');
                assert.equal(writes[0].validity_start, '2026-09-27T17:00:00+04:00');
                assert.equal(rows[0].validity_start, '2026-09-27T13:00:00.000Z');
                const row = page.locator('tr').filter({ hasText: `OMAN P1 ${kind}` });
                assert.match(await row.innerText(), /Scheduled/);
                await page.clock.setFixedTime(new Date('2026-09-27T13:00:00Z'));
                    await row.getByText(/Active/).waitFor({ timeout: 10000 });
                assert.match(await row.innerText(), /Active/);
                await page.clock.setFixedTime(new Date('2026-09-27T14:00:01Z'));
                    await row.getByText(/Expired/).waitFor({ timeout: 10000 });
                assert.match(await row.innerText(), /Expired/);
                await row.getByRole('button', { name: 'Edit', exact: true }).click();
                assert.equal(await dates.nth(0).inputValue(), '2026-09-27T17:00', 'edit shows original Oman time');
                assert.equal(await dates.nth(1).inputValue(), '2026-09-27T18:00');
                await dates.nth(0).fill('2026-09-28T17:00'); await dates.nth(1).fill('2026-09-28T18:00');
                await page.getByRole('button', { name: 'Save', exact: true }).click();
                await page.waitForFunction(() => !document.querySelector('form input[type="datetime-local"]'), undefined, { timeout: 10000 });
                assert.equal(writes.length, 2); assert.equal(writes[1].validity_start, '2026-09-28T17:00:00+04:00');
                assert.equal(rows[0].validity_start, '2026-09-28T13:00:00.000Z');
                console.log(`PASS ${timezoneId} ${kind} ${scenario}: real create/edit, 17 Oman = 13 UTC, scheduled/active/expired boundaries`);
            } catch (error) { failed++; console.error(`FAIL ${timezoneId} ${kind} ${scenario}:`, error.stack); }
            finally {
                completed++;
                // Keep one isolated browser process per case and tear it down here;
                // this bounds memory without relying on Chrome page-close cleanup.
                if (browserServer) await browserServer.kill();
            }
          }
        }
    }
} finally {
    console.log(`RESULT ${completed - failed} passed, ${failed} failed, ${completed}/18 completed`);
    console.log("CLEANUP HTTP server");
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
}
console.log("CLEANUP complete"); process.exitCode = failed ? 1 : 0;
