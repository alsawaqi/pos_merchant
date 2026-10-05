// LAUNCH review add-on, Part B — the inventory screens: A1 no cost field,
// A2/A3 containers, A4/A5 SKU + barcodes, D3 packs, B breakdown, C purchases,
// D documents by container, E amount boxes, F scan box, and the locales.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ar, assertBilingual, assertKeysExist, en, exists, get, read, sfc } from './launch-p4-support.mjs';

const INDEX = 'resources/js/Pages/Merchant/Inventory/Index.vue';
const CREATE = 'resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Create.vue';
const COMPONENTS = ['AmountConfirmDialog', 'AmountInput', 'BarcodeChips', 'ContainerRows', 'ContainersEditor', 'PacksEditor', 'ScanBox', 'ScanLinkDialog', 'StockBreakdown']
    .map((name) => `resources/js/Pages/Merchant/Inventory/components/${name}.vue`);

/** The opening tag (up to the marker) that carries data-test="marker". */
function tagOf(template, marker) {
    const at = template.indexOf(`data-test="${marker}"`);
    assert.ok(at >= 0, marker);
    return template.slice(template.lastIndexOf('<', at), at);
}

/** The text of one modal / form, from its marker to the end of its <form>. */
function block(template, marker) {
    const at = template.indexOf(marker);
    assert.ok(at >= 0, marker);
    return template.slice(at, template.indexOf('</form>', at));
}

test('A1 the ingredient form has no cost box; the list says "Current cost" or "No cost yet"', () => {
    const { script, template } = sfc(INDEX);
    assert.doesNotMatch(template, /v-model="ingForm\.default_unit_cost"/);
    assert.doesNotMatch(script, /default_unit_cost: /, 'the payload never sends a cost');
    assert.match(template, /data-test="cost-from-purchases"/);
    assert.match(template, /v-if="ing\.has_cost === false"[^>]*data-test="no-cost-yet">\{\{ t\('purchases_v2\.no_cost_yet'\) \}\}/);
    assert.equal(en.inventory.table.default_cost, 'Current cost');
    assert.equal(ar.inventory.table.default_cost, 'التكلفة الحالية');
    // The waste preview and the prep editor say "No cost yet" too.
    assert.match(script, /if \(ing\.has_cost === false \|\| !\(parseFloat\(ing\.default_unit_cost\) > 0\)\) return null;/);
    assert.match(template, /v-if="wasteCostPreview === null"[^>]*>\{\{ t\('purchases_v2\.no_cost_yet'\) \}\}/);
    const prep = sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue');
    assert.match(prep.template, /data-test="prep-line-no-cost"/);
    assert.match(prep.template, /data-test="prep-cost-incomplete"/);
    // The branch "Add stock" (refused by single stock-in, reads the old default cost) is hidden.
    const branch = sfc('resources/js/Pages/Merchant/Branches/Show.vue');
    assert.match(branch.template, /v-if="canInventoryManage && !singleStockIn"[^>]*data-test="branch-add-stock"|v-if="canInventoryManage && !singleStockIn"[\s\S]{0,300}data-test="branch-add-stock"/);
});

test('A2 / A3 / A5 the containers editor replaces the count container field and the pack sizes', () => {
    const { script, template } = sfc(INDEX);
    for (const path of COMPONENTS) assert.ok(exists(path), path);
    assert.match(template, /<ContainersEditor\s[^>]*v-model:drafts="containerDrafts"/);
    assert.doesNotMatch(template, /data-test="count-container"|ingForm\.piece_unit_label|ingForm\.container_amount/);
    assert.doesNotMatch(template, /packSizeDrafts|altUnitDrafts/);
    assert.match(script, /pack_sizes: containers/);
    const editor = sfc(COMPONENTS[4]);
    assert.match(editor.template, /containers\.count_marker/);
    assert.match(editor.template, /data-test="container-size-locked"/);
    assert.match(editor.script, /contains_index/, 'nested drafts point at another row');
    // A5 — barcodes on the item and per container.
    assert.match(template, /data-test="item-barcodes"/);
    assert.match(editor.template, /<BarcodeChips/);
});

test('A4 the SKU: a box on both forms (blank = generated), a list column, "Generate missing SKUs"', () => {
    const { script, template } = sfc(INDEX);
    assert.match(template, /v-model="ingForm\.sku"/);
    assert.match(template, /v-model="physicalItemForm\.sku"/);
    assert.match(script, /sku: ingForm\.sku\.trim\(\) \|\| null/);
    assert.match(script, /sku: physicalItemForm\.sku\.trim\(\) \|\| null/);
    assert.match(template, /data-test="ingredient-sku"/);
    assert.match(template, /data-test="physical-sku"/);
    assert.match(template, /data-test="generate-skus"/);
    assert.match(read('resources/js/lib/api/physicalItems.ts'), /\/api\/physical-items\/generate-skus/);
});

