// LAUNCH packaging add-on, Part B — the "Used for" ticks (lib/orderTypes.ts,
// the wizard, the add-on stock editor), the Order packaging tab
// (lib/orderPackaging.ts) and the report notes. Before (81a59b4): none of
// these files, keys or bindings existed.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { assertBilingual, assertKeysExist, exists, lib, read, sfc } from './launch-p4-support.mjs';

const plain = (value) => JSON.parse(JSON.stringify(value));
const tok = (uuid) => `#${Buffer.from(uuid.replace(/-/g, ''), 'hex').toString('base64url')}`;

test('order types: bits, ticks, the no-tick line and the read of a stored mask', () => {
    const { ORDER_TYPE_BITS, ORDER_TYPE_BUCKETS, ALL_ORDER_TYPES, readMask, hasType, toggleType, ticked, noTicks } = lib('orderTypes');
    assert.deepEqual(plain(ORDER_TYPE_BITS), { dine_in: 1, quick: 2, to_go: 4, delivery: 8 });
    assert.deepEqual(plain(ORDER_TYPE_BUCKETS), ['dine_in', 'quick', 'to_go', 'delivery']);
    assert.equal(ALL_ORDER_TYPES, 15);
    assert.equal(readMask(undefined), 15);
    assert.equal(readMask(0), 15);
    assert.equal(readMask(16), 15);
    assert.equal(readMask('12'), 12);
    assert.equal(readMask(6), 6);
    assert.equal(hasType(12, 'to_go'), true);
    assert.equal(hasType(12, 'dine_in'), false);
    assert.equal(toggleType(15, 'dine_in'), 14);
    assert.equal(toggleType(14, 'dine_in'), 15);
    assert.deepEqual(plain(ticked(12)), ['to_go', 'delivery']);
    assert.equal(noTicks(toggleType(1, 'dine_in')), true);
});

test('order types: the same item on non-overlapping lines is fine; overlapping lines are flagged', () => {
    const { overlappingLines } = lib('orderTypes');
    const lines = [
        { uuid: 'napkin', mask: 1 },
        { uuid: 'napkin', mask: 12 },
        { uuid: 'milk', mask: 15 },
        { uuid: '', mask: 15 },
        { uuid: '', mask: 15 },
        { uuid: 'napkin', mask: 8 },
    ];
    assert.deepEqual(plain(overlappingLines(lines, (l) => l.uuid, (l) => l.mask)), [5]);
});

test('order types: the cost per type once a line is ticked, null when every line is for every type', () => {
    const { costByType } = lib('orderTypes');
    const lines = [{ cost: 0.2, mask: 15 }, { cost: 0.01, mask: 1 }, { cost: 0.03, mask: 12 }];
    const costs = plain(costByType(lines, (l) => l.mask, (l) => l.cost));
    assert.equal(costs.dine_in.toFixed(3), '0.210');
    assert.equal(costs.quick.toFixed(3), '0.200');
    assert.equal(costs.to_go.toFixed(3), '0.230');
    assert.equal(costs.delivery.toFixed(3), '0.230');
    assert.equal(costByType([{ cost: 1, mask: 15 }], (l) => l.mask, (l) => l.cost), null);
});

test('order types: the server overlap 422 reads in the page language', () => {
    const { overlapMessage } = lib('orderTypes');
    const payload = { code: 'order_types_overlap', message: 'Duplicate line: "Napkin"', message_ar: 'سطر مكرر: "Napkin"' };
    assert.equal(overlapMessage(payload, 'ar'), 'سطر مكرر: "Napkin"');
    assert.equal(overlapMessage(payload, 'en'), 'Duplicate line: "Napkin"');
    assert.equal(overlapMessage({ message: 'Other' }, 'ar'), null);
    // Server review M1 — a cooked product with an ingredient on several lines.
    assert.equal(overlapMessage({ code: 'cooked_split_lines', message: 'Cooked…', message_ar: 'المطبوخة…' }, 'ar'), 'المطبوخة…');
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue').template, /recipeTicksShown \? t\('order_types\.overlap'\) : t\('order_types\.cooked_split'\)/);
    assert.equal(overlapMessage(null, 'en'), null);
});

