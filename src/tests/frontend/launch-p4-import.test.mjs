// LAUNCH-P4 B6 — menu import and export page (work order LAUNCH-P4, Part B):
//   a downloadable .xlsx template; upload .xlsx (or CSV UTF-8); a preview table
//   (new / update / error per row, with messages); save in one go; "create
//   missing categories"; export in the same columns; English AND Arabic.
// Run: node --test tests/frontend/launch-p4-import.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, en, read, sfc } from './launch-p4-support.mjs';

test('B6 the page offers the template, the upload with "create missing categories", and the exports', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/MenuImport.vue');
    for (const hook of ['download-template', 'export-xlsx', 'export-csv', 'import-file', 'create-categories', 'run-preview']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(template, /accept="\.xlsx,\.csv"/);
    assert.match(script, /previewMenuImport\(file\.value, createCategories\.value\)/);
    assertKeysExist(template + script, 'MenuImport');
    const api = read('resources/js/lib/api/menuImport.ts');
    for (const url of ['/api/products/import/template', '/api/products/import/preview', '/api/products/import/commit', '/api/products/export?format=']) {
        assert.ok(api.includes(url), url);
    }
});

test('B6 the preview shows new / update / no change / error per row with its messages, and saving waits for a clean file', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/MenuImport.vue');
    assert.match(template, /data-test="import-preview"/);
    assert.match(template, /data-test="import-row"/);
    assert.match(template, /t\(`menu_import\.actions\.\$\{row\.action\}`\)/);
    assert.match(template, /issueText\(issue\)/);
    assert.match(script, /t\(`menu_import\.issues\.\$\{issue\.code\}`/);
    assert.match(script, /summary\.error === 0/);
    assert.match(template, /:disabled="busy \|\| !canSave"[^>]*data-test="run-save"/);
    for (const action of ['new', 'update', 'unchanged', 'error']) assert.ok(en.menu_import.actions[action], action);
});

test('B6 every issue and file error the server sends has a text in both languages', () => {
    const planner = read('app/Actions/Pos/Catalogue/MenuImport/PlanMenuImportAction.php');
    const codes = new Set([...planner.matchAll(/\$error\('([a-z_]+)'/g)].map((m) => m[1]));
    codes.add('category_will_be_created');
    for (const code of codes) {
        assert.ok(en.menu_import.issues[code], `en issue ${code}`);
    }
    const reader = read('app/Support/Spreadsheet/XlsxReader.php') + read('app/Support/Spreadsheet/SpreadsheetRows.php') + planner;
    const reasons = new Set([...reader.matchAll(/new XlsxReaderException\('([a-z_]+)'/g)].map((m) => m[1]));
    for (const reason of reasons) {
        assert.ok(en.menu_import.file_errors[reason], `en file error ${reason}`);
    }
    assertBilingual('menu_import');
});

test('B6 the catalogue links to the import page', () => {
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/Index.vue').template, /router\.push\('\/catalogue\/import'\)/);
    assert.match(read('resources/js/router.ts'), /path: '\/catalogue\/import'/);
});
