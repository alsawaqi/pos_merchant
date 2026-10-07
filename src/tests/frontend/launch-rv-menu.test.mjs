// LAUNCH review add-on, part C (menu) — work order §6, owner decisions D9–D12:
//   the combo editor's main slot ("Make it a meal?"), daily hours (no longer
//   wiped), limited-time dates and cooking time; the product wizard's dates
//   and cooking time, and "Can be removed" on recipe lines; list badges
//   "Starts / Ends / Ended"; the add-on groups page's type selector (Extras or
//   Quick instructions); every new text in English and Arabic, in the new
//   `meals` and `menu_extras` blocks at the end of the locale files.
// Run: node --test tests/frontend/launch-rv-menu.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, ar, en, exists, lib, read, sfc } from './launch-p4-support.mjs';

const menu = () => lib('menuExtras', { Intl, Date });

test('C2 list badges: Starts before the first day, Ends while an end is set, Ended after the last day (inclusive days)', () => {
    assert.ok(exists('resources/js/lib/menuExtras.ts'), 'lib/menuExtras.ts');
    const { saleBadge, onSaleOn, saleDay, shortDay, muscatToday } = menu();
    const today = '2026-11-15';
    assert.equal(saleBadge(null, null, today), null);
    assert.equal(saleBadge('2026-11-01', null, today), null);
    assert.deepEqual({ ...saleBadge('2026-11-20', null, today) }, { kind: 'starts', date: '2026-11-20' });
    assert.deepEqual({ ...saleBadge('2026-11-20', '2026-11-30', today) }, { kind: 'starts', date: '2026-11-20' });
    assert.deepEqual({ ...saleBadge(null, '2026-11-15', today) }, { kind: 'ends', date: '2026-11-15' });
    assert.deepEqual({ ...saleBadge('2026-11-01', '2026-11-14', today) }, { kind: 'ended', date: '2026-11-14' });
    assert.equal(onSaleOn('2026-11-15', '2026-11-15', today), true);
    assert.equal(onSaleOn('2026-11-16', null, today), false);
    assert.equal(onSaleOn(null, '2026-11-14', today), false);
    assert.equal(saleDay(''), null);
    assert.equal(saleDay('15/11/2026'), null);
    assert.equal(shortDay('2026-11-01', 'en', today), '1 Nov');
    assert.match(shortDay('2027-01-05', 'en', today), /2027/);
    // Muscat is UTC+4: 21:00 UTC on 15 Nov is already 16 Nov there.
    assert.equal(muscatToday(new Date('2026-11-15T21:00:00Z')), '2026-11-16');
});

test('C1/C2 dates and cooking time: until never before from; 0..240 whole minutes; blank = not set', () => {
    const { datesProblem, cookingProblem, cookingPayload, comboCookingFigure } = menu();
    assert.equal(datesProblem('2026-11-10', '2026-11-09'), 'until_before_from');
    assert.equal(datesProblem('2026-11-10', '2026-11-10'), null);
    assert.equal(datesProblem('', '2026-11-09'), null);
    for (const ok of ['', '0', '12', 240, '240']) assert.equal(cookingProblem(ok), null, String(ok));
    for (const bad of ['-1', '241', '2.5', 'soon']) assert.equal(cookingProblem(bad), 'cooking_range', bad);
    assert.equal(cookingPayload(''), null);
    assert.equal(cookingPayload('0'), 0);
    assert.equal(cookingPayload(12), 12);
    // A combo: its own time, else its longest item.
    assert.equal(comboCookingFigure('', [5, null, 12]), 12);
    assert.equal(comboCookingFigure('8', [5, 12]), 8);
    assert.equal(comboCookingFigure('', [null]), null);
});

test('C3 "Can be removed": the ticked lines of the recipe as it stands, each once, with optional labels', () => {
    const { removablePayload, removeOptionName, removeOptionNameAr, ticksFromState, groupKind } = menu();
    const lines = [{ ingredient_uuid: 'k' }, { ingredient_uuid: 'o' }, { ingredient_uuid: '' }, { ingredient_uuid: 'k' }];
    const ticks = { k: { ticked: true, label: ' Ketchup ', label_ar: '' }, o: { ticked: false, label: '', label_ar: '' }, gone: { ticked: true, label: '', label_ar: '' } };
    assert.deepEqual([...removablePayload(lines, ticks)].map((r) => ({ ...r })), [{ ingredient_uuid: 'k', label: 'Ketchup', label_ar: null, price: '0.000' }]);
    assert.equal(removeOptionName('', 'Onion'), 'NO Onion');
    assert.equal(removeOptionName('Ketchup', 'Ketchup (Heinz 5 kg)'), 'NO Ketchup');
    assert.equal(removeOptionNameAr('', 'بصل', 'Onion'), 'بدون بصل');
    assert.equal(removeOptionNameAr('', null, 'Onion'), 'بدون Onion');
    assert.deepEqual({ ...ticksFromState([{ ingredient_uuid: 'k', label: 'Ketchup', label_ar: null }]).k }, { ticked: true, label: 'Ketchup', label_ar: '', price: '0.000' });
    assert.equal(groupKind({ kind: 'instructions' }), 'instructions');
    assert.equal(groupKind({}), 'extras');
    assert.equal(groupKind(null), 'extras');
});

