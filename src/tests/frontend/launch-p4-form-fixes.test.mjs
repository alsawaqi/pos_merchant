// LAUNCH-P4 B7 — product form fixes (work order LAUNCH-P4, Part B):
//   M6 red "below zero" badges in the stock dialog, the branch page (cooked
//      too) and the catalogue list; every new text in English AND Arabic.
//   (H6/H7/L5 are covered by launch-p4-channels.test.mjs.)
// Run: node --test tests/frontend/launch-p4-form-fixes.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, lib, sfc } from './launch-p4-support.mjs';

test('M6 below zero is flagged for ready-made and cooked products only', () => {
    const { isBelowZero, belowZeroBranchIds } = lib('stockFlags');
    assert.equal(isBelowZero('-0.001'), true);
    assert.equal(isBelowZero('0.000'), false);
    assert.equal(isBelowZero(null), false);
    assert.equal(isBelowZero(-2), true);
    const branches = [{ branch_id: 1, stock_qty: -2 }, { branch_id: 2, stock_qty: 5 }, { branch_id: 3, stock_qty: null }];
    assert.deepEqual([...belowZeroBranchIds({ stock_mode: 'cooked', branches })], [1]);
    assert.deepEqual([...belowZeroBranchIds({ stock_mode: 'unit', branches })], [1]);
    assert.deepEqual([...belowZeroBranchIds({ stock_mode: 'ingredient', branches })], []);
});

test('M6 the stock dialog, the branch page and the catalogue list show the badge', () => {
    const dialog = sfc('resources/js/Pages/Merchant/Catalogue/ProductStockDialog.vue');
    assert.match(dialog.template, /v-if="isBelowZero\(b\.stock_qty\)"[^>]*data-test="below-zero"/);
    const branch = sfc('resources/js/Pages/Merchant/Branches/Show.vue');
    assert.match(branch.template, /p\.stock_mode === 'unit' \|\| p\.stock_mode === 'cooked'/);
    assert.match(branch.template, /v-if="isBelowZero\(p\.stock_qty\)"[^>]*data-test="below-zero"/);
    const list = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(list.template, /v-if="belowZeroBranchIds\(prod\)\.length > 0"/);
    assertBilingual('stock_flags');
});
