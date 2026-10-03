// LAUNCH-P4 B1 — Tax settings (work order LAUNCH-P4, Part B):
//   the Taxes page shows VAT-registered + the VAT number read-only, the "Menu
//   prices include VAT" switch, and a warning with one-click "Add VAT 5%";
//   the per-product tax fields are hidden and never sent; the Taxes page text
//   matches the real rule; every new text exists in English AND Arabic.
// Run: node --test tests/frontend/launch-p4-tax.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, en, ar, read, sfc } from './launch-p4-support.mjs';

test('B1 the Taxes page shows the VAT registration read-only and the "prices include VAT" switch', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Taxes/Index.vue');
    assert.match(template, /data-test="vat-settings"/);
    assert.match(template, /vat\.vat_number/);
    assert.match(template, /vat\.vat_registered/);
    // The registration is read-only: no input is bound to it.
    assert.doesNotMatch(template, /v-model="vat\./);
    assert.match(template, /data-test="prices-include-vat"/);
    assert.match(script, /updatePricesIncludeVat\(/);
    assertKeysExist(template + script, 'Taxes page');
});

test('B1 a registered business with no active tax is warned and can add VAT 5% in one click', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Taxes/Index.vue');
    const warning = template.slice(template.indexOf('data-test="vat-missing"'));
    assert.match(template, /v-if="vat\.needs_vat_row"/);
    assert.match(warning, /data-test="add-vat"/);
    assert.match(script, /addStandardVat\(\)/);
    const api = read('resources/js/lib/api/taxes.ts');
    assert.match(api, /'\/api\/settings\/tax'/);
    assert.match(api, /'\/api\/settings\/tax\/prices-include-vat'/);
    assert.match(api, /'\/api\/settings\/tax\/add-vat'/);
});

test('B1 the per-product tax rate and "price includes tax" are hidden and never sent', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.doesNotMatch(template, /v-model="form\.tax_rate"/);
    assert.doesNotMatch(template, /v-model="form\.tax_inclusive"/);
    const payload = script.slice(script.indexOf('function productPayload'), script.indexOf('function recipePayload'));
    assert.doesNotMatch(payload, /tax_rate\s*:/);
    assert.doesNotMatch(payload, /tax_inclusive\s*:/);
    assert.match(template, /tax_settings\.product_hint/);
});

test('B1 the Taxes page text matches the real rule, in English and Arabic', () => {
    assert.doesNotMatch(en.taxes.subtitle, /on top of every order total/);
    assert.match(en.taxes.subtitle, /include VAT/);
    assert.match(en.taxes.subtitle, /Delivery-app orders carry no tax/);
    assert.match(ar.taxes.subtitle, /شاملة الضريبة/);
    assertBilingual('tax_settings');
});
