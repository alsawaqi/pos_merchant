<script setup lang="ts">
/**
 * LAUNCH-P4 B6 — menu import and export (owner decision 5).
 *
 *   1. Download the Excel template (or export the current menu in the same
 *      columns).
 *   2. Upload the filled .xlsx (or CSV UTF-8) → the preview: every row is New,
 *      Update, No change or Error, with its messages in English or Arabic.
 *      Matching is by SKU, else by exact name, so uploading twice never
 *      duplicates. "Create missing categories" adds the categories it needs.
 *   3. Save → everything in one go, or nothing while a row has an error.
 * Photos, add-ons and combos are added in the portal afterwards.
 */
import { ArrowLeft, Download, FileSpreadsheet, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    commitMenuImport,
    downloadMenuExport,
    downloadMenuTemplate,
    previewMenuImport,
    type ImportIssue,
    type ImportPreview,
    type ImportResult,
} from '@/lib/api/menuImport';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.CatalogueManage));

const file = ref<File | null>(null);
const createCategories = ref(false);
const preview = ref<ImportPreview | null>(null);
const result = ref<ImportResult | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);

const canSave = computed(() => preview.value !== null
    && preview.value.summary.error === 0
    && preview.value.summary.new + preview.value.summary.update > 0);

function apiMessage(err: unknown, fallback: string): string {
    if (err instanceof ApiError && err.payload && typeof err.payload === 'object') {
        const reason = (err.payload as { reason?: unknown }).reason;
        if (typeof reason === 'string') {
            const key = `menu_import.file_errors.${reason}`;
            const text = t(key);
            if (text !== key) return text;
        }
        const message = (err.payload as { message?: unknown }).message;
        if (typeof message === 'string' && message !== '') return message;
    }
    return fallback;
}

async function guard(run: () => Promise<void>, fallback: string): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        await run();
    } catch (err) {
        error.value = apiMessage(err, fallback);
    } finally {
        busy.value = false;
    }
}

function onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    preview.value = null;
    result.value = null;
    error.value = null;
}

function runPreview(): Promise<void> {
    return guard(async () => {
        if (!file.value) return;
        result.value = null;
        preview.value = (await previewMenuImport(file.value, createCategories.value)).data;
    }, t('menu_import.preview_failed'));
}

function runSave(): Promise<void> {
    return guard(async () => {
        if (!file.value || !canSave.value) return;
        try {
            result.value = (await commitMenuImport(file.value, createCategories.value)).data;
            preview.value = result.value.preview;
        } catch (err) {
            // 422 while a row has an error: show the fresh preview.
            if (err instanceof ApiError && err.status === 422 && err.payload && typeof err.payload === 'object' && 'data' in err.payload) {
                preview.value = ((err.payload as { data: ImportResult }).data).preview;
            }
            throw err;
        }
    }, t('menu_import.save_failed'));
}

function issueText(issue: ImportIssue): string {
    const field = issue.field ? t(`menu_import.columns.${issue.field}`) : '';
    return t(`menu_import.issues.${issue.code}`, { ...issue.params, field });
}

