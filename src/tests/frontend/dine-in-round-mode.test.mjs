import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { computed, reactive, ref } from 'vue';

const componentUrl = new URL('../../resources/js/Pages/Merchant/Settings/DineInRoundMode.vue', import.meta.url);
const componentSource = readFileSync(componentUrl, 'utf8');
const english = JSON.parse(readFileSync(new URL('../../resources/js/locales/en.json', import.meta.url), 'utf8'));

/** No browser or new test framework: execute the actual script-setup handlers. */
function pageHarness({ source = componentSource, initial = snapshot(), updateBranch, updateDefault }) {
    const parsed = parse(source);
    assert.deepEqual(parsed.errors, []);
    assert.ok(parsed.descriptor.scriptSetup);
    const script = parsed.descriptor.scriptSetup.content.replace(/^import[\s\S]*?;\s*/gm, '');
    const compiled = ts.transpileModule(script, {
        compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.None },
        reportDiagnostics: true,
    });
    assert.equal(compiled.diagnostics?.length ?? 0, 0);

    class ApiError extends Error {
        constructor(status) {
            super('Mock API refusal');
            this.status = status;
            this.payload = null;
        }

        firstValidationMessage() {
            return null;
        }
    }

    const context = {
        computed,
        reactive,
        ref,
        onMounted: () => {},
        useI18n: () => ({
            locale: ref('en'),
            t: (key) => key.split('.').reduce((value, part) => value[part], english),
        }),
        usePermissions: () => ({ can: () => true }),
        MerchantPermission: { BranchesUpdate: 'branches.update' },
        ApiError,
        getDineInRoundModeSetting: async () => ({ data: initial }),
        updateBranchDineInRoundMode: updateBranch,
        updateDineInRoundModeDefault: updateDefault,
    };
    runInNewContext(compiled.outputText + '\n' + `
        globalThis.page = {
            changeDefault, changeBranch, setting,
            defaultSaving, defaultError, defaultSuccess,
            branchSaving, branchErrors, branchSuccess,
        };
    `, context, { filename: 'DineInRoundMode.vue' });
    context.page.setting.value = initial;
    return { page: context.page, ApiError };
}

function snapshot({ company = 'kitchen_direct', a = null, b = null } = {}) {
    return {
        company_default: company,
        company_default_editable: true,
        branches: [
            { uuid: 'A', name: 'A', name_ar: null, code: 'A', mode: a, effective: a ?? company },
            { uuid: 'B', name: 'B', name_ar: null, code: 'B', mode: b, effective: b ?? company },
        ],
    };
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((done, fail) => {
        resolve = done;
        reject = fail;
    });
    return { promise, resolve, reject };
}

const microtasks = () => new Promise((resolve) => setImmediate(resolve));

async function assertIndependentRowsAreSerialized(source = componentSource) {
    const first = deferred();
    const second = deferred();
    const calls = [];
    const { page } = pageHarness({
        source,
        updateBranch: (uuid, mode) => {
            calls.push([uuid, mode]);
            if (uuid === 'B') {
                assert.equal(page.setting.value.branches[0].mode, 'staff_confirm',
                    'apply the first full snapshot before dispatching the next PUT');
            }
            return uuid === 'A' ? first.promise : second.promise;
        },
    });
    const aSelect = { value: 'staff_confirm' };
    const bSelect = { value: 'staff_confirm' };
    const aSave = page.changeBranch(page.setting.value.branches[0], { target: aSelect });
    const bSave = page.changeBranch(page.setting.value.branches[1], { target: bSelect });

    try {
        assert.equal(page.branchSaving.A, true);
        assert.equal(page.branchSaving.B, true, 'the independently queued row has its own saving state');
        await microtasks();
        assert.deepEqual(calls, [['A', 'staff_confirm']]);

        // B is ready before delayed A. Its PUT must not have been dispatched.
        second.resolve({ data: snapshot({ a: 'staff_confirm', b: 'staff_confirm' }) });
        await microtasks();
        assert.deepEqual(calls, [['A', 'staff_confirm']]);
        assert.equal(page.setting.value.branches[1].mode, null);

        first.resolve({ data: snapshot({ a: 'staff_confirm' }) });
        await Promise.all([aSave, bSave]);
        assert.deepEqual(calls, [['A', 'staff_confirm'], ['B', 'staff_confirm']]);
        assert.equal(page.setting.value.branches[0].mode, 'staff_confirm');
        assert.equal(page.setting.value.branches[1].mode, 'staff_confirm');
        assert.equal(page.branchSaving.A, false);
        assert.equal(page.branchSaving.B, false);
        assert.equal(page.branchSuccess.A, true);
        assert.equal(page.branchSuccess.B, true);
        assert.equal(aSelect.value, 'staff_confirm');
        assert.equal(bSelect.value, 'staff_confirm');
    } finally {
        first.resolve({ data: snapshot({ a: 'staff_confirm' }) });
        second.resolve({ data: snapshot({ a: 'staff_confirm', b: 'staff_confirm' }) });
        await Promise.allSettled([aSave, bSave]);
    }
}

