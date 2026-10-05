// LAUNCH review add-on — fix order B-2 (the orchestrator's browser check):
// 1 the "holds" boxes translate live, 2 the inner count of a nested container
// (the owner's broken bottle), 3 the warehouse dialog by container, 4 no
// translation noise, 5 costs at 3 decimals, 6 a saved item shows at once.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ar, assertKeysExist, en, get, lib, sfc } from './launch-p4-support.mjs';

const plain = (value) => JSON.parse(JSON.stringify(value));
const tok = (uuid) => `#${Buffer.from(uuid.replace(/-/g, ''), 'hex').toString('base64url')}`;
const BOTTLE = { uuid: '11111111-1111-4111-8111-111111111111', token: tok('11111111-1111-4111-8111-111111111111'), name: 'bottle', factor: '1000', display_name: 'bottle 1 l', display_name_ar: 'زجاجة 1 l' };
const CRATE = { uuid: '22222222-2222-4222-8222-222222222222', token: tok('22222222-2222-4222-8222-222222222222'), name: 'crate', factor: '12000', contains_unit_uuid: BOTTLE.uuid, contains_quantity: '12', display_name: 'crate (12 × bottle 1 l)', display_name_ar: 'صندوق (12 × زجاجة 1 l)' };
const MILK = { unit: 'ml', alt_units: [BOTTLE, CRATE] };

const CREATE = 'resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Create.vue';
const INDEX = 'resources/js/Pages/Merchant/Inventory/Index.vue';
const ROWS = 'resources/js/Pages/Merchant/Inventory/components/ContainerRows.vue';
const EDITOR = 'resources/js/Pages/Merchant/Inventory/components/ContainersEditor.vue';
const DIALOG = 'resources/js/Pages/Merchant/Inventory/IngredientStockDialog.vue';

test('B-2 item 4 a translation only when it adds something: never a whole l / kg in ml / g', () => {
    const { compoundAmount, translateAmount } = lib('amountSafety');
    assert.equal(compoundAmount('23', 'l'), null, '23 l says it already');
    assert.equal(compoundAmount('2', 'kg'), null);
    assert.equal(compoundAmount('2.5', 'l'), '2 l 500 ml');
    assert.equal(compoundAmount('1500', 'ml'), '1.5 l');
    assert.equal(compoundAmount('0.5', 'l'), '500 ml');
    assert.equal(compoundAmount('500', 'ml'), '0.5 l');
    assert.equal(compoundAmount('50', 'ml'), null, 'a small amount says nothing new in l');
    assert.deepEqual(plain(translateAmount({ amount: '23', unit: 'l', storedUnit: 'ml' })), []);
    assert.deepEqual(plain(translateAmount({ amount: '3', unit: CRATE.token, storedUnit: 'ml', containers: MILK.alt_units })), ['36 × bottle 1 l', '36 l']);
});

test('B-2 item 1 the "holds" boxes translate live: 500 ml = 0.5 l, a crate of 12 × bottle 1 l = 12 l', () => {
    const { holdsTranslation } = lib('amountSafety');
    assert.deepEqual(plain(holdsTranslation({ mode: 'amount', amount: '500', unit: 'ml', storedUnit: 'ml' })), ['0.5 l']);
    assert.deepEqual(plain(holdsTranslation({ mode: 'amount', amount: '1.5', unit: 'l', storedUnit: 'ml' })), ['1 l 500 ml']);
    assert.deepEqual(plain(holdsTranslation({ mode: 'amount', amount: '1', unit: 'l', storedUnit: 'ml' })), [], 'a whole litre needs no translation');
    assert.deepEqual(plain(holdsTranslation({ mode: 'nested', storedUnit: 'ml', childFactor: '1000', quantity: '12' })), ['12 l']);
    assert.deepEqual(plain(holdsTranslation({ mode: 'nested', storedUnit: 'piece', childFactor: '24', quantity: '4' })), ['96 piece']);
    assert.deepEqual(plain(holdsTranslation({ mode: 'nested', storedUnit: 'ml', childFactor: null, quantity: '12' })), []);

    // Every "holds" row of the form shows it: new drafts, saved rows, the add row.
    const { script, template } = sfc(EDITOR);
    assert.match(script, /holdsTranslation\(\{ mode: source\.mode, amount: source\.amount, unit: source\.unit, storedUnit: props\.storedUnit, childFactor, quantity: source\.contains_quantity \}\)/);
    assert.equal(template.match(/data-test="container-holds-translation"/g)?.length, 3);
    assert.match(template, /holdsLine\(d, d\.contains_index === null \? null : draftFactor\(d\.contains_index\)\)/);
    assert.match(template, /holdsLine\(edits\[row\.uuid\], rowFactor\(edits\[row\.uuid\]\.contains_unit_uuid\)\)/);
    assert.match(template, /holdsLine\(fresh, rowFactor\(fresh\.contains_unit_uuid\)\)/);
});

