// LAUNCH-P6 Part A item 9 — one product tick for the QR menu AND the customer
// tablet (owner decision 9): `show_on_customer_tablet` is labelled
// "Show to customers (QR and tablet)" / "إظهار للعملاء (QR والجهاز اللوحي)" in
// the product form (the channels editor and the catalogue field label) and on
// the catalogue list's channel badge (lib/channels.ts), in English and Arabic.
// Run: node --test tests/frontend/launch-p6-customer-label.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { ar, en, get, lib, sfc } from './launch-p4-support.mjs';

const EN = 'Show to customers (QR and tablet)';
const AR = 'إظهار للعملاء (QR والجهاز اللوحي)';

test('lib/channels.ts names the customer channel for both the QR menu and the tablet', () => {
    const { CUSTOMER_CHANNEL_LABEL, channelLabelKey, channelBadges } = lib('channels');
    assert.deepEqual(JSON.parse(JSON.stringify(CUSTOMER_CHANNEL_LABEL)), { en: EN, ar: AR });
    assert.equal(channelLabelKey('qr'), 'channels.badge.qr');
    // The one tick still drives the badge.
    assert.deepEqual(JSON.parse(JSON.stringify(channelBadges({ show_on_customer_tablet: false }))).find((b) => b.key === 'qr'), { key: 'qr', on: false });
});

test('the product form and the channel badge read "Show to customers (QR and tablet)" in English and Arabic', () => {
    for (const key of ['channels.qr', 'channels.badge.qr', 'catalogue.fields.show_on_tablet']) {
        assert.equal(get(en, key), EN, `en.${key}`);
        assert.equal(get(ar, key), AR, `ar.${key}`);
    }
    for (const key of ['channels.qr_hint', 'catalogue.fields.show_on_tablet_hint']) {
        assert.match(get(en, key), /tablet/i, `en.${key} mentions the tablet`);
        assert.match(get(ar, key), /الجهاز اللوحي/, `ar.${key} mentions the tablet`);
    }
    const editor = sfc('resources/js/Pages/Merchant/Catalogue/ChannelsEditor.vue');
    assert.match(editor.template, /t\('channels\.qr'\)/);
    const index = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(index.template, /channelLabelKey\(badge\.key\)/);
});
