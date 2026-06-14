<script setup lang="ts">
/**
 * Add stock to THIS branch, inline from the branch control center. Two modes:
 *   - Ingredient → POST /api/branches/{uuid}/stock/restock (RestockAction):
 *     receive ingredient stock into the branch, with an optional unit cost.
 *   - Product    → POST /api/products/{uuid}/stock/adjust (branch_uuid, +qty):
 *     top up a unit/cooked product's branch shelf.
 * Gated by inventory.manage at the call site; both endpoints re-check it.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import { ApiError } from '@/lib/api';
import { listIngredients, restockStock, type Ingredient } from '@/lib/api/inventory';
import { adjustProductStock } from '@/lib/api/productStock';
import type { BranchProductRow } from '@/lib/api/branches';

const props = defineProps<{ branchUuid: string; branchName: string; products: BranchProductRow[] }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>();

const { t } = useI18n();

type Mode = 'ingredient' | 'product';
const mode = ref<Mode>('ingredient');

const ingredients = ref<Ingredient[]>([]);
// Only ready/bought-in ('unit') products are stocked/topped up here. Cooked
// products are production-driven (their shelf is written by the kitchen), so
// they're not offered — matching the purchase model elsewhere.
const stockProducts = computed(() => props.products.filter((p) => p.stock_mode === 'unit'));

const form = reactive<{ ingredient_uuid: string; product_uuid: string; quantity: string; unit_cost: string; note: string }>({
    ingredient_uuid: '',
    product_uuid: '',
    quantity: '',
    unit_cost: '',
    note: '',
});

const busy = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const canSubmit = computed(() => {
    if (busy.value || !(Number(form.quantity) > 0)) return false;
    return mode.value === 'ingredient' ? form.ingredient_uuid !== '' : form.product_uuid !== '';
});

function setMode(m: Mode): void {
    mode.value = m;
    success.value = null;
    error.value = null;
}

onMounted(async () => {
    try {
        ingredients.value = (await listIngredients()).data;
    } catch {
        ingredients.value = [];
    }
});

async function submit(): Promise<void> {
    if (!canSubmit.value) return;
    busy.value = true;
    error.value = null;
    success.value = null;
    try {
        if (mode.value === 'ingredient') {
            await restockStock(props.branchUuid, {
                ingredient_uuid: form.ingredient_uuid,
                quantity: form.quantity,
                unit_cost: form.unit_cost.trim() === '' ? null : form.unit_cost,
                note: form.note.trim() || null,
            });
        } else {
            await adjustProductStock(form.product_uuid, {
                branch_uuid: props.branchUuid,
                signed_quantity: form.quantity, // positive = added to this branch's shelf
                note: form.note.trim() || t('branches.add_stock.default_note'),
            });
        }
        success.value = t('branches.add_stock.success');
        emit('saved');
        // Keep the dialog open for another entry; reset the line fields.
        form.quantity = '';
        form.unit_cost = '';
        form.note = '';
    } catch (e) {
        error.value = e instanceof ApiError
            ? (e.firstValidationMessage() ?? e.message ?? t('branches.add_stock.failed'))
            : t('branches.add_stock.failed');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <BaseModal
        :title="t('branches.add_stock.title', { branch: branchName })"
        size="md"
        :loading="busy"
        @close="emit('close')"
    >
        <div class="space-y-4">
            <!-- Mode toggle -->
            <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-sm">
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 font-semibold transition"
                    :class="mode === 'ingredient' ? 'bg-teal-600 text-white' : 'text-slate-600 hover:bg-slate-50'"
                    @click="setMode('ingredient')"
                >{{ t('branches.add_stock.ingredient') }}</button>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 font-semibold transition"
                    :class="mode === 'product' ? 'bg-teal-600 text-white' : 'text-slate-600 hover:bg-slate-50'"
                    @click="setMode('product')"
                >{{ t('branches.add_stock.product') }}</button>
            </div>

            <!-- Ingredient mode -->
            <template v-if="mode === 'ingredient'">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('branches.add_stock.pick_ingredient') }} *</span>
                    <select v-model="form.ingredient_uuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option value="">{{ t('branches.add_stock.pick_ingredient') }}…</option>
                        <option v-for="ing in ingredients" :key="ing.uuid" :value="ing.uuid">{{ ing.name }} ({{ ing.unit }})</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('branches.add_stock.unit_cost') }}</span>
                    <input v-model="form.unit_cost" type="number" step="0.001" min="0" :placeholder="t('branches.add_stock.unit_cost_ph')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <span class="mt-0.5 block text-xs text-slate-400">{{ t('branches.add_stock.unit_cost_hint') }}</span>
                </label>
            </template>

            <!-- Product mode -->
            <template v-else>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('branches.add_stock.pick_product') }} *</span>
                    <select v-model="form.product_uuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option value="">{{ t('branches.add_stock.pick_product') }}…</option>
                        <option v-for="p in stockProducts" :key="p.uuid" :value="p.uuid">{{ p.name }}</option>
                    </select>
                    <span v-if="stockProducts.length === 0" class="mt-0.5 block text-xs text-amber-600">{{ t('branches.add_stock.no_products') }}</span>
                </label>
            </template>

            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ t('branches.add_stock.quantity') }} *</span>
                <input v-model="form.quantity" type="number" step="0.001" min="0.001" placeholder="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ t('branches.add_stock.note') }}</span>
                <input v-model="form.note" type="text" maxlength="200" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
            </label>

            <p v-if="success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ success }}</p>
            <p v-if="error" class="text-sm text-rose-600">{{ error }}</p>
        </div>

        <template #footer>
            <div class="flex justify-end gap-3">
                <button type="button" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="emit('close')">
                    {{ t('common.close') }}
                </button>
                <button
                    type="button"
                    :disabled="!canSubmit"
                    class="rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-60"
                    @click="submit"
                >
                    {{ busy ? t('common.saving') : t('branches.add_stock.submit') }}
                </button>
            </div>
        </template>
    </BaseModal>
</template>
