<script setup lang="ts">
// PT — the optional purchase/input tax control, shared by the GRN lines +
// charges, the stock-receive cost block, and the manual expense form. The user
// picks a rate from the company's tax list (auto-computes off the NET `base`) OR
// types an exact amount; emits { tax_amount, tax_rate } (tax_rate NULL = a typed
// amount). With "No tax" it emits { 0, null }. The after-tax total is shown so
// "before and after tax" is visible. Single-emit-per-change (the codebase trap).
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Tax } from '@/lib/api/taxes';

export interface PurchaseTaxModel {
    tax_amount: string | number;
    tax_rate: string | number | null;
}

const model = defineModel<PurchaseTaxModel>({ required: true });
const props = defineProps<{ base: number; taxes: Tax[]; disabled?: boolean }>();

const { t, locale } = useI18n();
const isAr = computed(() => locale.value === 'ar');

function round3(n: number): number {
    return Math.round(n * 1000) / 1000;
}

// Local control state seeded from the incoming model. choice is 'none', a
// 'rate:<taxId>', or 'manual'.
function seedChoice(): string {
    const rate = model.value.tax_rate;
    if (rate !== null && rate !== '' && Number(rate) > 0) {
        const match = props.taxes.find((x) => Number(x.rate_percent) === Number(rate));
        return match ? `rate:${match.id}` : 'manual';
    }
    return Number(model.value.tax_amount) > 0 ? 'manual' : 'none';
}

const choice = ref<string>(seedChoice());
const manualAmount = ref<string>(
    Number(model.value.tax_amount) > 0 && (model.value.tax_rate === null || model.value.tax_rate === '')
        ? String(model.value.tax_amount)
        : '',
);

function taxLabel(tax: Tax): string {
    const name = (isAr.value && tax.name_ar) ? tax.name_ar : tax.name;
    return `${name} (${Number(tax.rate_percent)}%)`;
}

function recompute(): void {
    if (props.disabled || choice.value === 'none') {
        model.value = { tax_amount: 0, tax_rate: null };
        return;
    }
    if (choice.value.startsWith('rate:')) {
        const id = Number(choice.value.slice(5));
        const tax = props.taxes.find((x) => x.id === id);
        const r = tax ? Number(tax.rate_percent) : 0;
        model.value = {
            tax_amount: round3((Number(props.base) || 0) * r / 100),
            tax_rate: r > 0 ? r : null,
        };
        return;
    }
    // manual
    model.value = { tax_amount: manualAmount.value === '' ? 0 : manualAmount.value, tax_rate: null };
}

// Recompute a rate-based tax when the base cost changes, or when disabled flips.
watch(() => props.base, () => { if (choice.value.startsWith('rate:')) recompute(); });
watch(() => props.disabled, () => recompute());

const taxAmount = computed(() => Number(model.value.tax_amount) || 0);
const afterTax = computed(() => round3((Number(props.base) || 0) + taxAmount.value));

function money(n: number): string {
    return n.toLocaleString(isAr.value ? 'ar' : 'en-GB', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}
</script>

<template>
    <div class="flex flex-wrap items-end gap-2">
        <label class="block">
            <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ t('purchase_tax.label') }}</span>
            <select
                v-model="choice"
                :disabled="disabled"
                class="mt-1 block rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-100 disabled:text-slate-400"
                @change="recompute"
            >
                <option value="none">{{ t('purchase_tax.none') }}</option>
                <option v-for="tax in taxes" :key="tax.id" :value="`rate:${tax.id}`">{{ taxLabel(tax) }}</option>
                <option value="manual">{{ t('purchase_tax.manual') }}</option>
            </select>
        </label>
        <label v-if="choice === 'manual'" class="block">
            <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ t('purchase_tax.amount') }} (OMR)</span>
            <input
                v-model="manualAmount"
                :disabled="disabled"
                type="number"
                step="0.001"
                min="0"
                placeholder="0.000"
                class="mt-1 w-24 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-100"
                @input="recompute"
            >
        </label>
        <p v-if="!disabled && (taxAmount > 0 || choice !== 'none')" class="pb-1.5 text-[11px] text-slate-500">
            {{ t('purchase_tax.summary', { tax: money(taxAmount), total: money(afterTax) }) }}
        </p>
    </div>
</template>
