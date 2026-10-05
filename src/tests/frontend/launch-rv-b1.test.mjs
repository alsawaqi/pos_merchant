// LAUNCH review add-on — fix order B-1 (review R3 of part B). The scan paths
// of every screen RUN here through the pure helpers the screens call
// (lib/scanApply.ts, lib/scanDetect.ts): M5, L8 (typed work), L9 (plain text
// never links), L10 (Purchases refusals); plus the "#" names (L9 of R3) and
// the portal parts of M2 / M3 / M4.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ar, assertKeysExist, en, get, lib, sfc } from './launch-p4-support.mjs';

const plain = (value) => JSON.parse(JSON.stringify(value));
const tok = (uuid) => `#${Buffer.from(uuid.replace(/-/g, ''), 'hex').toString('base64url')}`;
const BOTTLE = { uuid: '11111111-1111-4111-8111-111111111111', name: 'bottle', factor: '1500' };
const SMALL = { uuid: '22222222-2222-4222-8222-222222222222', name: 'bottle', factor: '500' };
BOTTLE.token = tok(BOTTLE.uuid);
SMALL.token = tok(SMALL.uuid);
const MILK = { uuid: 'milk', unit: 'ml', alt_units: [BOTTLE, SMALL], count_container_uuid: BOTTLE.uuid };
const holderOf = (uuid) => (uuid === 'milk' ? MILK : null);

test('M5 Purchases: an item-level code scanned again makes the line 2, like a container code', () => {
    const { applyPurchaseScan } = lib('scanApply');
    const lines = [];
    const deps = {
        blankLine: () => ({ itemKey: '', container_uuid: '', pieces: '', amount: '' }),
        // As Create.vue's onItemChange: the first container of an ingredient.
        onItemChange: (line) => { line.container_uuid = line.itemKey === 'ingredient:milk' ? BOTTLE.uuid : ''; line.pieces = ''; line.amount = ''; },
    };
    const sku = { found: true, item_type: 'ingredient', item: { uuid: 'milk', name: 'Milk' }, container: null, purchasable: true };
    assert.equal(applyPurchaseScan(lines, sku, deps).ok, true);
    assert.deepEqual(plain(lines), [{ itemKey: 'ingredient:milk', container_uuid: BOTTLE.uuid, pieces: '1', amount: '' }]);
    applyPurchaseScan(lines, sku, deps);
    assert.equal(lines[0].pieces, '2', 'the same item-level scan again makes it 2');
    // A container code of another size: its own line; again → 2.
    const small = { ...sku, container: { uuid: SMALL.uuid } };
    applyPurchaseScan(lines, small, deps);
    applyPurchaseScan(lines, small, deps);
    assert.equal(lines.length, 2);
    assert.equal(lines[1].container_uuid, SMALL.uuid);
    assert.equal(lines[1].pieces, '2');
    // A bought-in product with no pack: one more piece each scan.
    const cola = { found: true, item_type: 'product', item: { uuid: 'cola' }, container: null, pack: null, purchasable: true };
    applyPurchaseScan(lines, cola, deps);
    applyPurchaseScan(lines, cola, deps);
    assert.equal(lines[2].amount, '2');
    // A pack code.
    const box = { found: true, item_type: 'physical', item: { uuid: 'cup' }, pack: { uuid: 'box50' }, purchasable: true };
    applyPurchaseScan(lines, box, deps);
    assert.equal(lines[3].container_uuid, 'box50');
    assert.equal(lines[3].pieces, '1');
});

test('L10 Purchases: a scan never adds an item Purchases refuses, and says why', () => {
    const { applyPurchaseScan } = lib('scanApply');
    const lines = [];
    const deps = { blankLine: () => ({ itemKey: '', container_uuid: '', pieces: '', amount: '' }), onItemChange: () => {} };
    assert.deepEqual(plain(applyPurchaseScan(lines, { found: true, item_type: 'ingredient', item: { uuid: 'syrup' }, purchasable: false, not_purchasable_reason: 'prep' }, deps)), { ok: false, reason: 'not_purchasable_prep' });
    assert.deepEqual(plain(applyPurchaseScan(lines, { found: true, item_type: 'product', item: { uuid: 'meal' }, purchasable: false, not_purchasable_reason: 'not_bought_in' }, deps)), { ok: false, reason: 'not_purchasable' });
    assert.equal(lines.length, 0);
    const create = sfc('resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Create.vue');
    assert.match(create.script, /const outcome = applyPurchaseScan\(lines\.value, result, \{ blankLine, onItemChange \}\);/);
    assert.match(create.template, /data-test="purchase-scan-refused"/);
});

