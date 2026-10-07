// Mount a real page component in node (not a test file). LAUNCH combo add-on,
// fix order 2 (C-14): behaviour tests click the real buttons of the real
// component. Only installed packages are used: vite + @vitejs/plugin-vue
// bundle the component (vue included) with the HTTP layer, the router, i18n
// and the layout stubbed; a small in-memory DOM stands in for the browser.
//
// The HTTP stub records every request in `calls` and answers with `respond`.
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const SRC = fileURLToPath(new URL('../../', import.meta.url));

// ---- A small DOM: what @vue/runtime-dom and v-model use ----------------------
class FakeEvent {
    constructor(type, init = {}) {
        this.type = type;
        this.bubbles = init.bubbles ?? true;
        this.defaultPrevented = false;
        this.target = null;
        this.currentTarget = null;
    }

    preventDefault() { this.defaultPrevented = true; }

    stopPropagation() { this.stopped = true; }

    stopImmediatePropagation() { this.stopped = true; }
}

class FakeNode {
    constructor(nodeType) {
        this.nodeType = nodeType;
        this.parentNode = null;
        this.childNodes = [];
        this.listeners = {};
    }

    get firstChild() { return this.childNodes[0] ?? null; }

    get nextSibling() {
        if (!this.parentNode) return null;
        const siblings = this.parentNode.childNodes;
        return siblings[siblings.indexOf(this) + 1] ?? null;
    }

    insertBefore(child, anchor) {
        if (child.parentNode) child.parentNode.removeChild(child);
        const at = anchor ? this.childNodes.indexOf(anchor) : -1;
        if (at < 0) this.childNodes.push(child); else this.childNodes.splice(at, 0, child);
        child.parentNode = this;
        return child;
    }

    appendChild(child) { return this.insertBefore(child, null); }

    removeChild(child) {
        const at = this.childNodes.indexOf(child);
        if (at >= 0) this.childNodes.splice(at, 1);
        child.parentNode = null;
        return child;
    }

    addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); }

    removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] ?? []).filter((f) => f !== fn); }

    dispatchEvent(event) {
        event.target ??= this;
        for (let node = this; node; node = node.parentNode) {
            event.currentTarget = node;
            for (const fn of [...(node.listeners[event.type] ?? [])]) fn.call(node, event);
            if (event.stopped || !event.bubbles) break;
        }
        return !event.defaultPrevented;
    }

    get textContent() {
        if (this.nodeType === 3) return this.nodeValue;
        if (this.nodeType === 8) return '';
        return this.childNodes.map((c) => c.textContent).join('');
    }

    set textContent(value) {
        if (this.nodeType === 3 || this.nodeType === 8) { this.nodeValue = value; return; }
        for (const c of this.childNodes) c.parentNode = null;
        this.childNodes = [];
        if (value !== '' && value != null) this.appendChild(document.createTextNode(String(value)));
    }
}

const VALUE_TAGS = new Set(['INPUT', 'TEXTAREA', 'OPTION', 'BUTTON', 'SELECT']);

class FakeElement extends FakeNode {
    constructor(tag, namespaceURI = null) {
        super(1);
        this.tagName = namespaceURI ? tag : tag.toUpperCase();
        this.namespaceURI = namespaceURI;
        this.attributes = new Map();
        this.className = '';
        this.style = { cssText: '', setProperty() {}, removeProperty() {} };
        this.disabled = false;
        if (this.tagName === 'INPUT') { this.checked = false; this.type = 'text'; }
        if (this.tagName === 'OPTION') this.selected = false;
        if (VALUE_TAGS.has(this.tagName) && this.tagName !== 'SELECT') this.value = '';
        if (this.tagName === 'SELECT') {
            this.multiple = false;
            Object.defineProperty(this, 'value', {
                get: () => { const o = this.options.find((x) => x.selected); return o ? String(o.value) : ''; },
                set: (v) => { for (const o of this.options) o.selected = String(o.value) === String(v); },
            });
            Object.defineProperty(this, 'selectedIndex', {
                get: () => this.options.findIndex((o) => o.selected),
                set: (i) => { this.options.forEach((o, k) => { o.selected = k === i; }); },
            });
        }
    }

