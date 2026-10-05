// LAUNCH review add-on, Part B — the pure libraries: E unit safety
// (lib/amountSafety.ts), the container helpers (lib/containers.ts) and the
// unit pickers keyed by container token (A2).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { lib } from './launch-p4-support.mjs';

/** Values made in the lib's own realm, as plain data for deepEqual. */
const plain = (value) => JSON.parse(JSON.stringify(value));
const tok = (uuid) => `#${Buffer.from(uuid.replace(/-/g, ''), 'hex').toString('base64url')}`;
const BOTTLE_1L = { uuid: '11111111-1111-4111-8111-111111111111', token: tok('11111111-1111-4111-8111-111111111111'), name: 'bottle', factor: '1000', display_name: 'bottle 1 l', display_name_ar: 'زجاجة 1 l' };
const CRATE = { uuid: '22222222-2222-4222-8222-222222222222', token: tok('22222222-2222-4222-8222-222222222222'), name: 'crate', factor: '12000', contains_unit_uuid: BOTTLE_1L.uuid, contains_quantity: '12', display_name: 'crate (12 × bottle 1 l)', display_name_ar: 'صندوق (12 × زجاجة 1 l)' };
const BOTTLE_15 = { uuid: '33333333-3333-4333-8333-333333333333', token: tok('33333333-3333-4333-8333-333333333333'), name: 'bottle', factor: '1500', display_name: 'bottle 1.5 l', display_name_ar: 'زجاجة 1.5 l' };
const MILK = { unit: 'ml', alt_units: [BOTTLE_1L, CRATE, BOTTLE_15] };

test('E1 the compound translation: 2.5 l = 2 l 500 ml, 1500 ml = 1.5 l', () => {
    const { compoundAmount, translateAmount } = lib('amountSafety');
    assert.equal(compoundAmount('2.5', 'l'), '2 l 500 ml');
    assert.equal(compoundAmount('1500', 'ml'), '1.5 l');
    assert.equal(compoundAmount('2.25', 'kg'), '2 kg 250 g');
    assert.deepEqual(plain(translateAmount({ amount: '2.5', unit: 'l', storedUnit: 'ml' })), ['2 l 500 ml']);
    assert.deepEqual(plain(translateAmount({ amount: '', unit: 'l', storedUnit: 'ml' })), []);
});

test('E1 a container translates to its leaf and its amount: 3 crates = 36 × bottle 1 l = 36 l', () => {
    const { translateAmount } = lib('amountSafety');
    assert.deepEqual(plain(translateAmount({ amount: '3', unit: CRATE.token, storedUnit: 'ml', containers: MILK.alt_units })), ['36 × bottle 1 l', '36 l']);
    assert.deepEqual(plain(translateAmount({ amount: '2', unit: BOTTLE_15.token, storedUnit: 'ml', containers: MILK.alt_units })), ['3 l']);
    // A counted item gets only the container translation.
    assert.deepEqual(plain(translateAmount({ amount: '5', unit: '', storedUnit: 'piece' })), []);
});

test('E2 the warnings: recipe portion, container size, purchase cost, minimum stock', () => {
    const { recipeLineWarning, containerSizeWarning, purchaseCostWarning, thresholdWarning } = lib('amountSafety');
    const big = recipeLineWarning({ amount: '200', unit: 'l', storedUnit: 'ml' });
    assert.equal(big.key, 'amount_safety.warnings.recipe_suggest');
    assert.equal(big.params.suggestion, '200 ml');
    assert.equal(recipeLineWarning({ amount: '200', unit: 'ml', storedUnit: 'ml' }), null);
    assert.equal(recipeLineWarning({ amount: '60', unit: '', storedUnit: 'piece' }).key, 'amount_safety.warnings.recipe');

    assert.equal(containerSizeWarning('150000', 'g').key, 'amount_safety.warnings.container_big');
    assert.equal(containerSizeWarning('3', 'ml').key, 'amount_safety.warnings.container_small');
    assert.equal(containerSizeWarning('1500', 'ml'), null);
    assert.equal(containerSizeWarning('24', 'piece'), null);

    // 5 × above / below the current cost per stored unit warns; no cost yet (0) and a free line never.
    assert.equal(purchaseCostWarning(0.001, '0.00015').key, 'amount_safety.warnings.cost_high');
    assert.equal(purchaseCostWarning(0.00002, '0.00015').key, 'amount_safety.warnings.cost_low');
    assert.equal(purchaseCostWarning(0.0002, '0.00015'), null);
    assert.equal(purchaseCostWarning(0.0002, '0'), null);
    assert.equal(purchaseCostWarning(0, '0.00015'), null);

    assert.equal(thresholdWarning('500', ['1500', '1000'], 'ml').key, 'amount_safety.warnings.threshold_small');
    assert.equal(thresholdWarning('3000', ['1500'], 'ml'), null);
    assert.equal(thresholdWarning(null, ['1500'], 'ml'), null);
});

