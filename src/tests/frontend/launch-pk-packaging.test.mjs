// LAUNCH packaging add-on, Part B — the pure helpers behind the "Used for"
// ticks (lib/orderTypes.ts, lib/recipeUnits.ts), the Order packaging editor
// (lib/orderPackaging.ts) and the texts. Fix order PK-B1 (L7) — behaviour is
// tested through the helpers the screens call, not regexes over .vue source.
// Before (81a59b4): none of these helpers or keys existed.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { assertBilingual, assertKeysExist, lib, sfc } from './launch-p4-support.mjs';

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

test('order types: ingredient ticks only on made-to-order; a cooked recipe saves every type', () => {
    const { recipeTicksShown, recipeLineMask, overlappingLines } = lib('orderTypes');
    assert.equal(recipeTicksShown('ingredient'), true);
    assert.equal(recipeTicksShown('cooked'), false);
    assert.equal(recipeLineMask('ingredient', 4), 4);
    assert.equal(recipeLineMask('cooked', 4), 15);
    // On a cooked product two lines of one ingredient always overlap (merge them).
    const lines = [{ uuid: 'napkin', mask: 1 }, { uuid: 'napkin', mask: 12 }];
    assert.deepEqual(plain(overlappingLines(lines, (l) => l.uuid, (l) => recipeLineMask('cooked', l.mask))), [1]);
    assert.deepEqual(plain(overlappingLines(lines, (l) => l.uuid, (l) => recipeLineMask('ingredient', l.mask))), []);
});

test('PK-B1 M4: made to order → cooked saves the recipe before the type; every other change the type first', () => {
    const { recipeSavedFirst } = lib('orderTypes');
    assert.equal(recipeSavedFirst('ingredient', 'cooked'), true);
    assert.equal(recipeSavedFirst('cooked', 'cooked'), false);
    assert.equal(recipeSavedFirst('cooked', 'ingredient'), false);
    // Untracked → cooked has no recipe until the type allows one.
    assert.equal(recipeSavedFirst('untracked', 'cooked'), false);
    assert.equal(recipeSavedFirst(undefined, 'cooked'), false);
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

test('PK-B1 L3: a server refusal reads in the page language', () => {
    const { localizedMessage } = lib('orderTypes');
    for (const code of ['order_types_overlap', 'cooked_split_lines', 'on_packaging_list', 'packaging_prep']) {
        const payload = { code, message: `EN ${code}`, message_ar: `AR ${code}` };
        assert.equal(localizedMessage(payload, 'ar'), `AR ${code}`);
        assert.equal(localizedMessage(payload, 'en'), `EN ${code}`);
    }
    assert.equal(localizedMessage({ message: 'Only English' }, 'ar'), null);
    assert.equal(localizedMessage(null, 'en'), null);
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

test('PK-B1 L4: the item picker and packs come from the list endpoint; pieces = packs × pieces', () => {
    const { packOptions, piecesOf, blankDraft } = lib('orderPackaging');
    const napkin = { uuid: 'n', name: 'Napkin', name_ar: 'منديل', kind: 'physical', cost_price: '0.002', packs: [{ uuid: 'p', token: '#tok', pieces: '50', display_name: 'pack 50', display_name_ar: 'علبة 50' }] };
    assert.deepEqual(plain(packOptions(napkin, 'en', 'pieces')), [{ value: '', label: 'pieces' }, { value: '#tok', label: 'pack 50' }]);
    assert.deepEqual(plain(packOptions(napkin, 'ar', 'قطع'))[1], { value: '#tok', label: 'علبة 50' });
    assert.deepEqual(plain(packOptions(undefined, 'en', 'pieces')), [{ value: '', label: 'pieces' }]);
    assert.equal(piecesOf({ ...blankDraft('product'), product_uuid: 'n', quantity: '2', unit: '#tok' }, napkin), 100);
    assert.equal(piecesOf({ ...blankDraft('product'), product_uuid: 'n', quantity: '3', unit: '' }, napkin), 3);
});

test('PK-B1 L6 + E2: a read-only line isolates only its amount; warnings are worded for one order', () => {
    const { readonlyParts, orderWarning } = lib('orderPackaging');
    const bag = { type: 'product', ingredient_uuid: null, product_uuid: 'b', name: 'Bag', name_ar: 'كيس', quantity: '2.000', unit: null, entered_unit: null, entered_quantity: null, pack_uuid: null };
    assert.deepEqual(plain(readonlyParts(bag, 'ar', () => '')), { amount: '2 ×', name: 'كيس' });
    const sugar = { type: 'ingredient', ingredient_uuid: 's', product_uuid: null, name: 'Sugar', name_ar: null, quantity: '10.0000', unit: 'g', entered_unit: 'kg', entered_quantity: '0.01', pack_uuid: null };
    assert.deepEqual(plain(readonlyParts(sugar, 'ar', (d) => `${d.quantity} ${d.unit}`)), { amount: '0.01 kg', name: 'Sugar' });
    assert.deepEqual(plain(orderWarning({ key: 'amount_safety.warnings.recipe_suggest', params: { amount: '200 l' } })), { key: 'order_packaging.warnings.recipe_suggest', params: { amount: '200 l' } });
    assert.equal(orderWarning(null), null);
});

test('every new text is in English and Arabic, and every key the new screens use exists', () => {
    assertBilingual('order_types');
    assertBilingual('order_packaging');
    for (const path of [
        'resources/js/Pages/Merchant/Catalogue/OrderTypeTicks.vue',
        'resources/js/Pages/Merchant/Inventory/OrderPackagingTab.vue',
        'resources/js/Pages/Merchant/Inventory/OrderPackagingPage.vue',
    ]) {
        const file = sfc(path);
        assertKeysExist(file.script + file.template, path);
    }
});
