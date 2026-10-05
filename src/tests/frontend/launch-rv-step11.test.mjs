// LAUNCH review add-on, Part B step 11 (after part C merged) — E1/E2 on the
// catalogue recipe screens, "No cost yet" in the recipe editor, and the
// tester calls: the prep-batch "Is this right?" rule and the scan detector
// (the link dialog opens only for a real scan).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ar, assertKeysExist, en, get, lib, sfc } from './launch-p4-support.mjs';

const WIZARD = 'resources/js/Pages/Merchant/Catalogue/ProductWizard.vue';
const CONSUMPTION = 'resources/js/Pages/Merchant/Catalogue/AddonConsumptionEditor.vue';
const PREP = 'resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue';
const SCANBOX = 'resources/js/Pages/Merchant/Inventory/components/ScanBox.vue';

test('tester call — a prep batch warns above 50 kg / 50 l / 500 pieces, or above 10 × its own yield (same kind)', () => {
    const { prepLineWarning, PREP_LIMITS, PREP_YIELD_TIMES } = lib('amountSafety');
    assert.equal(PREP_LIMITS.weighed, 50000);
    assert.equal(PREP_LIMITS.liquid, 50000);
    assert.equal(PREP_LIMITS.counted, 500);
    assert.equal(PREP_YIELD_TIMES, 10);
    // 60 kg of flour in one batch.
    const flour = prepLineWarning({ amount: '60', factor: 1000, storedUnit: 'g', yieldStored: 70000, yieldUnit: 'g', item: 'Flour' });
    assert.equal(flour.key, 'amount_safety.warnings.prep_batch');
    assert.equal(flour.params.amount, '60 kg');
    assert.equal(flour.params.item, 'Flour');
    // 40 l of water to make 2 l of syrup (more than 10 × the yield).
    const water = prepLineWarning({ amount: '40', factor: 1000, storedUnit: 'ml', yieldStored: 2000, yieldUnit: 'ml', item: 'Water' });
    assert.equal(water.key, 'amount_safety.warnings.prep_yield');
    assert.equal(water.params.amount, '40 l');
    assert.equal(water.params.made, '2 l');
    assert.equal(prepLineWarning({ amount: '15', factor: 1000, storedUnit: 'ml', yieldStored: 2000, yieldUnit: 'ml' }), null, '15 l for 2 l is within 10 ×');
    assert.equal(prepLineWarning({ amount: '20', factor: 1000, storedUnit: 'ml', yieldStored: 2000, yieldUnit: 'ml' }), null, 'exactly 10 × is fine');
    // Another kind than the yield: only the absolute limit applies.
    assert.equal(prepLineWarning({ amount: '30', factor: 1000, storedUnit: 'g', yieldStored: 2000, yieldUnit: 'ml' }), null);
    assert.equal(prepLineWarning({ amount: '51', factor: 1, storedUnit: 'kg', yieldStored: 2000, yieldUnit: 'ml' }).key, 'amount_safety.warnings.prep_batch');
    // Counted: 600 eggs, or 30 eggs for 2 pieces.
    assert.equal(prepLineWarning({ amount: '600', factor: 1, storedUnit: 'piece', yieldStored: null, yieldUnit: 'g' }).key, 'amount_safety.warnings.prep_batch');
    assert.equal(prepLineWarning({ amount: '30', factor: 1, storedUnit: 'piece', yieldStored: 2, yieldUnit: 'piece' }).key, 'amount_safety.warnings.prep_yield');
    // A container: 3 crates of 12 l = 36 l for 2 l.
    assert.equal(prepLineWarning({ amount: '3', factor: 12000, storedUnit: 'ml', yieldStored: 2000, yieldUnit: 'ml' }).key, 'amount_safety.warnings.prep_yield');
    // Blank, 0 and unknown factors say nothing.
    assert.equal(prepLineWarning({ amount: '', factor: 1, storedUnit: 'g', yieldStored: 1, yieldUnit: 'g' }), null);
    assert.equal(prepLineWarning({ amount: '0', factor: 1, storedUnit: 'g', yieldStored: 1, yieldUnit: 'g' }), null);
});

test('tester call — the scan detector: a burst ending in Enter is a scan; plain typing never is', () => {
    const { looksScanned, isBarcodeLike, mayOfferLink, nextTimes, wasScanned } = lib('scanDetect');
    const burst = [0, 8, 15, 24, 31, 40, 47, 55, 62, 70, 77, 85, 92];
    assert.equal(looksScanned(burst), true);
    assert.equal(looksScanned([0, 150, 310, 420, 600]), false, 'a person typing');
    assert.equal(looksScanned([0, 10, 20]), false, 'too short');
    assert.equal(looksScanned([0, 10, 20, 30, 200, 210]), false, 'a pause in the middle');
    assert.equal(isBarcodeLike('12345678'), true);
    assert.equal(isBarcodeLike(' 5012345678900 '), true);
    assert.equal(isBarcodeLike('1234567'), false);
    assert.equal(isBarcodeLike('123456789012345'), false);
    assert.equal(isBarcodeLike('milk'), false);
    assert.equal(isBarcodeLike('ING-0001'), false);
    assert.equal(mayOfferLink('milk', false), false, 'plain text + Enter never opens the dialog');
    assert.equal(mayOfferLink('ABC-123', true), true, 'a scanned code may be linked');
    assert.equal(mayOfferLink('5012345678900', false), true, 'a typed EAN / UPC may be linked');
    assert.equal(mayOfferLink('', true), false);
    // Keystrokes: one character added = one keystroke; a paste or a deletion starts again.
    let times = [];
    let text = '';
    for (const [i, ch] of [...'ABC-1234'].entries()) {
        times = nextTimes(times, i * 9, text.length, text.length + 1);
        text += ch;
    }
    assert.equal(wasScanned(times, text), true);
    assert.equal(nextTimes(times, 200, text.length, text.length + 5).length, 0, 'a paste');
    assert.equal(nextTimes(times, 200, text.length, text.length - 1).length, 0, 'a deletion');
    assert.equal(wasScanned([0, 9, 18, 27], 'abcdefgh'), false, 'only part of the text came in the burst');
});