test('B-2 item 2 the inner count of a nested container: 24 by default, 23 for a broken bottle, never 25', () => {
    const { innerCount, innerRaised, rowsCap, containerRowsPayload, containerLineText } = lib('containers');
    const full = innerCount(MILK, { container_uuid: CRATE.uuid, pieces: '2' });
    assert.equal(full.full, 24);
    assert.equal(full.count, 24);
    assert.equal(full.lowered, false);
    assert.equal(full.leaf.uuid, BOTTLE.uuid);
    const broken = innerCount(MILK, { container_uuid: CRATE.uuid, pieces: '2', leaf_pieces: '23' });
    assert.equal(broken.count, 23);
    assert.equal(broken.lowered, true);
    assert.equal(broken.raised, false);
    assert.equal(innerCount(MILK, { container_uuid: CRATE.uuid, pieces: '2', leaf_pieces: '25' }).raised, true);
    assert.equal(innerCount(MILK, { container_uuid: BOTTLE.uuid, pieces: '2' }), null, 'a container that holds an amount has no inner count');

    // The amount follows the inner count (still lowerable from there).
    assert.equal(rowsCap(MILK, [{ container_uuid: CRATE.uuid, pieces: '2' }]), 24000);
    assert.equal(rowsCap(MILK, [{ container_uuid: CRATE.uuid, pieces: '2', leaf_pieces: '23' }]), 23000);
    assert.equal(rowsCap(MILK, [{ container_uuid: CRATE.uuid, pieces: '2', leaf_pieces: '25' }]), 24000, 'a raised count never raises the cap');
    assert.equal(innerRaised(MILK, [{ container_uuid: CRATE.uuid, pieces: '2', leaf_pieces: '25' }]), true);
    assert.equal(innerRaised(MILK, [{ container_uuid: CRATE.uuid, pieces: '2', leaf_pieces: '23' }]), false);

    // Sent only when typed (blank = the full count).
    assert.deepEqual(plain(containerRowsPayload([
        { container_uuid: CRATE.uuid, pieces: '2', leaf_pieces: '23' },
        { container_uuid: CRATE.uuid, pieces: '1', leaf_pieces: '' },
        { container_uuid: BOTTLE.uuid, pieces: '' },
        { container_uuid: '', pieces: '3' },
    ])), [
        { container_uuid: CRATE.uuid, pieces: '2', leaf_pieces: '23' },
        { container_uuid: CRATE.uuid, pieces: '1' },
    ]);

    const line = containerLineText({ container: CRATE, all: MILK.alt_units, pieces: '2', amountStored: 23000, storedUnit: 'ml', lineCost: '4.8', leafPieces: '23' });
    assert.equal(line.leaf, '23 × bottle 1 l');
    assert.equal(line.amount, '23 l');
});

