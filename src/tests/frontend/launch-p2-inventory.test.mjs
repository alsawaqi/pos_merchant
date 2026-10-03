// LAUNCH-P2 — merchant portal inventory screens.
//   P2-1 new ingredients default to a small unit (g / ml / piece);
//   P2-3 the goods-received unit picker (options + live preview);
//   P2-4 the other stock-in entry points are hidden while single stock-in is on;
//   P2-6 the count entry is blind (never the system quantity);
//   P2-7 the stock page flags negative / below minimum, filters low stock,
//        and the dashboard Low stock card links to that filter;
//   every new string exists in English AND Arabic.
// Run: node --test tests/frontend/
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

function sfc(path) {
    const parsed = parse(read(path));
    assert.deepEqual(parsed.errors, [], path);
    return { script: parsed.descriptor.scriptSetup?.content ?? '', template: parsed.descriptor.template?.content ?? '' };
}

/** Load lib/purchaseUnits.ts as a plain module (exports collected from `export`). */
function purchaseUnits() {
    const source = read('resources/js/lib/purchaseUnits.ts');
    const { outputText } = ts.transpileModule(source, {
        compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
    });
    const module = { exports: {} };
    runInNewContext(outputText, { module, exports: module.exports, Math, Number, String, Set, parseFloat });
    return module.exports;
}

test('P2-3 the unit picker offers base, metric pair, extra units and the piece unit', () => {
    const { purchaseUnitOptions, purchaseUnitFactor, PIECE_UNIT } = purchaseUnits();
    const milk = {
        unit: 'ml',
        auto_units: [{ name: 'l', factor: '1000' }],
        alt_units: [{ name: 'box', factor: '12000.0000' }],
        piece_unit_label: 'bottle',
        units_per_piece: '1000.0000',
    };
    const options = purchaseUnitOptions(milk);
    // (Values built in the VM realm: copy before a strict structural compare.)
    assert.deepEqual([...options.map((o) => o.value)], ['', 'box', 'l', PIECE_UNIT]);
    assert.equal(purchaseUnitFactor(options, 'l'), 1000);
    assert.equal(purchaseUnitFactor(options, 'box'), 12000);
    assert.equal(purchaseUnitFactor(options, PIECE_UNIT), 1000);
    assert.equal(options[3].label, 'bottle (1000 ml)');
    assert.equal(PIECE_UNIT, '@piece');
});

test('P2-3 the line preview converts to base units and prices per base unit', () => {
    const { purchaseLinePreview, trimNumber } = purchaseUnits();
    // 25 kg of a gram ingredient at 0.350 per kg.
    const flour = purchaseLinePreview(1000, '25', '0.350');
    assert.equal(flour.baseQuantity, 25000);
    assert.equal(flour.lineCost, 8.75);
    assert.equal(trimNumber(flour.costPerBase, 6), '0.00035');
    // 0.3 g of a kg ingredient keeps its 4 decimals.
    const saffron = purchaseLinePreview(0.001, '0.3', '1.2');
    assert.equal(saffron.baseQuantity, 0.0003);
    assert.equal(saffron.costPerBase, 1200);
    assert.deepEqual({ ...purchaseLinePreview(1000, '', '1') }, { baseQuantity: null, lineCost: null, costPerBase: null });
});

test('P2-3 the goods-received form enters a unit and a price per unit and shows the base quantity', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Create.vue');
    assert.match(template, /data-test="line-unit"/);
    assert.match(template, /data-test="line-unit-price"/);
    assert.match(template, /data-test="line-base-equivalent"/);
    assert.match(template, /purchase_receipts\.form\.price_per_unit/);
    assert.match(script, /unit_price: l\.unit_price === '' \? 0 : l\.unit_price/);
    assert.match(script, /unit: kind === 'ingredient' && l\.unit !== '' \? l\.unit : null/);
    assert.doesNotMatch(template, /v-model="line\.line_cost"/);
});

test('P2-4 the branch Restock / Purchase and the warehouse Receive are hidden while single stock-in is on', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(script, /getInventorySettings\(\)/);
    for (const call of ['openRestock(null, ingredients[0])', 'openPurchase(null, ingredients[0])', 'openRestock(row)', 'openPurchase(row)']) {
        const at = template.indexOf(`@click="${call}"`);
        assert.ok(at > 0, call);
        const tag = template.slice(template.lastIndexOf('<button', at), at);
        assert.match(tag, /!singleStockIn/, `${call} must be hidden while single stock-in is on`);
    }
    assert.match(template, /data-test="goods-received-link"/);
    const dialog = sfc('resources/js/Pages/Merchant/Inventory/IngredientStockDialog.vue').script;
    assert.match(dialog, /props\.singleStockIn\s*\?\s*\['allocate', 'transfer', 'adjust'\]/);
});

