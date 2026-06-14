<script setup lang="ts">
// PD5 — the cash-model purchase-cost trio (item cost + delivery + "no cost")
// shared by every "buy / receive new stock" form. A positive cost is required
// unless "No cost" is ticked; delivery books a separate 'delivery' expense.
// PT — plus an optional tax PAID on the item cost (rate or amount).
import { useI18n } from 'vue-i18n';
import type { Tax } from '@/lib/api/taxes';
import PurchaseTaxField, { type PurchaseTaxModel } from '@/Pages/Merchant/Inventory/PurchaseTaxField.vue';

export interface PurchaseCostModel {
    total_cost: string | number;
    delivery_cost: string | number;
    no_cost: boolean;
    tax_amount: string | number;
    tax_rate: string | number | null;
}

const model = defineModel<PurchaseCostModel>({ required: true });
defineProps<{ costPlaceholder?: string; taxes?: Tax[] }>();

const { t } = useI18n();

function patch(partial: Partial<PurchaseCostModel>): void {
    model.value = { ...model.value, ...partial };
}

// Bridge the shared tax control's { tax_amount, tax_rate } into this model.
function patchTax(tax: PurchaseTaxModel): void {
    patch({ tax_amount: tax.tax_amount, tax_rate: tax.tax_rate });
}
</script>

<template>
    <div class="space-y-2 rounded-lg border border-slate-200 bg-slate-50/60 p-2.5">
        <div class="flex flex-wrap items-end gap-3">
            <label class="block">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.purchase_cost.cost') }} (OMR) *</span>
                <input
                    :value="model.total_cost"
                    :disabled="model.no_cost"
                    type="number"
                    step="0.001"
                    min="0"
                    :placeholder="costPlaceholder ?? '0.000'"
                    class="mt-1 w-32 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-100 disabled:text-slate-400"
                    @input="patch({ total_cost: ($event.target as HTMLInputElement).value })"
                >
            </label>
            <label class="block">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.purchase_cost.delivery') }} (OMR)</span>
                <input
                    :value="model.delivery_cost"
                    :disabled="model.no_cost"
                    type="number"
                    step="0.001"
                    min="0"
                    placeholder="0.000"
                    class="mt-1 w-28 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-100 disabled:text-slate-400"
                    @input="patch({ delivery_cost: ($event.target as HTMLInputElement).value })"
                >
            </label>
            <label class="flex items-center gap-1.5 pb-2 text-xs font-medium text-slate-600">
                <input
                    :checked="model.no_cost"
                    type="checkbox"
                    class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                    @change="patch({ no_cost: ($event.target as HTMLInputElement).checked })"
                >
                {{ t('inventory.purchase_cost.no_cost') }}
            </label>
        </div>
        <PurchaseTaxField
            v-if="taxes !== undefined"
            :model-value="{ tax_amount: model.tax_amount, tax_rate: model.tax_rate }"
            :base="Number(model.total_cost) || 0"
            :taxes="taxes"
            :disabled="model.no_cost"
            @update:model-value="patchTax"
        />
        <p class="text-[11px] text-slate-500">{{ model.no_cost ? t('inventory.purchase_cost.no_cost_hint') : t('inventory.purchase_cost.hint') }}</p>
    </div>
</template>