test('B-2 item 2 the screens show and send the inner count (purchase, transfer, count, waste)', () => {
    const rows = sfc(ROWS);
    assert.match(rows.script, /leaf_pieces\?: string \| number;/);
    assert.match(rows.template, /data-test="container-inner-pieces"/);
    assert.match(rows.template, /update\(i, \{ leaf_pieces: \(\$event\.target as HTMLInputElement\)\.value \}\)/);
    assert.match(rows.template, /:placeholder="String\(inner\(row\)!\.full\)"/);
    assert.match(rows.template, /data-test="container-inner-raised"/);
    // Another container or count starts the inner count again (full).
    assert.match(rows.script, /patch\.container_uuid !== undefined \|\| patch\.pieces !== undefined \? \{ leaf_pieces: '' \} : \{\}/);

    const create = sfc(CREATE);
    assert.match(create.template, /v-model="line\.leaf_pieces"/);
    assert.match(create.template, /data-test="line-inner-pieces"/);
    assert.match(create.template, /data-test="line-inner-raised"/);
    assert.match(create.script, /if \(container\) return rowsCap\(lineIngredient\(line\), \[line\]\);/);
    assert.match(create.script, /\? \{ leaf_pieces: String\(l\.leaf_pieces\)\.trim\(\) \} : \{\}/);
    assert.match(create.script, /leafPieces: line\.leaf_pieces/);

    const index = sfc(INDEX);
    assert.match(index.script, /function countContainerRows\(r: CountRow\): ReturnType<typeof containerRowsPayload> \{\s*\/\/[^\n]*\n\s*return containerRowsPayload\(r\.containers\);/);
    assert.match(index.script, /const containers = containerRowsPayload\(l\.containers\);/);
    assert.match(index.script, /leaf_pieces: String\(container\.leaf_pieces\)\.trim\(\)/);
    assert.match(index.script, /if \(innerRaised\(ingredient, line\.containers\)\) return true;/);
    // A restock request asks for containers; there is no broken one yet.
    assert.match(index.template, /single\s+:inner="false"/);
});

test('B-2 item 3 the warehouse dialog: Allocate per branch and Transfer by container, the total lowered only', () => {
    const { script, template } = sfc(DIALOG);
    assert.match(template, /<ContainerRows\s+v-else-if="ingredient"\s+v-model:rows="row\.containers"\s+v-model:amount="row\.amount"\s+v-model:amount-unit="row\.amount_unit"/);
    assert.match(template, /<ContainerRows\s+v-if="transferForm\.containers\.length > 0 && ingredient"\s+v-model:rows="transferForm\.containers"/);
    assert.match(template, /data-test="allocate-by-container"/);
    assert.match(template, /data-test="warehouse-transfer-by-container"/);
    assert.match(template, /:disabled="busy \|\| allocateRaised"/);
    assert.match(template, /:disabled="busy \|\| transferRaised"/);
    assert.match(script, /lines\.push\(\{ branch_uuid: r\.branch_uuid, containers, \.\.\.\(total !== '' \? \{ quantity: total, amount_unit: r\.amount_unit \|\| null \} : \{\}\) \}\);/);
    assert.match(script, /\? \{ containers, \.\.\.\(total !== '' \? \{ quantity: total, amount_unit: transferForm\.amount_unit \|\| null \} : \{\}\) \}/);
    assert.match(script, /if \(innerRaised\(ing, rows\)\) return true;/);
    assert.match(script, /const containers = containerRowsPayload\(r\.containers\);/);
    assert.match(script, /const containers = containerRowsPayload\(transferForm\.containers\);/);
});

test('B-2 item 5 costs read at 3 decimals per l / kg / piece; per the larger unit when that is 0.000', () => {
    const { costPerText, containerLineText } = lib('containers');
    assert.deepEqual(plain(costPerText(4.8, 23000, 'ml', null)), { cost: '0.209', unit: 'l' }, 'never 0.208696');
    assert.deepEqual(plain(costPerText(4.8, 24000, 'ml', null)), { cost: '0.200', unit: 'l' });
    assert.deepEqual(plain(costPerText(1, 3, 'piece', null)), { cost: '0.333', unit: 'piece' });
    // 0.0004 per l rounds to 0.000: per the line's container, or per 1000 l.
    assert.deepEqual(plain(costPerText(0.4, 1000000, 'ml', { label: 'tank 1000 l', pieces: 1 })), { cost: '0.400', unit: 'tank 1000 l' });
    assert.deepEqual(plain(costPerText(0.4, 1000000, 'ml', null)), { cost: '0.400', unit: '1000 l' });
    assert.deepEqual(plain(costPerText(0.4, 1000000, 'g', null)), { cost: '0.400', unit: '1000 kg' });
    assert.equal(costPerText(0, 1000, 'ml', null), null);
    const line = containerLineText({ container: CRATE, all: MILK.alt_units, pieces: '2', amountStored: 23000, storedUnit: 'ml', lineCost: '4.8', leafPieces: '23' });
    assert.deepEqual(plain(line.costPer), { cost: '0.209', unit: 'l' });
    // A product line too (per piece, or per pack when that is 0.000).
    assert.match(sfc(CREATE).script, /const per = costPerText\(cost, amount, 'piece', /);
});

test('B-2 item 6 a saved item shows in the list at once; an older read never overwrites a newer one', () => {
    const { upsertByUuid, latestOnly } = lib('listUpsert');
    const list = [{ uuid: 'a', name: 'Apple' }, { uuid: 'c', name: 'cumin' }, { uuid: 'm', name: 'Milk' }];
    assert.deepEqual(plain(upsertByUuid(list, { uuid: 'b', name: 'Butter' })).map((r) => r.uuid), ['a', 'b', 'c', 'm'], 'in name order');
    assert.deepEqual(plain(upsertByUuid(list, { uuid: 'z', name: 'Zaatar' })).map((r) => r.uuid), ['a', 'c', 'm', 'z']);
    assert.deepEqual(plain(upsertByUuid(list, { uuid: 'm', name: 'Milk 2' })), [{ uuid: 'a', name: 'Apple' }, { uuid: 'c', name: 'cumin' }, { uuid: 'm', name: 'Milk 2' }], 'an edit replaces its row');
    assert.equal(list.length, 3, 'the list itself is not changed');
    const reads = latestOnly();
    const first = reads.start();
    const second = reads.start();
    assert.equal(reads.isLatest(first), false);
    assert.equal(reads.isLatest(second), true);

    const { script } = sfc(INDEX);
    const at = script.indexOf('async function submitIngredient');
    const body = script.slice(at, script.indexOf('\n}\n', at));
    assert.match(body, /saved = \(await createIngredient\(\{/);
    assert.match(body, /ingredients\.value = upsertByUuid\(ingredients\.value, saved\);/);
    assert.ok(body.indexOf('upsertByUuid(') < body.indexOf('await fetchIngredients();'), 'shown before the list is re-read');
    assert.match(script, /const ticket = ingredientsRead\.start\(\);[\s\S]*?if \(!ingredientsRead\.isLatest\(ticket\)\) return;\s*ingredients\.value = response\.data;/);
});

test('B-2 every new text is in English and Arabic', () => {
    for (const key of ['containers.inner', 'containers.inner_hint', 'containers.inner_raised', 'containers.by_container', 'containers.by_amount', 'containers.total_raised_summary']) {
        assert.ok(typeof get(en, key) === 'string' && get(en, key) !== '', `en ${key}`);
        assert.ok(typeof get(ar, key) === 'string' && get(ar, key) !== '' && get(ar, key) !== get(en, key), `ar ${key}`);
    }
    for (const path of [ROWS, CREATE, DIALOG, EDITOR]) {
        const { script, template } = sfc(path);
        assertKeysExist(`${script}\n${template}`, path);
    }
});
