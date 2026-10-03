<script setup lang="ts">
/**
 * LAUNCH-P3 P3-4 — the Prep items tab of the Inventory page.
 *
 * A prep item (sauce, dough) has its own recipe per batch and a yield.
 * Dishes, cooked products and add-on options use it like an ingredient; when
 * a dish sells, the raw ingredients behind it come off stock automatically.
 * It has NO stock of its own, so it never appears on the stock screens.
 * Opening a row shows its recipe, live cost and history; creating or
 * changing one needs "Edit recipes".
 */
import { ChefHat, Plus } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { usePermissions } from '@/composables/usePermissions';
import { listPrepItems, type PrepItem } from '@/lib/api/prepItems';
import { canWriteRecipes } from '@/lib/permissions';
import { friendlyAmount } from '@/lib/itemKind';
import { money } from '@/lib/recipeUnits';

const { t, locale } = useI18n();
const { can } = usePermissions();

// Fix order 1, L8 — the one rule for every recipe write: "Edit recipes" + catalogue view.
const canEditRecipes = computed(() => canWriteRecipes(can));
const isArabic = computed(() => locale.value === 'ar');

const items = ref<PrepItem[]>([]);
const loading = ref(true);
const failed = ref(false);

onMounted(async () => {
    try {
        items.value = (await listPrepItems()).data;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});

defineExpose({ count: computed(() => items.value.length) });

/** A8 — what one batch makes, as people read it ("2 l"). */
function yieldText(item: PrepItem): string {
    const batch = friendlyAmount(item.prep_yield_quantity, item.unit);
    return `${batch.amount} ${batch.unit}`;
}

function usedBy(item: PrepItem): string {
    const used = item.used_by;
    if (!used) return '—';
    const parts: string[] = [];
    if (used.product_recipes > 0) parts.push(t('prep_items.used_products', { n: used.product_recipes }));
    if (used.addon_lines > 0) parts.push(t('prep_items.used_addons', { n: used.addon_lines }));
    if (used.prep_recipes > 0) parts.push(t('prep_items.used_preps', { n: used.prep_recipes }));
    return parts.length > 0 ? parts.join(' · ') : t('prep_items.unused');
}
</script>

<template>
    <section class="space-y-4" data-test="prep-items-tab">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="max-w-2xl text-xs text-slate-500">{{ t('prep_items.subtitle') }}</p>
            <RouterLink
                v-if="canEditRecipes"
                to="/inventory/prep-items/new"
                data-test="new-prep-item"
                class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
            >
                <Plus class="size-4" />
                {{ t('prep_items.new') }}
            </RouterLink>
        </div>

        <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
            {{ t('common.loading') }}
        </div>
        <div v-else-if="failed" class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">
            {{ t('prep_items.load_failed') }}
        </div>
        <div v-else-if="items.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
            <ChefHat class="mx-auto size-10 text-slate-300" />
            <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('prep_items.empty') }}</p>
        </div>
        <div v-else class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('prep_items.columns.name') }}</th>
                        <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('prep_items.columns.yield') }}</th>
                        <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('prep_items.columns.unit_cost') }}</th>
                        <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('prep_items.columns.batch_cost') }}</th>
                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('prep_items.columns.used_by') }}</th>
                        <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <tr v-for="item in items" :key="item.uuid" class="transition hover:bg-slate-50">
                        <td class="px-5 py-4">
                            <span class="block text-sm font-semibold text-slate-950">{{ isArabic && item.name_ar ? item.name_ar : item.name }}</span>
                            <span class="mt-0.5 inline-block rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700">{{ t('prep_items.level', { n: item.depth }) }}</span>
                        </td>
                        <!-- LAUNCH item kind, A8 — "2 l", not "2000.0000 ml". -->
                        <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-700" data-test="prep-yield">{{ yieldText(item) }}</td>
                        <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-950">{{ item.unit_cost }} <span class="text-[10px] text-slate-400">OMR / {{ item.unit }}</span></td>
                        <!-- Fix order 1, UI-1 — a batch costs money: 3 decimals (the cost per unit keeps 6). -->
                        <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-950" data-test="prep-batch-cost">{{ money(item.batch_cost) }} <span class="text-[10px] text-slate-400">OMR</span></td>
                        <td class="px-5 py-4 text-xs text-slate-600">{{ usedBy(item) }}</td>
                        <td class="px-5 py-4 text-end">
                            <RouterLink :to="`/inventory/prep-items/${item.uuid}`" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50">
                                {{ canEditRecipes ? t('inventory.actions.edit') : t('prep_items.view') }}
                            </RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