test('P2-6 the count entry is blind: every ingredient, never the quantity on the books', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    const modal = template.slice(template.indexOf('id="count-modal-form"'), template.indexOf('</form>', template.indexOf('id="count-modal-form"')));
    assert.ok(modal.length > 0);
    assert.doesNotMatch(modal, /on_book|r\.row\.quantity|expected/);
    assert.match(modal, /data-test="blind-count-hint"/);
    assert.match(script, /countRows\.value = ingredients\.value\s*\.filter\(\(ingredient\) => ingredient\.status === 'active'\)/);
});

test('P2-7 the stock page flags negative red and below minimum amber, with a Low stock filter', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(template, /data-test="stock-filter"/);
    assert.match(template, /stockFilter = 'low'/);
    assert.match(template, /row\.stock_value/);
    assert.match(script, /if \(status === 'negative'\) return 'bg-rose-100 text-rose-700';/);
    assert.match(script, /if \(status === 'below_minimum'\) return 'bg-amber-100 text-amber-700';/);
    assert.match(script, /listBranchStock\(selectedBranchUuid\.value, stockFilter\.value === 'low' \? 'low' : null\)/);
    const dashboard = sfc('resources/js/Pages/Merchant/Dashboard.vue');
    assert.match(dashboard.template, /data-test="low-stock-card"/);
    assert.match(dashboard.script, /\{ tab: 'stock', filter: 'low' \}/);
});

test('P2-1 a new ingredient is stored in a small unit', () => {
    // LAUNCH item kind (owner decision 2026-10-03): the small unit now comes
    // from the kind question — Weighed g, Liquid ml, Counted piece — instead
    // of a preselected small base unit.
    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(read('resources/js/lib/itemKind.ts'), /KIND_STORED_UNIT: Record<ItemKind, 'g' \| 'ml' \| 'piece'> = \{\s*weighed: 'g',\s*liquid: 'ml',\s*counted: 'piece',\s*\}/);
    assert.match(script, /ingForm\.unit = storedUnitForKind\(kind, /);
    assert.match(template, /data-test="item-kind"/);
});

test('every LAUNCH-P2 string exists in English and Arabic', () => {
    const en = JSON.parse(read('resources/js/locales/en.json'));
    const ar = JSON.parse(read('resources/js/locales/ar.json'));
    const keys = [
        'purchase_receipts.form.unit', 'purchase_receipts.form.unit_price', 'purchase_receipts.form.price_per_unit',
        'purchase_receipts.form.line_total', 'purchase_receipts.form.base_equivalent', 'purchase_receipts.form.cost_per_base',
        'purchase_receipts.form.split_in_unit', 'purchase_receipts.show.entered_as', 'purchase_receipts.show.cost_per_base_unit',
        'purchase_receipts.show.piece_unit', 'inventory.stock.all', 'inventory.stock.low_filter', 'inventory.stock.no_low',
        'inventory.stock.value', 'inventory.stock.total_value', 'inventory.stock.negative_count',
        'inventory.stock.below_minimum_count', 'inventory.stock.minimum', 'inventory.stock.goods_received',
        'inventory.stock.status.negative', 'inventory.stock.status.below_minimum', 'inventory.stock.status.ok',
        'inventory.movement_types.count_correction', 'inventory.counts.success_blind', 'inventory.counts.modal.blind_hint',
        'inventory.units.group_small', 'inventory.units.group_other', 'inventory.units.small_unit_hint',
        'dashboard_widgets.low_stock_negative', 'dashboard_widgets.low_stock_below_minimum', 'dashboard_widgets.low_stock_open',
    ];
    const get = (tree, key) => key.split('.').reduce((node, part) => node?.[part], tree);
    for (const key of keys) {
        assert.equal(typeof get(en, key), 'string', `en ${key}`);
        assert.equal(typeof get(ar, key), 'string', `ar ${key}`);
        assert.notEqual(get(ar, key), get(en, key), `ar ${key} is translated`);
    }
});

test('P2-1 inventory inputs accept 6-decimal unit costs and 4-decimal quantities', () => {
    // Found in the browser 2026-10-02: after goods received the average cost
    // is e.g. 0.015328, and the ingredient editor's step="0.001" made that a
    // browser-invalid value, so the ingredient could not be saved without
    // rounding the average away.
    const { template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    const inputFor = (model) => {
        const escaped = model.replace(/[.[\]()]/g, '\$&');
        const match = template.match(new RegExp(`<input[^>]*v-model="${escaped}"[^>]*>`));
        assert.ok(match, `input for ${model}`);
        return match[0];
    };
    for (const model of ['ingForm.default_unit_cost', 'restockForm.unit_cost']) {
        assert.match(inputFor(model), /step="0\.000001"/, `${model} keeps 6 decimals`);
    }
    for (const model of ['ingForm.min_stock_threshold', 'adjustForm.signed_quantity', 'restockForm.quantity', 'purchaseForm.units', 'wasteForm.quantity']) {
        const input = inputFor(model);
        assert.match(input, /step="0\.0001"/, `${model} keeps 4 decimals`);
        assert.doesNotMatch(input, /min="0\.001"/, `${model} does not refuse 0.0005`);
    }
});