test('add-on stock lines: ticks travel to the save, no tick and overlap block it', () => {
    const { completeConsumptionLines, consumptionLineProblem, consumptionLinesHaveProblems, consumptionOverlaps } = lib('recipeUnits');
    const find = () => ({ unit: 'piece' });
    const cupAdd = { type: 'product', product_uuid: 'cup', direction: 'add', quantity: '1', order_types: 12 };
    const cupRemove = { type: 'product', product_uuid: 'cup', direction: 'remove', quantity: '1', order_types: 12 };
    assert.deepEqual(plain(completeConsumptionLines([cupAdd]))[0].order_types, 12);
    assert.equal(plain(completeConsumptionLines([{ ...cupAdd, order_types: undefined }]))[0].order_types, 15);
    // Add and remove of one item are different keys.
    assert.deepEqual(plain(consumptionOverlaps([cupAdd, cupRemove])), []);
    assert.deepEqual(plain(consumptionOverlaps([cupAdd, { ...cupAdd, order_types: 8 }])), [1]);
    assert.equal(consumptionLinesHaveProblems([cupAdd, { ...cupAdd, order_types: 8 }], find), true);
    assert.equal(consumptionLinesHaveProblems([cupAdd, { ...cupAdd, order_types: 1 }], find), false);
    assert.equal(consumptionLineProblem({ ...cupAdd, order_types: 0 }, find).key, 'order_types.pick_one');
});

test('order packaging: stored lines reopen as typed, saves send picked lines, one line per item', () => {
    const { draftOf, payloadOf, duplicateLines, amountMissing, blankDraft } = lib('orderPackaging');
    const sugar = { type: 'ingredient', ingredient_uuid: 's', product_uuid: null, quantity: '10.0000', unit: 'g', entered_unit: 'kg', entered_quantity: '0.01', pack_uuid: null };
    const bag = { type: 'product', ingredient_uuid: null, product_uuid: 'b', quantity: '1.000', unit: null, entered_unit: null, entered_quantity: null, pack_uuid: null };
    assert.deepEqual(plain(draftOf(sugar)), { type: 'ingredient', ingredient_uuid: 's', product_uuid: '', quantity: '0.01', unit: 'kg' });
    assert.deepEqual(plain(draftOf(bag)), { type: 'product', ingredient_uuid: '', product_uuid: 'b', quantity: '1', unit: '' });
    const drafts = [draftOf(sugar), draftOf(bag), blankDraft('product'), { ...draftOf(bag), quantity: '2' }];
    assert.deepEqual(plain(payloadOf(drafts)), [
        { type: 'ingredient', ingredient_uuid: 's', product_uuid: null, quantity: '0.01', unit: 'kg' },
        { type: 'product', ingredient_uuid: null, product_uuid: 'b', quantity: '1', unit: null },
        { type: 'product', ingredient_uuid: null, product_uuid: 'b', quantity: '2', unit: null },
    ]);
    assert.deepEqual(plain(duplicateLines(drafts)), [3]);
    assert.equal(amountMissing({ ...draftOf(bag), quantity: '0' }), true);
    assert.equal(amountMissing(blankDraft()), false);
});

test('order packaging: a scan adds the item, again makes it 2, a pack counts packs; prep and other units refused', () => {
    const { applyPackagingScan, blankDraft } = lib('orderPackaging');
    const PACK = '11111111-1111-4111-8111-111111111111';
    let out = applyPackagingScan([blankDraft()], { found: true, item_type: 'physical', item: { uuid: 'napkin' }, pack: { uuid: PACK } });
    assert.equal(out.ok, true);
    assert.deepEqual(plain(out.drafts), [{ type: 'product', ingredient_uuid: '', product_uuid: 'napkin', quantity: '1', unit: tok(PACK) }]);
    out = applyPackagingScan(out.drafts, { found: true, item_type: 'physical', item: { uuid: 'napkin' }, pack: { uuid: PACK } });
    assert.equal(out.drafts[0].quantity, '2');
    assert.deepEqual(plain(applyPackagingScan(out.drafts, { found: true, item_type: 'physical', item: { uuid: 'napkin' }, pack: null })), { ok: false, reason: 'other_unit' });
    assert.deepEqual(plain(applyPackagingScan([], { found: true, item_type: 'ingredient', item: { uuid: 'syrup', is_prep: true } })), { ok: false, reason: 'prep' });
    assert.deepEqual(plain(applyPackagingScan([], { found: true, item_type: 'product', item: { uuid: 'latte', stock_mode: 'ingredient' } })), { ok: false, reason: 'not_pieces' });
    assert.deepEqual(plain(applyPackagingScan([], { found: false })), { ok: false, reason: 'not_found' });
    const sugar = applyPackagingScan([], { found: true, item_type: 'ingredient', item: { uuid: 'sugar' }, container: null });
    assert.deepEqual(plain(sugar.drafts), [{ type: 'ingredient', ingredient_uuid: 'sugar', product_uuid: '', quantity: '1', unit: '' }]);
});