test('tester call — the scan box opens the link dialog only for a real scan; a list search only filters', () => {
    const { script } = sfc(SCANBOX);
    assert.match(script, /keyTimes = nextTimes\(keyTimes, Date\.now\(\), text\.value\.length, value\.length\);/);
    assert.match(script, /const scanned = wasScanned\(keyTimes, text\.value\);/);
    assert.match(script, /\} else if \(props\.canLink && res\.data\.can_link && mayOfferLink\(code, scanned\)\) \{\s*linkCode\.value = code;/);
    // Plain text in a list search: no dialog and no message (the list is already filtered).
    assert.match(script, /\} else if \(props\.mode === 'add'\) \{/);
    assert.doesNotMatch(script, /\} else if \(props\.canLink && res\.data\.can_link\) \{/);
});

test('step 11 — the product recipe lines translate live, say "No cost yet" and ask "Is this right?"', () => {
    const { script, template } = sfc(WIZARD);
    // E1 — the amount and its unit are one AmountInput (still the recipe unit options).
    assert.match(template, /<AmountInput\s*v-model="line\.quantity"\s*v-model:unit="line\.unit"[\s\S]*?:options="recipeUnitOptions\(ingredientByUuid\(line\.ingredient_uuid\), locale\)"[\s\S]*?data-test="recipe-line-quantity"/);
    // A1 — per line and on the total (editable, read-only and the review).
    assert.match(template, /v-if="line\.ingredient_uuid && recipeLineNoCost\(line\)"[^>]*data-test="recipe-line-no-cost">\{\{ t\('purchases_v2\.no_cost_yet'\) \}\}/);
    assert.match(template, /data-test="recipe-line-cost"/);
    for (const marker of ['recipe-cost-incomplete', 'recipe-cost-incomplete-readonly', 'review-cost-incomplete']) {
        assert.match(template, new RegExp(`v-if="recipeCostIncomplete"[^>]*data-test="${marker}"`), marker);
    }
    assert.match(script, /ingredient\.has_cost === false \|\| ingredient\.cost_complete === false/);
    // E2 — the recipe lines and new options on save; an option's stock usage on its own save.
    assert.match(script, /if \(!\(await confirmAmounts\(saveWarnings\(\)\)\)\) \{\s*step\.value = 2;\s*return;\s*\}/);
    assert.match(script, /if \(!\(await confirmAmounts\(consumptionWarnings\(optionStockDrafts\.value\[uuid\] \?\? \[\]\)\)\)\) return;/);
    assert.match(script, /return recipeLineWarning\(\{\s*amount: quantity,\s*unit: entered,\s*storedUnit: ingredient\.unit,\s*factor: recipeUnitFactor\(ingredient, entered\),/);
    assert.match(template, /<AmountConfirmDialog :warnings="amountWarnings" @answer="answerAmounts" \/>/);
    // Part C's behaviour is kept: the ticks on both lists, saved after the recipe.
    assert.equal((template.match(/<RemovableTick /g) ?? []).length, 2);
    assert.ok(script.indexOf('await updateProductRecipe(uuid') < script.indexOf('await saveRemovable(uuid'));
    assertKeysExist(`${script}\n${template}`, 'ProductWizard');
});

test('step 11 — an option\'s stock usage translates live and warns under the line', () => {
    const { script, template } = sfc(CONSUMPTION);
    assert.match(template, /v-if="lineTranslation\(line\)"[^>]*data-test="consumption-translation"/);
    assert.match(template, /v-if="lineWarning\(line\)"[^>]*data-test="consumption-warning"/);
    assert.match(script, /translateAmount\(\{ amount: line\.quantity, unit: line\.unit \?\? '', storedUnit: ingredient\.unit, containers: ingredient\.alt_units \?\? \[\], locale: locale\.value \}\)/);
    assert.match(script, /recipeLineWarning\(\{ amount: line\.quantity, unit: line\.unit \?\? '', storedUnit: ingredient\.unit, factor: recipeUnitFactor\(ingredient, line\.unit \?\? ''\) \}\)/);
    assertKeysExist(`${script}\n${template}`, 'AddonConsumptionEditor');
});

test('step 11 — the prep editor asks "Is this right?" per batch; the strings exist in both languages', () => {
    const { script, template } = sfc(PREP);
    assert.match(script, /if \(!\(await confirmAmounts\(batchWarnings\(\)\)\)\) return;/);
    assert.match(script, /yieldStored: yieldNumber\.value,\s*yieldUnit: form\.unit,/);
    assert.match(template, /<AmountConfirmDialog :warnings="amountWarnings" @answer="answerAmounts" \/>/);
    for (const key of ['amount_safety.warnings.prep_batch', 'amount_safety.warnings.prep_yield']) {
        assert.equal(typeof get(en, key), 'string', key);
        assert.equal(typeof get(ar, key), 'string', key);
        assert.notEqual(get(en, key), get(ar, key), key);
    }
    assert.match(en.amount_safety.warnings.prep_yield, /\{made\}/);
    assert.match(ar.amount_safety.warnings.prep_yield, /\{made\}/);
});
