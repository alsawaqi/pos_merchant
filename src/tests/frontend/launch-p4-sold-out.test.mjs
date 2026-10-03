// LAUNCH-P4 B4 — "Sold out" in the portal (work order LAUNCH-P4, Part B):
//   a per-branch toggle in the catalogue list and on the branch page, using the
//   permission rule ("Manage catalogue" or "Mark sold out"); a "Sold out"
//   filter; every new text exists in English AND Arabic.
// Run: node --test tests/frontend/launch-p4-sold-out.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, read, sfc } from './launch-p4-support.mjs';

test('B4 the permission rule: "Manage catalogue" or "Mark sold out"', () => {
    const perms = read('resources/js/lib/permissions.ts');
    assert.match(perms, /CatalogueSoldOut: 'catalogue\.sold_out',/);
    assert.match(perms, /export function canMarkSoldOut[\s\S]*?can\(MerchantPermission\.CatalogueManage\) \|\| can\(MerchantPermission\.CatalogueSoldOut\)/);
});

test('B4 the catalogue list has a per-branch sold-out switch and a "Sold out" filter', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(template, /data-test="sold-out-filter"/);
    assert.match(template, /data-test="sold-out-cell"/);
    assert.match(template, /v-if="canSoldOut"[\s\S]*?data-test="sold-out-open"/);
    assert.match(template, /<SoldOutDialog/);
    assert.match(script, /canMarkSoldOut\(can\)/);
    assert.match(script, /sold_out: soldOutOnly\.value/);
    const dialog = sfc('resources/js/Pages/Merchant/Catalogue/SoldOutDialog.vue');
    assert.match(dialog.template, /data-test="sold-out-toggle"/);
    assert.match(dialog.script, /setProductSoldOut\(props\.product\.uuid, branchId, value\)/);
    assertKeysExist(dialog.template + dialog.script + template, 'sold out UI');
    assert.match(read('resources/js/lib/api/catalogue.ts'), /`\/api\/products\/\$\{productUuid\}\/sold-out`/);
});

test('B4 the branch page shows and switches sold out', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Branches/Show.vue');
    assert.match(template, /data-test="branch-sold-out-toggle"/);
    assert.match(script, /setProductSoldOut\(p\.uuid, branch\.value\.id, !p\.sold_out\)/);
    assert.match(script, /canMarkSoldOut\(can\)/);
});

test('B4 every sold-out text exists in English and Arabic', () => {
    assertBilingual('sold_out');
});
