<script setup lang="ts">
/**
 * Audit Log Viewer — blueprint §5.12.
 *
 * Paginated, filterable feed of every audit-able action by the
 * tenant. Filters: event (exact match), actor user ID, branch,
 * date window. The per_page selector caps at 200.
 *
 * Old / New / Metadata are JSON columns — we render them as
 * collapsible <details> blocks so the table stays readable.
 */

import { ChevronLeft, ChevronRight, Search } from 'lucide-vue-next';
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listBranches, type Branch } from '@/lib/api/branches';
import { fetchAuditLog, type AuditLogFilter, type AuditLogPayload } from '@/lib/api/reports';
import { ApiError } from '@/lib/api';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';

const { t } = useI18n();

function defaultFilter(): AuditLogFilter {
    const today = new Date();
    const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
    return {
        date_from: monthStart.toISOString().slice(0, 10),
        date_to: today.toISOString().slice(0, 10),
        branch_ids: null,
        event: null,
        actor_id: null,
        page: 1,
        per_page: 50,
    };
}

const filter = reactive<AuditLogFilter>(defaultFilter());
const payload = ref<AuditLogPayload | null>(null);
const loading = ref<boolean>(false);
const error = ref<string | null>(null);
const branches = ref<Branch[]>([]);

async function run(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const r = await fetchAuditLog(filter);
        payload.value = r.data;
    } catch (err) {
        if (err instanceof ApiError) {
            error.value = err.status === 403 ? 'Forbidden' : `HTTP ${err.status}`;
        } else {
            error.value = t('audit_log.load_failed');
        }
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    try {
        const r = await listBranches();
        branches.value = r.data;
    } catch (err) {
        if (!(err instanceof ApiError)) throw err;
    }
    void run();
});

function goPage(page: number): void {
    filter.page = page;
    void run();
}

function onBranchChange(event: Event): void {
    const v = (event.target as HTMLSelectElement).value;
    filter.branch_ids = v === '' ? null : [Number(v)];
}

function formatJson(value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'object') return JSON.stringify(value, null, 2);
    return String(value);
}
</script>

<template>
    <MerchantLayout>
        <div class="max-w-7xl">
            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-teal-600">{{ t('audit_log.section_label') }}</span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('audit_log.title') }}</h1>
                <p class="max-w-3xl text-sm text-slate-600">{{ t('audit_log.subtitle') }}</p>
            </header>

            <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('reports.filters.date_from') }}</span>
                    <input type="date" v-model="filter.date_from" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('reports.filters.date_to') }}</span>
                    <input type="date" v-model="filter.date_to" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('reports.filters.branch_label') }}</span>
                    <select :value="filter.branch_ids?.[0] ?? ''" class="w-44 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" @change="onBranchChange">
                        <option value="">{{ t('reports.filters.branch_all') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('audit_log.filters.event') }}</span>
                    <input
                        type="text"
                        v-model="filter.event"
                        :placeholder="t('audit_log.filters.event_placeholder')"
                        class="w-56 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
                    />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('audit_log.filters.actor') }}</span>
                    <input type="number" v-model.number="filter.actor_id" min="1" class="w-36 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('audit_log.filters.per_page') }}</span>
                    <select v-model.number="filter.per_page" class="w-24 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                        <option :value="200">200</option>
                    </select>
                </label>

                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                    :disabled="loading"
                    @click="filter.page = 1; void run()"
                >
                    <Search class="size-4" />
                    {{ loading ? t('reports.filters.running') : t('reports.filters.run') }}
                </button>
            </div>

            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <div v-if="payload" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <table v-if="payload.rows.length" class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('audit_log.columns.time') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('audit_log.columns.event') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('audit_log.columns.actor') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('audit_log.columns.target') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('audit_log.columns.ip') }}</th>
                            <th class="px-5 py-2 text-end">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.rows" :key="row.id" class="border-b border-slate-100 last:border-0 align-top">
                            <td class="px-5 py-2 text-xs tabular-nums text-slate-600">{{ row.created_at }}</td>
                            <td class="px-5 py-2 font-medium text-slate-900">{{ row.event }}</td>
                            <td class="px-5 py-2 text-slate-700">
                                <span v-if="row.actor_name">{{ row.actor_name }}</span>
                                <span v-else class="text-slate-400">—</span>
                                <div v-if="row.actor_email" class="text-xs text-slate-500">{{ row.actor_email }}</div>
                            </td>
                            <td class="px-5 py-2 text-xs text-slate-600">
                                <span v-if="row.auditable_type">{{ row.auditable_type }}#{{ row.auditable_id }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-5 py-2 text-xs tabular-nums text-slate-500">{{ row.ip_address ?? '—' }}</td>
                            <td class="px-5 py-2 text-end">
                                <details v-if="row.old_values || row.new_values || row.metadata" class="text-xs">
                                    <summary class="cursor-pointer text-teal-700 hover:underline">View</summary>
                                    <div class="mt-2 grid gap-2 text-start">
                                        <div v-if="row.old_values">
                                            <div class="font-semibold text-slate-600">Old</div>
                                            <pre class="overflow-x-auto rounded border border-slate-200 bg-slate-50 p-2">{{ formatJson(row.old_values) }}</pre>
                                        </div>
                                        <div v-if="row.new_values">
                                            <div class="font-semibold text-slate-600">New</div>
                                            <pre class="overflow-x-auto rounded border border-slate-200 bg-slate-50 p-2">{{ formatJson(row.new_values) }}</pre>
                                        </div>
                                        <div v-if="row.metadata">
                                            <div class="font-semibold text-slate-600">Metadata</div>
                                            <pre class="overflow-x-auto rounded border border-slate-200 bg-slate-50 p-2">{{ formatJson(row.metadata) }}</pre>
                                        </div>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('audit_log.no_rows') }}</div>

                <div class="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                    <div>Page {{ payload.meta.current_page }} of {{ payload.meta.last_page }} · {{ payload.meta.total }} total</div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50"
                            :disabled="payload.meta.current_page <= 1 || loading"
                            @click="goPage(payload!.meta.current_page - 1)"
                        >
                            <ChevronLeft class="size-3.5" /> Prev
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50"
                            :disabled="payload.meta.current_page >= payload.meta.last_page || loading"
                            @click="goPage(payload!.meta.current_page + 1)"
                        >
                            Next <ChevronRight class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>
