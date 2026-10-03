// Shared helpers for the LAUNCH-P4 Part B node tests (not a test file).
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';

export const exists = (path) => existsSync(new URL(`../../${path}`, import.meta.url));
export const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

/** A Vue SFC split into its script-setup and template text. */
export function sfc(path) {
    const parsed = parse(read(path));
    assert.deepEqual(parsed.errors, [], path);
    return { script: parsed.descriptor.scriptSetup?.content ?? '', template: parsed.descriptor.template?.content ?? '' };
}

/** Load a lib/*.ts file as a plain module (exports collected from `export`). */
export function lib(name, extraGlobals = {}) {
    const { outputText } = ts.transpileModule(read(`resources/js/lib/${name}.ts`), {
        compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
    });
    const module = { exports: {} };
    const require = (id) => {
        if (id.startsWith('@/lib/')) return lib(id.slice('@/lib/'.length), extraGlobals);
        throw new Error(`unexpected import ${id} in ${name}`);
    };
    runInNewContext(outputText, { module, exports: module.exports, require, Math, Number, String, Set, Map, Array, Object, JSON, parseFloat, parseInt, isFinite, BigInt, ...extraGlobals });
    return module.exports;
}

export const en = JSON.parse(read('resources/js/locales/en.json'));
export const ar = JSON.parse(read('resources/js/locales/ar.json'));
export const get = (tree, key) => key.split('.').reduce((node, part) => node?.[part], tree);
export const leaves = (tree, prefix) => Object.entries(tree).flatMap(([k, v]) => (typeof v === 'object' && v !== null ? leaves(v, `${prefix}.${k}`) : [`${prefix}.${k}`]));

/** Every leaf of an EN section exists, non-empty, in AR too (and vice versa). */
export function assertBilingual(section) {
    const enNode = get(en, section);
    const arNode = get(ar, section);
    assert.ok(enNode && typeof enNode === 'object', `en.${section}`);
    assert.ok(arNode && typeof arNode === 'object', `ar.${section}`);
    const enLeaves = leaves(enNode, section).sort();
    const arLeaves = leaves(arNode, section).sort();
    assert.deepEqual(arLeaves, enLeaves, `${section}: the same keys in English and Arabic`);
    for (const key of enLeaves) {
        assert.ok(String(get(en, key)).trim() !== '', `en.${key}`);
        assert.ok(String(get(ar, key)).trim() !== '', `ar.${key}`);
    }
}

/** Every t('…') key used in a source text exists in both locales. */
export function assertKeysExist(text, label) {
    const keys = [...text.matchAll(/\bt\(\s*['`]([a-z0-9_.]+)['`]/g)].map((m) => m[1]);
    for (const key of keys) {
        assert.notEqual(get(en, key), undefined, `${label}: en.${key}`);
        assert.notEqual(get(ar, key), undefined, `${label}: ar.${key}`);
    }
    return keys;
}