function actionClass(action: string): string {
    switch (action) {
        case 'new': return 'bg-emerald-100 text-emerald-700';
        case 'update': return 'bg-sky-100 text-sky-700';
        case 'error': return 'bg-rose-100 text-rose-700';
        default: return 'bg-slate-100 text-slate-600';
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-6xl">
            <RouterLink :to="{ path: '/catalogue', query: { tab: 'products' } }" class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900">
                <ArrowLeft class="size-3.5" />
                {{ t('catalogue.wizard.back') }}
            </RouterLink>
            <h1 class="flex items-center gap-2 text-2xl font-bold text-slate-950">
                <FileSpreadsheet class="size-6 text-emerald-600" />
                {{ t('menu_import.title') }}
            </h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-500">{{ t('menu_import.subtitle') }}</p>

            <!-- 1. Template + export -->
            <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-slate-900">{{ t('menu_import.step_template') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500">{{ t('menu_import.step_template_hint') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" :disabled="busy" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60" data-test="download-template" @click="guard(downloadMenuTemplate, t('menu_import.download_failed'))">
                        <Download class="size-3.5" /> {{ t('menu_import.download_template') }}
                    </button>
                    <button type="button" :disabled="busy" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60" data-test="export-xlsx" @click="guard(() => downloadMenuExport('xlsx'), t('menu_import.download_failed'))">
                        <Download class="size-3.5" /> {{ t('menu_import.export_xlsx') }}
                    </button>
                    <button type="button" :disabled="busy" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60" data-test="export-csv" @click="guard(() => downloadMenuExport('csv'), t('menu_import.download_failed'))">
                        <Download class="size-3.5" /> {{ t('menu_import.export_csv') }}
                    </button>
                </div>
            </section>

            <!-- 2. Upload + preview -->
            <section v-if="canManage" class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-slate-900">{{ t('menu_import.step_upload') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500">{{ t('menu_import.step_upload_hint') }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 transition hover:bg-teal-100">
                        <Upload class="size-3.5" />
                        {{ file ? file.name : t('menu_import.choose_file') }}
                        <input type="file" accept=".xlsx,.csv" class="hidden" data-test="import-file" @change="onFile">
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="createCategories" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200" data-test="create-categories" @change="preview = null">
                        {{ t('menu_import.create_categories') }}
                    </label>
                    <button type="button" :disabled="busy || !file" class="rounded-lg bg-slate-950 px-4 py-2 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60" data-test="run-preview" @click="runPreview">
                        {{ t('menu_import.preview') }}
                    </button>
                </div>
            </section>
            <p v-else class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">{{ t('menu_import.manage_only') }}</p>

            <p v-if="error" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700" data-test="import-error">{{ error }}</p>

            <div v-if="result && result.saved" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800" data-test="import-saved">
                {{ t('menu_import.saved', { created: result.created, updated: result.updated, categories: result.categories_created }) }}
            </div>

            <!-- 3. Preview + save -->
            <section v-if="preview" class="mt-5 rounded-2xl border border-slate-200 bg-white shadow-sm" data-test="import-preview">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
                    <p class="text-sm text-slate-700">
                        {{ t('menu_import.summary', preview.summary) }}
                        <span v-if="preview.new_categories.length > 0" class="ms-1 text-xs text-slate-500">· {{ t('menu_import.new_categories', { names: preview.new_categories.map((c) => c.name).join(', ') }) }}</span>
                    </p>
                    <button v-if="!(result && result.saved)" type="button" :disabled="busy || !canSave" class="rounded-lg bg-teal-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60" data-test="run-save" @click="runSave">
                        {{ t('menu_import.save') }}
                    </button>
                </div>
                <p v-if="preview.summary.error > 0" class="px-5 pt-3 text-xs font-semibold text-rose-700">{{ t('menu_import.fix_errors') }}</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-start">{{ t('menu_import.row') }}</th>
                                <th class="px-4 py-2 text-start">{{ t('menu_import.status') }}</th>
                                <th class="px-4 py-2 text-start">{{ t('menu_import.columns.name') }}</th>
                                <th class="px-4 py-2 text-start">{{ t('menu_import.columns.category') }}</th>
                                <th class="px-4 py-2 text-end">{{ t('menu_import.columns.price') }}</th>
                                <th class="px-4 py-2 text-start">{{ t('menu_import.columns.sku') }}</th>
                                <th class="px-4 py-2 text-start">{{ t('menu_import.messages') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in preview.rows" :key="row.row" data-test="import-row">
                                <td class="px-4 py-2 tabular-nums text-slate-500">{{ row.row }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="actionClass(row.action)">{{ t(`menu_import.actions.${row.action}`) }}</span>
                                </td>
                                <td class="px-4 py-2 font-medium text-slate-900">{{ row.name || '—' }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ row.category ?? '—' }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-slate-900">{{ row.price ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ row.sku ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs">
                                    <ul class="space-y-0.5">
                                        <li v-for="(issue, i) in row.issues" :key="i" :class="issue.level === 'error' ? 'text-rose-700' : 'text-slate-500'">{{ issueText(issue) }}</li>
                                        <li v-if="row.action === 'update'" class="text-sky-700">{{ t('menu_import.changes', { fields: row.changes.map((f) => t(`menu_import.fields.${f}`)).join(', ') }) }}</li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </MerchantLayout>
</template>
