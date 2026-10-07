// LAUNCH combo add-on, Part A fix order 2 (LAUNCH-COMBO_A_FIX_ORDER_2.md):
//   C-14 the product wizard's "Can be removed" price check runs on step 2,
//        where the ticks are: the REAL component is mounted and driven
//        (component-support.mjs), and no request is sent.
// Run: node --test tests/frontend/launch-combo-fix2.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { buttons, byTest, fire, loadPage, pick, settle, type } from './component-support.mjs';

const WIZARD = 'resources/js/Pages/Merchant/Catalogue/ProductWizard.vue';
const CHEESE = { id: 1, uuid: 'ing-cheese', name: 'Cheese', name_ar: 'جبن', unit: 'g', is_prep: false, alt_units: [], last_unit_cost: '0.010', status: 'active' };

let page;

async function openWizard() {
    page ??= await loadPage(WIZARD);
    globalThis.__route = { name: 'merchant.catalogue.product-create', params: {}, query: {} };
    globalThis.__calls = [];
    globalThis.__navigations = [];
    globalThis.__respond = (method, url) => {
        if (method === 'GET' && url === '/api/ingredients') return { data: [CHEESE] };
        if (method === 'GET' && url === '/api/products') return { data: [], meta: { total: 0 } };
        if (method === 'POST' && url === '/api/products/wizard') return { data: { uuid: 'p-new', name: 'Cheese burger' } };
        return { data: [] };
    };
    page.authState.user = { id: 1, name: 'Owner', email: null, user_type: 'merchant', status: 'active', company_id: 1, locale: 'en',
        roles: [page.MerchantRole.SuperAdmin], permissions: [], branch_scope: null };
    const root = document.createElement('div');
    document.body.appendChild(root);
    page.mount(root);
    await settle(page);
    return root;
}

const input = (root, labelKey) => root.findAll((n) => n.tagName === 'LABEL' && n.textContent.includes(labelKey))[0]
    .findAll((n) => n.tagName === 'INPUT')[0];
const next = (root) => buttons(root, 'catalogue.wizard.next')[0];
const onStepTwo = (root) => byTest(root, 'recipe-section').length === 1;
const writes = () => globalThis.__calls.filter((c) => c.method !== 'GET');

/** Step 1 filled in (made-to-order "Cheese burger" 2.500), then Next; step 2 with a Cheese line ticked "Can be removed" at `price`. */
async function stepTwoWithRemovePrice(price) {
    const root = await openWizard();
    const madeToOrder = root.findAll((n) => n.tagName === 'INPUT' && n.type === 'radio' && n._value === 'ingredient')[0];
    madeToOrder.checked = true;
    fire(madeToOrder, 'change');
    type(input(root, 'catalogue.fields.name *'), 'Cheese burger');
    type(input(root, 'catalogue.fields.base_price'), '2.500');
    await settle(page);
    fire(next(root), 'click');
    await settle(page);
    assert.ok(onStepTwo(root), 'step 1 is valid: Next reaches step 2');

    fire(buttons(root, 'catalogue.recipe.add_line')[0], 'click');
    await settle(page);
    pick(root.findAll((n) => n.tagName === 'SELECT' && n.options.some((o) => o.value === CHEESE.uuid))[0], CHEESE.uuid);
    await settle(page);
    type(byTest(root, 'recipe-line-quantity')[0], '20');
    const tick = byTest(root, 'removable-checkbox')[0];
    tick.checked = true;
    fire(tick, 'change');
    await settle(page);
    type(byTest(root, 'removable-price')[0], price);
    await settle(page);
    return root;
}

test('C-14 step 2: "No cheese +0.200" blocks Next and Save, shows the error on step 2, and sends nothing', async () => {
    const root = await stepTwoWithRemovePrice('0.200');
    // The tick itself warns…
    assert.equal(byTest(root, 'removable-price-problem').length, 1);
    // …and Next is blocked: disabled, and a click (keyboard, a stale render) does not move on.
    assert.equal(next(root).disabled, true, 'Next is disabled');
    fire(next(root), 'click');
    await settle(page);
    assert.ok(onStepTwo(root), 'still on step 2');
    const errors = byTest(root, 'step-two-errors');
    assert.equal(errors.length, 1, 'the error shows on step 2');
    assert.match(errors[0].textContent, /menu_extras\.removable\.price_above_zero/);
    // Save lives on step 3, which cannot be reached: the step 3 tab is closed too.
    const reviewTab = buttons(root, 'catalogue.wizard.steps.review')[0];
    fire(reviewTab, 'click');
    await settle(page);
    assert.ok(onStepTwo(root), 'the review tab does not jump past step 2');
    assert.equal(buttons(root, 'catalogue.wizard.create').length, 0, 'no Save button is reachable');
    assert.deepEqual(writes(), [], 'no request writes anything');
});

test('C-14 a price fixed on step 2 (−0.100) moves on and saves; going back and raising it blocks the review tab again', async () => {
    const root = await stepTwoWithRemovePrice('-0.100');
    assert.equal(next(root).disabled, false);
    assert.equal(byTest(root, 'step-two-errors').length, 0);
    fire(next(root), 'click');
    await settle(page);
    assert.equal(onStepTwo(root), false, 'on step 3');

    // Back to step 2, raise the price, then try the (now visited) review tab and Save.
    fire(buttons(root, 'catalogue.wizard.back_step')[0], 'click');
    await settle(page);
    type(byTest(root, 'removable-price')[0], '0.200');
    await settle(page);
    fire(buttons(root, 'catalogue.wizard.steps.review')[0], 'click');
    await settle(page);
    assert.ok(onStepTwo(root), 'the review tab keeps the user on step 2');
    assert.match(byTest(root, 'step-two-errors')[0]?.textContent ?? '', /menu_extras\.removable\.price_above_zero/);
    assert.deepEqual(writes(), []);

    // Fixed again: Next, then Save sends the product once.
    type(byTest(root, 'removable-price')[0], '-0.100');
    await settle(page);
    fire(next(root), 'click');
    await settle(page);
    fire(buttons(root, 'catalogue.wizard.create')[0], 'click');
    await settle(page, 12);
    const sent = writes();
    assert.equal(sent.length, 1, JSON.stringify(sent));
    assert.equal(sent[0].url, '/api/products/wizard');
    assert.deepEqual(sent[0].body.removable.map((r) => [r.ingredient_uuid, r.price]), [[CHEESE.uuid, '-0.100']]);
});