test('A2 the container token matches the server: "#" + base64url of the uuid bytes', () => {
    const { containerToken, isContainerToken, findContainer } = lib('containers');
    for (const uuid of ['00000000-0000-0000-0000-000000000000', '123e4567-e89b-12d3-a456-426614174000', BOTTLE_1L.uuid]) {
        const expected = `#${Buffer.from(uuid.replace(/-/g, ''), 'hex').toString('base64url')}`;
        assert.equal(containerToken(uuid), expected, uuid);
        assert.equal(containerToken(uuid).length, 23, 'fits recipe entered_unit varchar(32)');
        assert.equal(isContainerToken(containerToken(uuid)), true);
    }
    assert.equal(isContainerToken('bottle'), false);
    assert.equal(isContainerToken('@piece'), false);
    // Found by uuid or token; two "bottle" sizes stay apart.
    assert.equal(findContainer(MILK, BOTTLE_15.uuid).factor, '1500');
    assert.equal(findContainer(MILK, BOTTLE_1L.token).factor, '1000');
});

test('C1 / D1 the amount fills in as pieces × size and may be lowered, never raised', () => {
    const { capOf, rowsCap, amountInStored, amountProblem } = lib('containers');
    assert.equal(capOf([{ factor: '1500', pieces: '3' }, { factor: '500', pieces: '5' }]), 7000);
    assert.equal(rowsCap(MILK, [{ container_uuid: CRATE.uuid, pieces: '2' }]), 24000);
    assert.equal(amountInStored('2', 'l', 'ml'), 2000);
    assert.equal(amountInStored('', 'l', 'ml'), null);
    assert.equal(amountProblem(24000, 24000), null);
    assert.equal(amountProblem(23500, 24000), null, 'lowered is fine');
    assert.equal(amountProblem(24001, 24000), 'raised');
    assert.equal(amountProblem(0, 24000), 'not_positive');
    assert.equal(amountProblem(0, 24000, true), null, 'a count may be 0');
    assert.equal(amountProblem(null, 24000), null, 'blank = what the pieces hold');
});

test('C1 the live line: "= 24 × bottle 1 l = 24 l · 0.200 per l"; a free line says so', () => {
    const { containerLineText } = lib('containers');
    const priced = containerLineText({ container: CRATE, all: MILK.alt_units, pieces: '2', amountStored: 24000, storedUnit: 'ml', lineCost: '4.8' });
    assert.equal(priced.leaf, '24 × bottle 1 l');
    assert.equal(priced.amount, '24 l');
    assert.deepEqual(plain(priced.costPer), { cost: '0.200', unit: 'l' });
    assert.equal(priced.free, false);
    const free = containerLineText({ container: BOTTLE_15, all: MILK.alt_units, pieces: '4', amountStored: 6000, storedUnit: 'ml', lineCost: '0' });
    assert.equal(free.leaf, null);
    assert.equal(free.amount, '6 l');
    assert.equal(free.costPer, null);
    assert.equal(free.free, true);
});

test('B / F the breakdown line and "the same scan again makes it 2"', () => {
    const { breakdownText, plusOne, scanTargetIndex } = lib('containers');
    const rows = [
        { container_uuid: BOTTLE_15.uuid, display_name: 'bottle 1.5 l', display_name_ar: 'زجاجة 1.5 l', pieces: '3', amount: '4500' },
        { container_uuid: BOTTLE_1L.uuid, display_name: 'bottle 1 l', display_name_ar: 'زجاجة 1 l', pieces: '5', amount: '5000' },
    ];
    assert.equal(breakdownText(rows), '3 × bottle 1.5 l + 5 × bottle 1 l');
    assert.equal(breakdownText(rows, 'ar'), '3 × زجاجة 1.5 l + 5 × زجاجة 1 l');
    assert.equal(breakdownText([]), '');
    assert.equal(plusOne('1'), '2');
    assert.equal(plusOne(''), '1');
    assert.equal(scanTargetIndex([{ c: 'a' }, { c: 'b' }], (l) => l.c === 'b'), 1);
});

test('A2 every unit picker lists containers by token with their sizes (the same word, two sizes)', () => {
    const { entryUnitOptions, conversionsOf } = lib('itemKind');
    const { recipeUnitOptions } = lib('recipeUnits');
    const { purchaseUnitOptions, purchaseUnitName } = lib('purchaseUnits');
    for (const [label, options] of [
        ['entry', entryUnitOptions(MILK, 'en')],
        ['recipe', recipeUnitOptions(MILK, 'en')],
        ['purchase', purchaseUnitOptions(MILK, 'en')],
    ]) {
        const bottles = options.filter((o) => String(o.label).startsWith('bottle'));
        assert.deepEqual(plain(bottles.map((o) => o.value)).sort(), [BOTTLE_1L.token, BOTTLE_15.token].sort(), label);
        assert.ok(options.some((o) => o.value === CRATE.token && o.label === 'crate (12 × bottle 1 l)'), label);
    }
    assert.ok(entryUnitOptions(MILK, 'ar').some((o) => o.label === 'زجاجة 1.5 l'));
    assert.equal(purchaseUnitName(MILK, BOTTLE_15.token, 'en'), 'bottle 1.5 l');
    assert.ok(conversionsOf('3000', MILK, 'en').includes('2 × bottle 1.5 l'));
    // A3 — the count container is a container row: no separate '@piece' option.
    const counted = { ...MILK, piece_unit_label: 'bottle', units_per_piece: '1500', count_container_uuid: BOTTLE_15.uuid };
    assert.equal(entryUnitOptions(counted, 'en').some((o) => o.value === '@piece'), false);
    assert.equal(recipeUnitOptions(counted, 'en').some((o) => o.value === '@piece'), false);
});