test('C1 (fix order C-1, L7) the combo editor saves the daily hours as set, the dates and the cooking time', () => {
    const { comboMenuFields } = menu();
    // The hours used to be sent as null on every save, wiping them (menu audit §3.2).
    assert.deepEqual({ ...comboMenuFields({ available_from: '11:00', available_until: '15:30', on_sale_from: '2026-11-01', on_sale_until: '2026-11-30', cooking_minutes: '12' }) }, {
        available_from: '11:00:00',
        available_until: '15:30:00',
        on_sale_from: '2026-11-01',
        on_sale_until: '2026-11-30',
        cooking_minutes: 12,
    });
    // Blank boxes = no bound / not set; 0 minutes is "ready at once", not "not set".
    assert.deepEqual({ ...comboMenuFields({ available_from: '', available_until: '', on_sale_from: '', on_sale_until: '', cooking_minutes: 0 }) }, {
        available_from: null,
        available_until: null,
        on_sale_from: null,
        on_sale_until: null,
        cooking_minutes: 0,
    });
    // A prefilled 'HH:MM:SS' (or a browser's 'HH:MM:SS' time box) keeps its minutes.
    assert.equal(comboMenuFields({ available_from: '09:45:00', available_until: '', on_sale_from: '', on_sale_until: '', cooking_minutes: '' }).available_from, '09:45:00');
    assert.equal(comboMenuFields({ available_from: '', available_until: '', on_sale_from: '', on_sale_until: '', cooking_minutes: '' }).cooking_minutes, null);

    // Wiring: payload() sends exactly these fields (LAUNCH combo add-on: no main slot any more).
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ComboEditor.vue');
    const body = script.slice(script.indexOf('function payload()'), script.indexOf('const blockingProblems'));
    assert.match(body, /\.\.\.comboMenuFields\(form\),/);
    assert.doesNotMatch(body, /available_from: null/);
    assert.doesNotMatch(body, /is_main/);
    for (const hook of ['combo-when', 'combo-hours-from', 'combo-hours-until', 'combo-sale-from', 'combo-sale-until', 'combo-cooking']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assertKeysExist(template + script, 'ComboEditor');
});

test('C3 (fix order C-1, M1) the wizard sends the ticks only when they loaded and changed, with the loaded ticks', () => {
    const { removableSaveDecision } = menu();
    const recipe = [{ ingredient_uuid: 'k' }, { ingredient_uuid: 'o' }, { ingredient_uuid: 'b' }];
    const loaded = [{ ingredient_uuid: 'k', label: 'Ketchup', label_ar: 'كاتشب' }, { ingredient_uuid: 'o', label: 'Onion', label_ar: null }];
    const asLoaded = { k: { ticked: true, label: 'Ketchup', label_ar: 'كاتشب' }, o: { ticked: true, label: 'Onion', label_ar: '' } };

    // A failed (or unfinished) load never sends: an empty list would wipe the saved Remove list.
    assert.equal(removableSaveDecision('failed', recipe, {}, []).send, false);
    assert.equal(removableSaveDecision('loading', recipe, asLoaded, loaded).send, false);
    // Nothing changed: nothing sent (no overwriting another manager's ticks).
    assert.equal(removableSaveDecision('ok', recipe, asLoaded, loaded).send, false);
    // A ticked line deleted from the recipe is not a tick change (the recipe save retires it).
    assert.equal(removableSaveDecision('ok', [{ ingredient_uuid: 'o' }, { ingredient_uuid: 'b' }], asLoaded, loaded).send, false);

    // A change sends the new ticks AND the loaded ones (409 when stale).
    const changed = removableSaveDecision('ok', recipe, { ...asLoaded, o: { ticked: false, label: 'Onion', label_ar: '' }, b: { ticked: true, label: '', label_ar: '' } }, loaded);
    assert.equal(changed.send, true);
    assert.deepEqual([...changed.lines].map((l) => ({ ...l })), [
        // LAUNCH combo add-on — each tick carries its price ('0.000' = no change).
        { ingredient_uuid: 'k', label: 'Ketchup', label_ar: 'كاتشب', price: '0.000' },
        { ingredient_uuid: 'b', label: null, label_ar: null, price: '0.000' },
    ]);
    assert.deepEqual([...changed.expected].map((l) => ({ ...l })), loaded);
    // Unticking everything is a real change, sent with what was loaded.
    const cleared = removableSaveDecision('ok', recipe, {}, loaded);
    assert.equal(cleared.send, true);
    assert.equal(cleared.lines.length, 0);
    assert.equal(cleared.expected.length, 2);
});

test('C2/C3 the product wizard: dates and cooking time on the basics, "Can be removed" on the recipe lines', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(script, /on_sale_from: saleDay\(form\.on_sale_from\)/);
    assert.match(script, /cooking_minutes: cookingPayload\(form\.cooking_minutes\)/);
    assert.match(script, /form\.on_sale_from = product\.on_sale_from/);
    assert.match(script, /if \(datesError\.value\) missing\.push/);
    for (const hook of ['product-sale-dates', 'product-sale-from', 'product-sale-until', 'product-cooking-minutes', 'removable-hint', 'removable-only-prints', 'review-sale-dates', 'review-cooking']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    // The tick on editable AND read-only recipe lines; catalogue.manage edits
    // it, and only once the saved ticks loaded (fix order C-1, M1).
    assert.equal((template.match(/<RemovableTick /g) ?? []).length, 2);
    assert.equal((template.match(/<RemovableTick[^>]*:disabled="removableLocked"/g) ?? []).length, 2);
    assert.match(script, /removableLocked = computed\(\(\) => !canManage\.value \|\| removableLoad\.value !== 'ok'\)/);
    assert.match(template, /v-if="removableLoad === 'failed'"[^>]*data-test="removable-load-failed"/);
    // A failed load leaves the state 'failed' (never an empty "loaded" list).
    const load = script.slice(script.indexOf('async function loadRemovable'), script.indexOf('const isPieceCounted'));
    assert.match(load, /catch \{\s*removableTicks\.value = \{\};\s*removableLoad\.value = 'failed';/);
    // Create: in the atomic wizard call; edit: saved after the recipe, only on the decision.
    assert.match(script, /removable: canEditRecipes\.value && hasRecipeStep\.value \? removablePayload\(recipePayload\(\), removableTicks\.value\) : \[\]/);
    assert.match(script, /removableSaveDecision\(removableLoad\.value, form\.recipe_lines, removableTicks\.value, removableBaseline\.value\)/);
    assert.match(script, /if \(hasRecipeStep\.value && removableSave\.send\) \{\s*await saveRemovable\(uuid, removableSave\.lines, removableSave\.expected\)/);
    assert.match(script, /err\.status === 409\s*\? t\('menu_extras\.removable\.stale'\)/);
    const recipeAt = script.indexOf('await updateProductRecipe(uuid');
    const removableAt = script.indexOf('await saveRemovable(uuid');
    assert.ok(recipeAt > 0 && removableAt > recipeAt, 'the ticks are saved after the recipe');
    assert.match(script, /removableOnlyPrints = computed\(\(\) => form\.stock_mode === 'cooked'\)/);
    assertKeysExist(template + script, 'ProductWizard');
    const tick = sfc('resources/js/Pages/Merchant/Catalogue/RemovableTick.vue');
    for (const hook of ['removable-tick', 'removable-checkbox', 'removable-label', 'removable-label-ar', 'removable-preview']) {
        assert.match(tick.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assertKeysExist(tick.template + tick.script, 'RemovableTick');
    const api = read('resources/js/lib/api/menuExtras.ts');
    assert.match(api, /`\/api\/products\/\$\{productUuid\}\/removable`/);
});

test('C2/C4 the catalogue list shows date badges; the add-on groups page picks Extras or Quick instructions', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(template, /data-test="sale-badge"/);
    assert.match(script, /saleBadge\(prod\.on_sale_from, prod\.on_sale_until, todayMuscat\)/);
    for (const hook of ['group-kind', 'group-kind-extras', 'group-kind-instructions', 'kind-badge', 'instruction-free']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(script, /kind: agForm\.kind,/);
    assert.match(script, /price_delta: instruction \? '0' : aoForm\.price_delta/);
    assert.match(script, /linked_product_uuid: instruction \? null :/);
    assert.match(template, /v-if="!aoIsInstruction" class="rounded-lg border border-slate-200 p-3"/);
    assertKeysExist(template + script, 'Index');
});

test('C7 every new text exists in English and Arabic, in new blocks at the end of the locale files', () => {
    assertBilingual('meals');
    assertBilingual('menu_extras');
    for (const [label, tree] of [['en', en], ['ar', ar]]) {
        assert.deepEqual(Object.keys(tree).slice(-2), ['meals', 'menu_extras'], `${label}: the new blocks are last`);
    }
});
