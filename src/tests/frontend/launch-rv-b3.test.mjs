// LAUNCH review add-on — fix order B-3: every cost the portal shows reads at
// 3 decimals (never "0.208696"); a cost per unit that would read 0.000 is
// said per 1000 of the unit, a cost total reads "< 0.001". Storage keeps 6.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readdirSync, statSync } from 'node:fs';
import { ar, en, get, lib, read, sfc } from './launch-p4-support.mjs';

const plain = (value) => JSON.parse(JSON.stringify(value));

test('B-3 a cost per unit reads 3 decimals; per 1000 of the unit when that is 0.000', () => {
    const { formatCost, friendlyCost } = lib('itemKind');
    assert.equal(formatCost(0.208696), '0.209');
    assert.equal(formatCost(0.15), '0.150');
    assert.equal(formatCost(12), '12.000');
    assert.equal(formatCost(-0.0001), '0.000');
    // 4.8 OMR for 23 l of milk (stored in ml): 0.000208696 per ml.
    assert.deepEqual(plain(friendlyCost('0.000208696', 'ml')), { amount: '0.209', unit: 'l' });
    assert.deepEqual(plain(friendlyCost('0.00035', 'g')), { amount: '0.350', unit: 'kg' });
    assert.deepEqual(plain(friendlyCost('1.2345678', 'kg')), { amount: '1.235', unit: 'kg' });
    // Water at 0.0004 per l would read 0.000 per l: per 1000 l instead.
    assert.deepEqual(plain(friendlyCost('0.0000004', 'ml')), { amount: '0.400', unit: '1000 l' });
    assert.deepEqual(plain(friendlyCost('0.0002', 'piece')), { amount: '0.200', unit: '1000 piece' });
    // No cost stays 0.000 in its own unit.
    assert.deepEqual(plain(friendlyCost('0', 'ml')), { amount: '0.000', unit: 'l' });
    assert.deepEqual(plain(friendlyCost('0.000000', 'kg')), { amount: '0.000', unit: 'kg' });

    // The twin kept in step (lib/purchaseUnits).
    const { friendlyCostPer } = lib('purchaseUnits');
    assert.deepEqual(plain(friendlyCostPer(0.000208696, 'ml')), { cost: '0.209', unit: 'l' });
    assert.deepEqual(plain(friendlyCostPer(0.0000004, 'ml')), { cost: '0.400', unit: '1000 l' });
    assert.deepEqual(plain(friendlyCostPer(0.05, 'piece')), { cost: '0.050', unit: 'piece' });
});

test('B-3 a cost total (a recipe line, a batch, a waste) reads 3 decimals, never 0.000 when it costs something', () => {
    const { costTotalText } = lib('itemKind');
    assert.equal(costTotalText(0.0456), '0.046');
    assert.equal(costTotalText(0.0004), '< 0.001', 'a pinch of salt');
    assert.equal(costTotalText(0), '0.000');
    assert.equal(costTotalText(2.5), '2.500');
});

test('B-3 every screen that shows a cost uses the rule', () => {
    const index = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    // Ingredients "Current cost" (list and form), movements: the shared helper.
    assert.match(index.template, /data-test="ingredient-cost">[\s\S]*?friendlyCost\(ing\.default_unit_cost, ing\.unit\)\.amount/);
    assert.match(index.template, /friendlyCost\(ingModalTarget\.default_unit_cost, ingModalTarget\.unit\)\.amount/);
    assert.match(index.template, /friendlyCost\(m\.unit_cost_at_time, m\.ingredient\?\.unit\)\.amount/);
    // The waste preview and the (hidden) branch purchase preview.
    assert.match(index.script, /return costTotalText\(cost\);/);
    assert.match(index.template, /cost: purchasePreview\.unitCost !== null \? friendlyCost\(purchasePreview\.unitCost, purchaseTarget\.ingredient\?\.unit\)\.amount : '—',\s*cost_unit: friendlyCost\(/);
    // The receipt detail.
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Show.vue').template, /friendlyCost\(line\.unit_cost, line\.unit\)\.amount/);
    // Prep items: the list, the batch cost and the cost per unit (its label follows a switch to 1000).
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PrepItemsTab.vue').template, /friendlyCost\(item\.unit_cost, item\.unit\)\.amount/);
    const prep = sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue');
    assert.match(prep.template, /\{\{ costTotalText\(batchCost\) \}\}/);
    assert.match(prep.template, /unit: unitCost === null \? costUnit\(form\.unit\) : friendlyCost\(unitCost, form\.unit\)\.unit/);
    assert.match(prep.script, /const unitCost = computed<number \| null>\(\(\) => \(yieldNumber\.value === null \? null : batchCost\.value \/ yieldNumber\.value\)\);/);
    // Recipe line costs and the live cost.
    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(wizard.script, /return Number\.isFinite\(cost\) \? costTotalText\(cost\) : null;/);
    assert.match(wizard.script, /const recipeLiveCostText = computed<string>\(\(\) => costTotalText\(recipeLiveTotal\.value\)\);/);
    assert.doesNotMatch(wizard.template, /\{\{ recipeLiveCost \}\}/);
    // The Purchases line (fix order B-2) keeps its container switch.
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Create.vue').script, /costPerText\(/);

    // No page formats a cost at 6 decimals any more.
    const pages = [];
    const walk = (dir) => {
        for (const name of readdirSync(new URL(`../../${dir}`, import.meta.url))) {
            const path = `${dir}/${name}`;
            if (statSync(new URL(`../../${path}`, import.meta.url)).isDirectory()) walk(path);
            else if (path.endsWith('.vue')) pages.push(path);
        }
    };
    walk('resources/js/Pages');
    for (const path of pages) assert.doesNotMatch(read(path), /toFixed\(6\)/, path);
});

test('B-3 the purchase preview text says the unit of its cost, in English and Arabic', () => {
    for (const tree of [en, ar]) {
        assert.match(get(tree, 'inventory.purchase_modal.preview'), /\{cost\}[^{]*\{cost_unit\}/);
    }
});