test('D3 physical items: a packs editor, purchases by pack, the stock dialog may count in a pack', () => {
    const { template } = sfc(INDEX);
    assert.match(template, /<PacksEditor\s/);
    assert.match(template, /:packs="physicalItemStockTarget\?\.packs \?\? \[\]"/);
    const dialog = sfc('resources/js/Pages/Merchant/Catalogue/ProductStockDialog.vue');
    assert.match(dialog.template, /data-test="stock-dialog-pack"/);
    for (const field of ['quantity: inPieces(distributeForm.quantity)', 'quantity: inPieces(receiveForm.quantity)', 'quantity: inPieces(transferForm.quantity)', 'signed_quantity: inPieces(adjustForm.signed_quantity)', 'quantity: inPieces(wasteForm.quantity)']) {
        assert.ok(dialog.script.includes(field), field);
    }
    const create = sfc(CREATE);
    assert.match(create.script, /\{ pack_uuid: l\.container_uuid \}/);
});

test('B the stock pages show the breakdown; the warehouse can "Correct containers"', () => {
    const { template } = sfc(INDEX);
    assert.match(template, /<StockBreakdown\s[^>]*:rows="row\.breakdown"/);
    const dialog = sfc('resources/js/Pages/Merchant/Inventory/IngredientStockDialog.vue');
    assert.match(dialog.template, /<StockBreakdown :rows="summary\.central_breakdown"/);
    assert.match(dialog.template, /<StockBreakdown :rows="b\.breakdown"/);
    assert.match(dialog.template, /data-test="correct-containers"/);
    assert.match(dialog.script, /correctWarehouseContainers\(props\.ingredientUuid as string, \{ containers, note: correctNote\.value \|\| null \}\)/);
    assert.match(dialog.script, /!isBranchRestricted\.value && itemContainers\.value\.length > 0/);
    assert.match(read('resources/js/lib/api/ingredientStock.ts'), /\/api\/ingredients\/\$\{uuid\}\/stock\/containers/);
});

test('C purchases: item → container → pieces → amount (lower only) → price paid (required, 0 = free)', () => {
    const { script, template } = sfc(CREATE);
    for (const marker of ['line-container', 'line-pieces', 'line-amount', 'line-cost', 'line-live', 'line-raised', 'line-needs-price', 'split-in-pieces']) {
        assert.match(template, new RegExp(`data-test="${marker}"`), marker);
    }
    assert.match(template, /v-model="line\.line_cost" type="number" step="0\.001" min="0"[^>]*required/);
    assert.doesNotMatch(script, /unit_price/, 'no price per unit any more');
    assert.match(script, /line_cost: l\.line_cost,/);
    assert.match(script, /if \(lineOverDistributed\(l\) \|\| lineRaised\(l\) \|\| lineNeedsPrice\(l\)\)/);
    assert.match(script, /return byPieces\(line\) && \(lineInner\(line\)\?\.raised === true \|\| amountProblem\(typedAmount\(line\), lineCap\(line\)\) === 'raised'\);/);
    // A container line sends pieces (+ a lowered amount); the split is in pieces.
    assert.match(script, /pieces: l\.pieces,/);
    assert.match(script, /\{ branch_uuid: a\.branch_uuid, pieces: a\.quantity \}/);
    // The default container is the first one.
    assert.match(script, /line\.container_uuid = ing \? \(containersOf\(ing\)\[0\]\?\.uuid \?\? ''\) : '';/);
    // E2 — a price far from the current cost asks "Is this right?".
    assert.match(script, /purchaseCostWarning\(lineCost\(l\) \/ amount, ing\.default_unit_cost\)/);
    assert.match(template, /<AmountConfirmDialog :warnings="amountWarnings" @answer="answerAmounts" \/>/);
    // Show reads containers back.
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Show.vue').template, /data-test="receipt-line-container"/);
    // Renamed "Purchases".
    assert.equal(en.nav.purchase_receipts, 'Purchases');
    assert.equal(ar.nav.purchase_receipts, 'المشتريات');
    assert.equal(en.purchase_receipts.title, 'Purchases');
    assert.equal(ar.purchase_receipts.title, 'المشتريات');
});