test('L8 transfers and counts keep typed work; a restock line refuses a second container', () => {
    const { applyLineScan, applyCountScan } = lib('scanApply');
    const blank = () => ({ ingredient_uuid: '', quantity: '', unit: '', containers: [] });
    const hit = (container) => ({ found: true, item_type: 'ingredient', item: { uuid: 'milk', name: 'Milk' }, container: container ? { uuid: container.uuid } : null });

    // Transfer: "2 l" typed stays as the (lowered) total; the scanned bottle is added.
    const transfer = [{ ingredient_uuid: 'milk', quantity: '2', unit: 'l', containers: [] }];
    assert.equal(applyLineScan(transfer, hit(BOTTLE), holderOf, blank, false).ok, true);
    assert.deepEqual(plain(transfer[0]), { ingredient_uuid: 'milk', quantity: '2', unit: 'l', containers: [{ container_uuid: BOTTLE.uuid, pieces: '1' }] });
    // "3" typed in bottles becomes 3 bottles; the same bottle scanned makes 4.
    const typedBottles = [{ ingredient_uuid: 'milk', quantity: '3', unit: BOTTLE.token, containers: [] }];
    applyLineScan(typedBottles, hit(BOTTLE), holderOf, blank, false);
    assert.deepEqual(plain(typedBottles[0].containers), [{ container_uuid: BOTTLE.uuid, pieces: '4' }]);
    // Another size on a transfer: a second row, the first kept.
    applyLineScan(typedBottles, hit(SMALL), holderOf, blank, false);
    assert.deepEqual(plain(typedBottles[0].containers), [{ container_uuid: BOTTLE.uuid, pieces: '4' }, { container_uuid: SMALL.uuid, pieces: '1' }]);
    // A blank first line takes the item; a new item goes on a new line.
    const fresh = [blank()];
    applyLineScan(fresh, hit(SMALL), holderOf, blank, false);
    assert.equal(fresh.length, 1);
    assert.equal(fresh[0].ingredient_uuid, 'milk');
    assert.deepEqual(plain(applyLineScan(fresh, { found: true, item_type: 'ingredient', item: { uuid: 'nope' } }, holderOf, blank, false)), { ok: false, reason: 'not_listed' });

    // Restock: 3 × bottle 1.5 l, then bottle 500 ml scanned → refused, nothing lost.
    const restock = [{ ingredient_uuid: 'milk', quantity: '', unit: '', containers: [{ container_uuid: BOTTLE.uuid, pieces: '3' }] }];
    assert.deepEqual(plain(applyLineScan(restock, hit(SMALL), holderOf, blank, true)), { ok: false, reason: 'one_container', index: 0 });
    assert.deepEqual(plain(restock[0].containers), [{ container_uuid: BOTTLE.uuid, pieces: '3' }]);
    applyLineScan(restock, hit(BOTTLE), holderOf, blank, true);
    assert.equal(restock[0].containers[0].pieces, '4');

    // Count: a typed "3 l" total is kept when a container is scanned (blind: nothing else added).
    const rows = [{ ingredient: MILK, counted: '3', unit: 'l', containers: [] }];
    assert.equal(applyCountScan(rows, hit(BOTTLE)).ok, true);
    assert.deepEqual(plain({ counted: rows[0].counted, unit: rows[0].unit, containers: rows[0].containers }), { counted: '3', unit: 'l', containers: [{ container_uuid: BOTTLE.uuid, pieces: '1' }] });
    // A count typed in the count container ('@piece') becomes that container's row.
    const legacy = [{ ingredient: MILK, counted: '2', unit: '@piece', containers: [] }];
    applyCountScan(legacy, hit(BOTTLE));
    assert.deepEqual(plain(legacy[0].containers), [{ container_uuid: BOTTLE.uuid, pieces: '3' }]);
    assert.equal(legacy[0].counted, '');
    assert.deepEqual(plain(applyCountScan(rows, { found: true, item: { uuid: 'nope' } })), { ok: false, reason: 'not_listed' });
});

test('L8 waste: a scan never replaces another item\'s typed waste; one container at a time', () => {
    const { applyWasteScan } = lib('scanApply');
    const form = { ingredient_uuid: 'butter', quantity: '2', unit: '', containers: [] };
    const hit = (container) => ({ found: true, item_type: 'ingredient', item: { uuid: 'milk' }, container: container ? { uuid: container.uuid } : null });
    const both = (uuid) => (uuid === 'milk' ? MILK : uuid === 'butter' ? { unit: 'g', alt_units: [] } : null);
    assert.deepEqual(plain(applyWasteScan(form, hit(BOTTLE), both)), { ok: false, reason: 'other_item' });
    assert.equal(form.ingredient_uuid, 'butter');
    const empty = { ingredient_uuid: '', quantity: '', unit: '', containers: [] };
    applyWasteScan(empty, hit(BOTTLE), both);
    applyWasteScan(empty, hit(BOTTLE), both);
    assert.deepEqual(plain(empty), { ingredient_uuid: 'milk', quantity: '', unit: '', containers: [{ container_uuid: BOTTLE.uuid, pieces: '2' }] });
    assert.deepEqual(plain(applyWasteScan(empty, hit(SMALL), both)), { ok: false, reason: 'one_container', index: 0 });
});

