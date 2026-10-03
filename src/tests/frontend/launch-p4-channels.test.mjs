// LAUNCH-P4 B3 + B7 (H6, H7, L5) — channels and branches on the product form:
//   B3 "In store", "QR menu" and "Delivery" (per provider: a listed tick and a
//      price, blank = the delivery price); channel icons in the catalogue list;
//   H6 a branch-scope choice (all branches / only selected branches);
//   H7 the product form never sends shelf counts;
//   L5 the Arabic description;
//   every new text exists in English AND Arabic.
// Run: node --test tests/frontend/launch-p4-channels.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, exists, lib, read, sfc } from './launch-p4-support.mjs';

test('B3 provider rows: not listed or own price is sent; listed at the default price is not', () => {
    assert.ok(exists('resources/js/lib/channels.ts'), 'lib/channels.ts');
    const { providerRowsFrom, providerPayload, effectiveProviderPrice } = lib('channels');
    const providers = [{ uuid: 'talabat' }, { uuid: 'otlob' }, { uuid: 'keeta' }];
    const rows = providerRowsFrom(providers, [
        { price: null, listed: false, delivery_provider: { uuid: 'talabat' } },
        { price: '2.100', listed: true, delivery_provider: { uuid: 'otlob' } },
    ]);
    assert.deepEqual(JSON.parse(JSON.stringify(rows)), {
        talabat: { listed: false, price: '' },
        otlob: { listed: true, price: '2.100' },
        keeta: { listed: true, price: '' },
    });
    assert.deepEqual(JSON.parse(JSON.stringify(providerPayload(providers, rows))), [
        { provider_uuid: 'talabat', listed: false, price: null },
        { provider_uuid: 'otlob', listed: true, price: '2.100' },
    ]);
    // Provider price, else the delivery price, else the base price.
    assert.equal(effectiveProviderPrice({ listed: true, price: '2.100' }, '1.800', '1.500'), '2.100');
    assert.equal(effectiveProviderPrice({ listed: true, price: '' }, '1.800', '1.500'), '1.800');
    assert.equal(effectiveProviderPrice(undefined, '', '1.500'), '1.500');
});

test('B3 the product form has In store, QR menu and Delivery with a listed tick and price per provider', () => {
    const editor = sfc('resources/js/Pages/Merchant/Catalogue/ChannelsEditor.vue');
    for (const hook of ['channel-in-store', 'channel-qr', 'channel-delivery', 'provider-listed', 'provider-price', 'delivery-price']) {
        assert.match(editor.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assertKeysExist(editor.template + editor.script, 'ChannelsEditor');
    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(wizard.template, /<ChannelsEditor/);
    assert.match(wizard.template, /v-model:sold-in-store="form\.sold_in_store"/);
    assert.match(wizard.template, /v-model:show-on-qr="form\.show_on_customer_tablet"/);
    assert.match(wizard.template, /v-model:sold-on-delivery="form\.sold_on_delivery"/);
    const payload = wizard.script.slice(wizard.script.indexOf('function productPayload'), wizard.script.indexOf('function recipePayload'));
    assert.match(payload, /sold_in_store: form\.sold_in_store/);
    assert.match(payload, /sold_on_delivery: form\.sold_on_delivery/);
    // Edit mode sends listed + price per touched provider.
    assert.match(wizard.script, /setProductDeliveryPrice\(uuid, provider\.uuid, \{ listed: row\.listed/);
});

test('B3 the catalogue list shows channel icons', () => {
    const { channelBadges } = lib('channels');
    assert.deepEqual(JSON.parse(JSON.stringify(channelBadges({ sold_in_store: true, show_on_customer_tablet: false, sold_on_delivery: true }))), [
        { key: 'in_store', on: true },
        { key: 'qr', on: false },
        { key: 'delivery', on: true },
    ]);
    const { template, script } = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(template, /data-test="channel-icons"/);
    assert.match(template, /channelBadges\(prod\)/);
    assert.match(script, /CHANNEL_ICONS/);
});

test('H6 the branch rule is a choice between all branches and selected branches', () => {
    const { branchScopePayload, selectedBranchIds } = lib('channels');
    assert.deepEqual(JSON.parse(JSON.stringify(branchScopePayload('all', [3, 1]))), { branch_scope: 'all', branch_ids: [] });
    assert.deepEqual(JSON.parse(JSON.stringify(branchScopePayload('selected', [3, 1, 3]))), { branch_scope: 'selected', branch_ids: [1, 3] });
    assert.deepEqual(selectedBranchIds([{ branch_id: 1, is_available: true }, { branch_id: 2, is_available: false }]), [1]);
    const editor = sfc('resources/js/Pages/Merchant/Catalogue/ChannelsEditor.vue');
    assert.match(editor.template, /data-test="scope-all"/);
    assert.match(editor.template, /data-test="scope-selected"/);
    const api = read('resources/js/lib/api/catalogue.ts');
    assert.match(api, /export function syncProductBranches\(\s*productUuid: string,\s*payload: BranchScopePayload,/);
});

test('H7 the product form never sends a shelf count', () => {
    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    const branches = wizard.script.slice(wizard.script.indexOf('function branchesPayload'), wizard.script.indexOf('function ownedGroupsPayload'));
    assert.doesNotMatch(branches, /stock_qty/);
    assert.doesNotMatch(wizard.template, /branch_rows/);
    assert.doesNotMatch(wizard.template + wizard.script, /stock_placeholder/);
    const editor = sfc('resources/js/Pages/Merchant/Catalogue/ChannelsEditor.vue');
    assert.doesNotMatch(editor.template + editor.script, /stock_qty/);
});

test('L5 the product form has an Arabic description', () => {
    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(wizard.template, /v-model="form\.description_ar"[^>]*dir="rtl"/);
    assert.match(wizard.script, /description_ar: form\.description_ar\.trim\(\) \|\| null/);
});

test('B3 every new channel text exists in English and Arabic', () => {
    assertBilingual('channels');
    assertBilingual('product_form');
});
