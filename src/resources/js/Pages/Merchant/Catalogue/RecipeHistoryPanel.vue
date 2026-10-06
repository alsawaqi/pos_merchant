<script setup lang="ts">
/**
 * LAUNCH-P3 P3-2 — a recipe's visible history (product or prep item): each
 * change with its version number, date, who, every line added / removed /
 * changed (before → after, in the unit it was entered in) and the note.
 * Newest first. Read-only; loads itself from the given loader.
 */
import { History } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { RecipeHistory, RecipeLineChange } from '@/lib/api/catalogue';
import { friendlyAmount } from '@/lib/itemKind';
import { ALL_ORDER_TYPES, ticked } from '@/lib/orderTypes';

const props = defineProps<{
    /** Fetches the history (product or prep item endpoint). */
    load: () => Promise<{ data: RecipeHistory }>;
    /** Bump to reload after a save. */
    refreshKey?: number;
    /** The prep item's unit, to label yield changes. */
    yieldUnit?: string | null;
}>();

const { t, locale } = useI18n();

const history = ref<RecipeHistory | null>(null);
const loading = ref(false);
const failed = ref(false);
const open = ref(false);

async function fetchHistory(): Promise<void> {
    loading.value = true;
    failed.value = false;
    try {
        history.value = (await props.load()).data;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(fetchHistory);
watch(() => props.refreshKey, fetchHistory);

const versions = computed(() => history.value?.versions ?? []);

function when(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * LAUNCH packaging add-on — a change's "Used for" ticks ("To go · Delivery",
 * "All → To go"); '' when the line is for every order type on both sides.
 */
function typesText(c: RecipeLineChange): string {
    const text = (mask: number | null | undefined): string => (mask === null || mask === undefined
        ? ''
        : mask === ALL_ORDER_TYPES ? t('order_types.all') : ticked(mask).map((b) => t(`order_types.short.${b}`)).join(' · '));
    if (c.before_order_types === undefined && c.after_order_types === undefined) return '';
    if (c.change === 'changed' && c.before_order_types !== c.after_order_types) {
        return `${text(c.before_order_types)} → ${text(c.after_order_types)}`;
    }
    return text(c.change === 'removed' ? c.before_order_types : c.after_order_types);
}

/** LAUNCH item kind, A8 — a prep yield as people read it ("2 l", not "2000 ml"). */
function yieldText(quantity: string | null | undefined): string {
    const batch = friendlyAmount(quantity, props.yieldUnit ?? '');
    return batch.unit === '' ? batch.amount : `${batch.amount} ${batch.unit}`;
}

function changeClass(change: string): string {
    if (change === 'added') return 'bg-emerald-50 text-emerald-700';
    if (change === 'removed') return 'bg-rose-50 text-rose-700';
    return 'bg-amber-50 text-amber-800';
}
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-test="recipe-history">
        <button type="button" class="flex w-full items-center justify-between gap-2 text-start" @click="open = !open">
            <span class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                <History class="size-4 text-slate-500" />
                {{ t('recipe_history.title') }}
                <span v-if="versions.length > 0" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">
                    {{ t('recipe_history.version', { n: history?.current.version ?? versions.length }) }}
                </span>
            </span>
            <span class="text-xs font-semibold text-teal-700">{{ open ? t('recipe_history.hide') : t('recipe_history.show') }}</span>
        </button>

        <div v-if="open" class="mt-3">
            <p v-if="loading" class="text-xs text-slate-500">{{ t('common.loading') }}</p>
            <p v-else-if="failed" class="text-xs text-rose-600">{{ t('recipe_history.load_failed') }}</p>
            <p v-else-if="versions.length === 0" class="text-xs italic text-slate-500">{{ t('recipe_history.empty') }}</p>
            <ol v-else class="space-y-3">
                <li v-for="v in versions" :key="v.version" class="rounded-lg border border-slate-200 p-3" data-test="recipe-history-version">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="text-sm font-semibold text-slate-900">
                            {{ t('recipe_history.version', { n: v.version }) }}
                            <span v-if="v.event === 'created'" class="ms-1 text-[11px] font-normal text-slate-500">· {{ t('recipe_history.created') }}</span>
                        </span>
                        <span class="text-xs text-slate-500">
                            {{ when(v.edited_at) }} · {{ v.edited_by?.name ?? t('recipe_history.unknown_user') }}
                        </span>
                    </div>
                    <p v-if="v.note" class="mt-1 rounded bg-slate-50 px-2 py-1 text-xs text-slate-700">
                        <span class="font-semibold">{{ t('recipe_history.note') }}:</span> {{ v.note }}
                    </p>
                    <!-- Fix order 1, L4 — amounts and arrows are isolated left-to-right so "150 g → 120 g" never garbles in Arabic. -->
                    <p v-if="v.yield_after !== undefined && v.yield_after !== null && v.yield_before !== v.yield_after" class="mt-1 text-xs text-slate-700">
                        {{ t('recipe_history.yield') }}:
                        <bdi dir="ltr" class="tabular-nums"><template v-if="v.yield_before">{{ yieldText(v.yield_before) }} → </template>{{ yieldText(v.yield_after) }}</bdi>
                    </p>
                    <ul v-if="v.changes.length > 0" class="mt-2 space-y-1">
                        <li v-for="(c, ci) in v.changes" :key="`${c.ingredient_id}-${c.change}-${ci}`" class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase" :class="changeClass(c.change)">{{ t(`recipe_history.${c.change}`) }}</span>
                            <span class="font-medium text-slate-900">{{ c.ingredient }}</span>
                            <bdi dir="ltr" class="tabular-nums text-slate-600" data-test="recipe-history-amount">
                                <template v-if="c.change === 'changed'">{{ c.before }} → {{ c.after }}</template>
                                <template v-else-if="c.change === 'added'">{{ c.after }}</template>
                                <template v-else>{{ c.before }}</template>
                            </bdi>
                            <!-- LAUNCH packaging add-on — the "Used for" ticks, when not every order type. -->
                            <span v-if="typesText(c)" class="text-[11px] text-slate-500" data-test="recipe-history-types">({{ t('order_types.used_for') }}: {{ typesText(c) }})</span>
                        </li>
                    </ul>
                    <p v-else-if="!v.note" class="mt-1 text-xs italic text-slate-400">{{ t('recipe_history.no_line_changes') }}</p>
                </li>
            </ol>
        </div>
    </section>
</template>