test('L9 (tester call) plain text + Enter never offers to link; a real scan or a typed EAN does', () => {
    const { scanDecision } = lib('scanDetect');
    const base = { found: false, allowedTypes: ['ingredient'], canLink: true, serverCanLink: true };
    assert.equal(scanDecision({ ...base, code: 'tomato', scanned: false, mode: 'search' }), 'silent');
    assert.equal(scanDecision({ ...base, code: 'tomato', scanned: false, mode: 'add' }), 'say_unknown');
    assert.equal(scanDecision({ ...base, code: 'TOM-1', scanned: true, mode: 'search' }), 'link');
    assert.equal(scanDecision({ ...base, code: '6291000000017', scanned: false, mode: 'search' }), 'link');
    assert.equal(scanDecision({ ...base, code: '6291000000017', scanned: false, mode: 'search', canLink: false }), 'say_unknown');
    assert.equal(scanDecision({ ...base, code: '6291000000017', scanned: true, mode: 'add', serverCanLink: false }), 'say_unknown');
    assert.equal(scanDecision({ ...base, found: true, itemType: 'physical', code: 'x', scanned: true, mode: 'add' }), 'wrong_item');
    assert.equal(scanDecision({ ...base, found: true, itemType: 'ingredient', code: 'x', scanned: false, mode: 'search' }), 'deliver');
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/components/ScanBox.vue').script, /const decision = scanDecision\(\{/);
});

test('T1 every screen\'s scan path goes through the tested helpers', () => {
    const { findScanned } = lib('scanApply');
    assert.equal(findScanned([{ uuid: 'a' }, { uuid: 'b' }], { item: { uuid: 'b' } }).uuid, 'b');
    assert.equal(findScanned([{ uuid: 'a' }], { item: { uuid: 'z' } }), null);
    const { script } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    for (const call of [
        'applyLineScan(transferForm.lines, result, ingredientByUuid, blankTransferLine, false)',
        'applyLineScan(restockForm2.lines, result, ingredientByUuid, blankRestockLine, true)',
        'applyCountScan(countRows.value, result)',
        'applyWasteScan(wasteForm, result, ingredientByUuid)',
        'findScanned(ingredients.value, result)',
        'findScanned(physicalItems.value, result)',
    ]) {
        assert.ok(script.includes(call), call);
    }
    assert.doesNotMatch(script, /function addScannedContainer/);
});

test('L9 (R3) "#10 can" is a name; only the exact token formats are tokens', () => {
    const { isContainerToken } = lib('containers');
    assert.equal(isContainerToken('#10 can'), false);
    assert.equal(isContainerToken('#abc'), false);
    assert.equal(isContainerToken(BOTTLE.token), true);
    assert.equal(isContainerToken(`#${BOTTLE.uuid}`), true);
    const { unitContainer } = lib('scanApply');
    assert.equal(unitContainer(MILK, '#10 can'), null);
    assert.equal(unitContainer(MILK, '@piece'), BOTTLE.uuid);
    assert.equal(unitContainer(MILK, SMALL.token), SMALL.uuid);
    assert.equal(unitContainer(MILK, 'l'), null);
});

test('M2 / M3 / M4 portal: move a barcode here, warn when the till marker moves, part containers with the count container', () => {
    const chips = sfc('resources/js/Pages/Merchant/Inventory/components/BarcodeChips.vue');
    assert.match(chips.template, /data-test="barcode-move"/);
    const editor = sfc('resources/js/Pages/Merchant/Inventory/components/ContainersEditor.vue');
    assert.match(editor.script, /return t\('containers\.count_move_warning', \{ container: target \? rowLabel\(target\) : '' \}\);/);
    assert.match(editor.script, /if \(warning !== null && !window\.confirm\(warning\)\)/);
    assert.match(editor.script, /updateIngredient\(props\.ingredientUuid, \{ count_container_uuid: props\.countContainerUuid \?\? null, allow_fractional_pieces: value \}\)/);
    assert.match(editor.script, /moveBarcodeHere\(conflict\.holderUuid, conflict\.code,/);
    const index = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(index.script, /\.\.\.\(ingModalMode\.value === 'create' \? \{ allow_fractional_pieces: ingForm\.allow_fractional_pieces \} : \{\}\),/);
    assert.match(index.template, /@move="moveItemBarcode"/);
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/components/PacksEditor.vue').template, /@move="moveCode\(p\.uuid\)"/);
    for (const key of ['containers.count_move_warning', 'containers.move_barcode', 'scan.one_container', 'scan.other_item', 'scan.not_listed', 'purchases_v2.scan_prep', 'purchases_v2.scan_not_bought_in']) {
        assert.equal(typeof get(en, key), 'string', key);
        assert.equal(typeof get(ar, key), 'string', key);
        assert.notEqual(get(en, key), get(ar, key), key);
    }
    for (const path of ['resources/js/Pages/Merchant/Inventory/Index.vue', 'resources/js/Pages/Merchant/Inventory/components/ContainersEditor.vue', 'resources/js/Pages/Merchant/Inventory/components/BarcodeChips.vue', 'resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Create.vue']) {
        const { script, template } = sfc(path);
        assertKeysExist(`${script}\n${template}`, path);
    }
});