test('independent branch saves serialize PUTs and full snapshots despite delayed first response', async () => {
    await assertIndependentRowsAreSerialized();
});

test('a 403 remains local to its row and does not poison the next queued save', async () => {
    const first = deferred();
    const second = deferred();
    const calls = [];
    const { page, ApiError } = pageHarness({
        updateBranch: (uuid) => {
            calls.push(uuid);
            return uuid === 'A' ? first.promise : second.promise;
        },
    });
    const aSelect = { value: 'staff_confirm' };
    const bSelect = { value: 'staff_confirm' };
    const aSave = page.changeBranch(page.setting.value.branches[0], { target: aSelect });
    const bSave = page.changeBranch(page.setting.value.branches[1], { target: bSelect });

    try {
        await microtasks();
        assert.deepEqual(calls, ['A']);
        first.reject(new ApiError(403));
        await aSave;
        await microtasks();
        assert.deepEqual(calls, ['A', 'B']);
        assert.equal(page.branchErrors.A, english.settings.dine_in_round_mode.forbidden);
        assert.equal(page.branchErrors.B, null);
        assert.equal(page.branchSaving.A, false);
        assert.equal(page.branchSaving.B, true);
        assert.equal(aSelect.value, 'inherit');

        second.resolve({ data: snapshot({ b: 'staff_confirm' }) });
        await bSave;
        assert.equal(page.branchErrors.A, english.settings.dine_in_round_mode.forbidden);
        assert.equal(page.branchSuccess.A, false);
        assert.equal(page.branchSuccess.B, true);
        assert.equal(page.branchSaving.B, false);
        assert.equal(page.defaultError.value, null);
        assert.equal(page.setting.value.branches[1].effective, 'staff_confirm');
    } finally {
        first.resolve({ data: snapshot() });
        second.resolve({ data: snapshot({ b: 'staff_confirm' }) });
        await Promise.allSettled([aSave, bSave]);
    }
});

test('company default and branch inherit use one queue with independent saving states', async () => {
    const company = deferred();
    const branch = deferred();
    const calls = [];
    const { page } = pageHarness({
        initial: snapshot({ a: 'kitchen_direct' }),
        updateDefault: (mode) => {
            calls.push(['company', mode]);
            return company.promise;
        },
        updateBranch: (uuid, mode) => {
            calls.push([uuid, mode]);
            assert.equal(page.setting.value.company_default, 'staff_confirm');
            return branch.promise;
        },
    });
    const companySelect = { value: 'staff_confirm' };
    const branchSelect = { value: 'inherit' };
    const companySave = page.changeDefault({ target: companySelect });
    const branchSave = page.changeBranch(page.setting.value.branches[0], { target: branchSelect });

    try {
        assert.equal(page.defaultSaving.value, true);
        assert.equal(page.branchSaving.A, true);
        await microtasks();
        assert.deepEqual(calls, [['company', 'staff_confirm']]);
        branch.resolve({ data: snapshot({ company: 'staff_confirm' }) });
        await microtasks();
        assert.deepEqual(calls, [['company', 'staff_confirm']]);
        company.resolve({ data: snapshot({ company: 'staff_confirm', a: 'kitchen_direct' }) });
        await Promise.all([companySave, branchSave]);

        assert.deepEqual(calls, [['company', 'staff_confirm'], ['A', 'inherit']]);
        assert.equal(page.setting.value.company_default, 'staff_confirm');
        assert.equal(page.setting.value.branches[0].mode, null);
        assert.equal(page.setting.value.branches[0].effective, 'staff_confirm');
        assert.equal(page.defaultSaving.value, false);
        assert.equal(page.branchSaving.A, false);
        assert.equal(page.defaultSuccess.value, true);
        assert.equal(page.branchSuccess.A, true);
        assert.equal(branchSelect.value, 'inherit');
    } finally {
        company.resolve({ data: snapshot({ company: 'staff_confirm', a: 'kitchen_direct' }) });
        branch.resolve({ data: snapshot({ company: 'staff_confirm' }) });
        await Promise.allSettled([companySave, branchSave]);
    }
});

test('the serialization regression kills an unqueued in-memory source mutation', async () => {
    const queued = 'const pending = saveQueue.then(save);';
    assert.ok(componentSource.includes(queued), 'mutation must target the actual queue implementation');
    const unqueued = componentSource.replace(queued, 'const pending = save();');
    await assert.rejects(
        assertIndependentRowsAreSerialized(unqueued),
        { name: 'AssertionError' },
    );
});