    get options() { return this.findAll((n) => n.tagName === 'OPTION'); }

    setAttribute(name, value) { this.attributes.set(name, String(value)); if (name === 'type' && this.tagName === 'INPUT') this.type = String(value); }

    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }

    removeAttribute(name) { this.attributes.delete(name); }

    hasAttribute(name) { return this.attributes.has(name); }

    focus() { document.activeElement = this; }

    blur() {}

    scrollIntoView() {}

    getBoundingClientRect() { return { top: 0, left: 0, width: 0, height: 0, bottom: 0, right: 0 }; }

    /** Every descendant element matching a predicate, in document order. */
    findAll(match) {
        const out = [];
        const walk = (node) => { for (const c of node.childNodes) { if (c.nodeType === 1) { if (match(c)) out.push(c); walk(c); } } };
        walk(this);
        return out;
    }

    querySelector() { return null; }
}

class FakeText extends FakeNode {
    constructor(text, nodeType = 3) { super(nodeType); this.nodeValue = text; }
}

export function installDom() {
    const body = new FakeElement('body');
    const doc = {
        body,
        activeElement: null,
        documentElement: new FakeElement('html'),
        createElement: (tag) => new FakeElement(tag),
        createElementNS: (ns, tag) => new FakeElement(tag, ns),
        createTextNode: (text) => new FakeText(text),
        createComment: (text) => new FakeText(text, 8),
        querySelector: (sel) => (sel === 'body' ? body : null),
        addEventListener() {},
        removeEventListener() {},
    };
    doc.activeElement = body;
    const memory = new Map();
    const storage = { getItem: (k) => (memory.has(k) ? memory.get(k) : null), setItem: (k, v) => memory.set(k, String(v)), removeItem: (k) => memory.delete(k), clear: () => memory.clear() };
    Object.assign(globalThis, {
        document: doc,
        window: globalThis,
        Node: FakeNode,
        Element: FakeElement,
        HTMLElement: FakeElement,
        // Never instances: vue picks the SVG namespace for a root that is one.
        SVGElement: class SVGElement {},
        MathMLElement: class MathMLElement {},
        Event: FakeEvent,
        scrollTo() {},
        confirm: () => true,
        matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
        requestAnimationFrame: (fn) => setTimeout(fn, 0),
        cancelAnimationFrame: (id) => clearTimeout(id),
        getComputedStyle: () => ({ getPropertyValue: () => '' }),
    });
    if (typeof globalThis.addEventListener !== 'function') {
        globalThis.addEventListener = () => {};
        globalThis.removeEventListener = () => {};
    }
    try { if (!globalThis.localStorage) globalThis.localStorage = storage; } catch { /* node's own */ }
    try { if (!globalThis.sessionStorage) globalThis.sessionStorage = storage; } catch { /* node's own */ }
    return doc;
}

// ---- Stubs ---------------------------------------------------------------------
const STUBS = {
    'vue-router': `
        import { defineComponent, h } from 'vue';
        export function useRoute() { return globalThis.__route; }
        export function useRouter() { const go = async (to) => { globalThis.__navigations.push(to); }; return { push: go, replace: go, back() {} }; }
        export function onBeforeRouteLeave() {}
        export const RouterLink = defineComponent({ props: { to: null }, setup(_, { slots }) { return () => h('a', {}, slots.default?.()); } });
    `,
    'vue-i18n': `
        import { ref } from 'vue';
        const locale = ref('en');
        export function useI18n() { return { t: (key) => key, te: () => true, locale }; }
    `,
    api: `
        export class ApiError extends Error {
            constructor(status, payload, message) { super(message ?? 'Request failed with status ' + status); this.status = status; this.payload = payload; this.name = 'ApiError'; }
            isValidationError() { return this.status === 422 && !!this.payload && typeof this.payload === 'object' && 'errors' in this.payload; }
            firstValidationMessage() { if (!this.isValidationError()) return null; for (const m of Object.values(this.payload.errors)) { if (Array.isArray(m) && m.length > 0) return m[0]; } return null; }
        }
        const call = (method) => async (url, body) => { globalThis.__calls.push({ method, url, body }); return globalThis.__respond(method, url, body); };
        export const apiGet = call('GET');
        export const apiPost = call('POST');
        export const apiPatch = call('PATCH');
        export const apiPut = call('PUT');
        export const apiDelete = call('DELETE');
        export const apiUpload = call('UPLOAD');
        export const apiDownload = call('DOWNLOAD');
    `,
    layout: `
        import { defineComponent, h } from 'vue';
        export default defineComponent({ setup(_, { slots }) { return () => h('main', {}, slots.default?.()); } });
    `,
};