test('the "Used for" toggles: four small labelled toggles, all on by default, a no-tick warning', () => {
    assert.ok(exists('resources/js/Pages/Merchant/Catalogue/OrderTypeTicks.vue'));
    const ticks = sfc('resources/js/Pages/Merchant/Catalogue/OrderTypeTicks.vue');
    assert.match(ticks.template, /v-for="bucket in ORDER_TYPE_BUCKETS"/);
    assert.match(ticks.template, /:aria-pressed="hasType\(modelValue, bucket\)"/);
    assert.match(ticks.template, /order_types\.pick_one/);
    assertKeysExist(ticks.script + ticks.template.replace(/`order_types\.short\.\$\{b(ucket)?\}`/g, "'order_types.short.dine_in'"), 'OrderTypeTicks');
});

test('the wizard ticks made-to-order recipe lines and every physical-item row, and sends them', () => {
    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(wizard.script, /const recipeTicksShown = computed<boolean>\(\(\) => form\.stock_mode === 'ingredient'\)/);
    assert.match(wizard.template, /<div v-if="recipeTicksShown" class="basis-full">\s*<OrderTypeTicks v-model="line\.order_types" \/>/);
    assert.match(wizard.template, /<OrderTypeTicks v-model="row\.order_types" :readonly="readOnly" \/>/);
    assert.match(wizard.template, /order_types\.cooked_note/);
    assert.match(wizard.script, /order_types: recipeLineTypes\(l\) \}/);
    assert.match(wizard.script, /\{ component_uuid: l\.component_uuid, quantity: l\.quantity, order_types: l\.order_types \}/);
    assert.match(wizard.script, /order_types: readMask\(line\.order_types\)/);
    assert.match(wizard.script, /form\.recipe_lines\.push\(\{ ingredient_uuid: '', quantity: '', unit: '', order_types: ALL_ORDER_TYPES \}\)/);
    assert.match(wizard.template, /:disabled="submitting \|\| recipeHasDuplicates \|\| ticksBlocked"/);
    assert.match(wizard.template, /data-test="recipe-cost-by-type"/);
});

test('the add-on stock editor ticks each line and flags overlaps', () => {
    const editor = sfc('resources/js/Pages/Merchant/Catalogue/AddonConsumptionEditor.vue');
    assert.match(editor.template, /<OrderTypeTicks :model-value="line\.order_types \?\? ALL_ORDER_TYPES" :disabled="disabled" @update:model-value="patch\(idx, \{ order_types: \$event \}\)" \/>/);
    assert.match(editor.template, /data-test="consumption-overlap"/);
    assert.match(editor.script, /order_types: ALL_ORDER_TYPES \}/);
    for (const page of ['ProductWizard', 'Index']) {
        assert.match(sfc(`resources/js/Pages/Merchant/Catalogue/${page}.vue`).script, /order_types: readMask\(l\.order_types\)/, page);
    }
});

test('Inventory has an Order packaging tab with four lists, the scan box and the unit-safe amount box', () => {
    const index = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(index.template, /data-test="order-packaging-tab-button"/);
    assert.match(index.template, /<OrderPackagingTab v-if="activeTab === 'order_packaging'"/);
    const tab = sfc('resources/js/Pages/Merchant/Inventory/OrderPackagingTab.vue');
    assert.match(tab.template, /v-for="bucket in ORDER_TYPE_BUCKETS"/);
    assert.match(tab.template, /<ScanBox /);
    assert.match(tab.template, /<AmountInput/);
    assert.match(tab.template, /<AmountConfirmDialog/);
    assert.match(tab.script, /saveOrderPackaging\(bucket, payloadOf\(drafts\[bucket\]\)\)/);
    assert.match(read('resources/js/lib/api/orderPackaging.ts'), /\/api\/inventory\/order-packaging\/\$\{orderType\}/);
    assertKeysExist(tab.script + tab.template, 'OrderPackagingTab');
});

test('the reports say where the per-order packaging is counted', () => {
    assert.match(sfc('resources/js/Pages/Merchant/Reports/Sales.vue').template, /order_packaging\.reports\.sales_packaging/);
    assert.match(sfc('resources/js/Pages/Merchant/Reports/ProductPerformance.vue').template, /order_packaging\.reports\.performance_note/);
    const recipe = sfc('resources/js/Pages/Merchant/Reports/RecipeCost.vue');
    assert.match(recipe.template, /r\.theoretical_by_type/);
    assert.match(recipe.template, /order_packaging\.reports\.recipe_cost_note/);
});

test('every new text is in English and Arabic', () => {
    assertBilingual('order_types');
    assertBilingual('order_packaging');
});