test('D transfers, counts, waste and restock by container; counts stay blind', () => {
    const { script, template } = sfc(INDEX);
    const transfer = block(template, 'id="branch-transfer-form"');
    assert.match(transfer, /<ContainerRows\s[\s\S]*?v-model:rows="line\.containers"/);
    const count = block(template, 'id="count-modal-form"');
    assert.match(count, /<ContainerRows[\s\S]*?allow-zero/);
    assert.doesNotMatch(count, /breakdown|on_book|expected/, 'never shows the books');
    assert.match(script, /r\.containers = \[\{ container_uuid: first\.uuid, pieces: '' \}\];/, 'a count row starts empty, never pre-filled');
    const waste = block(template, 'id="waste-modal-form"');
    assert.match(waste, /<ContainerRows[\s\S]*?single/);
    const restock = block(template, 'id="restock-request-form"');
    assert.match(restock, /<ContainerRows[\s\S]*?single/);
    // Payloads.
    assert.match(script, /return \{ ingredient_uuid: l\.ingredient_uuid, containers, \.\.\.\(quantity !== '' \? \{ quantity, unit: wireUnit\(l\.unit\) \} : \{\}\) \};/);
    assert.match(script, /ingredient_uuid: r\.ingredient\.uuid,\s*containers,/);
    assert.match(script, /\{\s*container_uuid: container\.container_uuid,\s*pieces: container\.pieces,/);
    assert.match(script, /container_uuid: container\.container_uuid,\s*pieces: container\.pieces,/);
    // A raised total blocks the save.
    for (const flag of ['transferHasRaised', 'countHasRaised', 'wasteRaised', 'restockHasRaised']) {
        assert.match(template, new RegExp(`:disabled="[^"]*${flag}`), flag);
    }
});

test('E every inventory amount box translates live; the ingredient save asks "Is this right?"', () => {
    const { script, template } = sfc(INDEX);
    for (const marker of ['min-stock-input', 'transfer-quantity', 'count-amount', 'waste-quantity', 'restock-quantity', 'adjust-quantity', 'allocate-quantity', 'suggestion-quantity']) {
        assert.ok(tagOf(template, marker).startsWith('<AmountInput'), marker);
    }
    assert.match(script, /if \(!\(await confirmAmounts\(ingredientWarnings\(unit\)\)\)\) return;/);
    assert.match(template, /<AmountConfirmDialog :warnings="amountWarnings" @answer="answerAmounts" \/>/);
    const input = sfc(COMPONENTS[1]);
    assert.match(input.script, /translateAmount\(/);
    const dialog = sfc('resources/js/Pages/Merchant/Inventory/IngredientStockDialog.vue');
    assert.equal((dialog.template.match(/<AmountInput /g) ?? []).length, 5, 'distribute, receive, allocate, transfer, adjust');
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue').template, /data-test="prep-line-amount"/);
});

test('F the scan box: lists search by name / SKU, documents add by scan, unknown codes link', () => {
    const { template } = sfc(INDEX);
    for (const marker of ['ingredient-search', 'physical-search', 'stock-search', 'transfer-scan', 'count-scan', 'waste-scan', 'restock-scan']) {
        assert.match(template, new RegExp(`<ScanBox[^>]*data-test="${marker}"|data-test="${marker}"[\\s\\S]{0,80}@found`), marker);
    }
    assert.match(sfc(CREATE).template, /data-test="purchase-scan"/);
    const box = sfc(COMPONENTS[6]);
    assert.match(box.script, /scanCode\(code\)/);
    // (Fix order B-1 — the link decision is lib/scanDetect scanDecision, given canLink and the server's can_link.)
    assert.match(box.script, /canLink: props\.canLink,\s*serverCanLink: res\.data\.can_link,/);
    assert.match(sfc(COMPONENTS[7]).script, /linkScannedCode\(/);
    const api = read('resources/js/lib/api/inventoryCodes.ts');
    assert.match(api, /\/api\/inventory\/scan\?code=\$\{encodeURIComponent\(code\)\}/);
    assert.match(api, /\/api\/inventory\/scan\/link/);
});

test('every new string exists in English and Arabic, in the four blocks right after item_kind', () => {
    for (const section of ['containers', 'scan', 'amount_safety', 'purchases_v2']) assertBilingual(section);
    assert.deepEqual(Object.keys(en).slice(0, 5), ['item_kind', 'containers', 'scan', 'amount_safety', 'purchases_v2']);
    assert.deepEqual(Object.keys(ar).slice(0, 5), ['item_kind', 'containers', 'scan', 'amount_safety', 'purchases_v2']);
    for (const path of [INDEX, CREATE, ...COMPONENTS,
        'resources/js/Pages/Merchant/Inventory/IngredientStockDialog.vue',
        'resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue',
        'resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Show.vue',
        'resources/js/Pages/Merchant/Catalogue/ProductStockDialog.vue']) {
        const { script, template } = sfc(path);
        assertKeysExist(`${script}\n${template}`, path);
    }
    // Keys the libraries hand to t().
    for (const key of ['amount_safety.warnings.recipe', 'amount_safety.warnings.recipe_suggest', 'amount_safety.warnings.container_big', 'amount_safety.warnings.container_small', 'amount_safety.warnings.cost_high', 'amount_safety.warnings.cost_low', 'amount_safety.warnings.threshold_small']) {
        assert.equal(typeof get(en, key), 'string', key);
        assert.equal(typeof get(ar, key), 'string', key);
        assert.notEqual(get(en, key), get(ar, key), key);
    }
});