function stubFor(id) {
    if (id === 'vue-router' || id === 'vue-i18n') return id;
    const path = id.replace(/\\/g, '/').replace(/\?.*$/, '');
    if (path === '@/lib/api' || /\/resources\/js\/lib\/api(\.ts)?$/.test(path)) return 'api';
    if (/\/Layouts\/MerchantLayout\.vue$/.test(path)) return 'layout';
    return null;
}

/**
 * Bundle a page component (path from src/) with vue and the stubs, and load
 * it. Returns { mount(route), nextTick, authState, MerchantRole }.
 */
export async function loadPage(componentPath) {
    installDom();
    const { build } = await import('vite');
    const { default: vue } = await import('@vitejs/plugin-vue');
    const entryId = '\0component-entry';
    const component = join(SRC, componentPath).replace(/\\/g, '/');
    const out = await build({
        configFile: false,
        root: SRC,
        logLevel: 'error',
        cacheDir: join(tmpdir(), 'component-support-vite'),
        define: { 'process.env.NODE_ENV': '"production"', __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
        resolve: { alias: [{ find: /^@\//, replacement: `${join(SRC, 'resources/js').replace(/\\/g, '/')}/` }] },
        plugins: [
            {
                name: 'component-support-stubs',
                enforce: 'pre',
                resolveId(id) {
                    if (id === 'component-entry') return entryId;
                    const stub = stubFor(id);
                    return stub ? `\0stub:${stub}` : null;
                },
                load(id) {
                    if (id === entryId) {
                        return `
                            import { createApp, nextTick } from 'vue';
                            import Page from ${JSON.stringify(component)};
                            import { authState } from '@/stores/auth';
                            import { MerchantRole } from '@/lib/permissions';
                            export { nextTick, authState, MerchantRole };
                            export function mount(root) { const app = createApp(Page); app.config.warnHandler = () => {}; app.mount(root); return app; }
                        `;
                    }
                    if (id.startsWith('\0stub:')) return STUBS[id.slice('\0stub:'.length)];
                    return null;
                },
            },
            vue({ template: { compilerOptions: { hoistStatic: false } } }),
        ],
        build: {
            write: false,
            minify: false,
            emptyOutDir: false,
            copyPublicDir: false,
            modulePreload: false,
            rollupOptions: { input: 'component-entry', preserveEntrySignatures: 'strict', output: { format: 'es', codeSplitting: false } },
        },
    });
    const outputs = (Array.isArray(out) ? out : [out]).flatMap((o) => o.output);
    const chunk = outputs.find((o) => o.type === 'chunk' && o.isEntry);
    const dir = mkdtempSync(join(tmpdir(), 'component-support-'));
    const file = join(dir, 'page.mjs');
    writeFileSync(file, chunk.code);
    return import(pathToFileURL(file).href);
}

/** Let promises, watchers and re-renders settle. */
export async function settle(page, rounds = 6) {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
        await page.nextTick();
    }
}

/** Elements under `root` with this data-test hook. */
export const byTest = (root, hook) => root.findAll((n) => n.getAttribute?.('data-test') === hook);

/** The buttons under `root` whose text (i18n keys in these tests) contains `text`. */
export const buttons = (root, text) => root.findAll((n) => n.tagName === 'BUTTON' && n.textContent.includes(text));

export function fire(el, type) {
    el.dispatchEvent(new FakeEvent(type));
}

/** Type into an input (v-model / @input). */
export function type(el, value) {
    el.value = String(value);
    fire(el, 'input');
}

/** Pick an option of a select (v-model / @change). */
export function pick(select, value) {
    select.value = value;
    fire(select, 'change');
}
