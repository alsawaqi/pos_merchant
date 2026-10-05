<script setup lang="ts">
/**
 * LAUNCH review add-on (E2) — "Is this right?": the amounts that look
 * unrealistic, with "Yes, save" and "Go back". It warns and never blocks
 * (see composables/useAmountConfirm).
 */
import { AlertTriangle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import type { AmountWarning } from '@/lib/amountSafety';

defineProps<{ warnings: AmountWarning[] }>();
const emit = defineEmits<{ (e: 'answer', ok: boolean): void }>();
const { t } = useI18n();
</script>

<template>
    <BaseModal v-if="warnings.length > 0" :title="t('amount_safety.confirm_title')" size="md" @close="emit('answer', false)">
        <template #icon>
            <span class="grid size-9 place-items-center rounded-lg bg-amber-50 text-amber-600">
                <AlertTriangle class="size-5" />
            </span>
        </template>
        <p class="text-sm text-slate-600">{{ t('amount_safety.confirm_body') }}</p>
        <ul class="mt-3 space-y-2" data-test="amount-warnings">
            <li v-for="(w, i) in warnings" :key="i" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">
                {{ t(w.key, w.params) }}
            </li>
        </ul>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" data-test="amount-confirm-back" @click="emit('answer', false)">
                    {{ t('amount_safety.confirm_back') }}
                </button>
                <button type="button" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700" data-test="amount-confirm-yes" @click="emit('answer', true)">
                    {{ t('amount_safety.confirm_yes') }}
                </button>
            </div>
        </template>
    </BaseModal>
</template>
