<script setup lang="ts">
/**
 * LAUNCH-P4 B4 — switch an item sold out / back on sale, per branch (owner
 * decision 4). It applies on every channel at that branch and stays until
 * switched back; stock never drives it. Lists the branches the user may act
 * on (the server keeps branch-limited users to their own branches).
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import { ApiError } from '@/lib/api';
import { setProductSoldOut, type Product } from '@/lib/api/catalogue';
import type { Branch as BranchLite } from '@/lib/api/branches';

const props = defineProps<{ product: Product; branches: BranchLite[] }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'updated', soldOutBranchIds: number[]): void }>();

const { t } = useI18n();
const soldOut = ref<number[]>([...(props.product.sold_out_branch_ids ?? [])]);
const busyBranch = ref<number | null>(null);
const error = ref<string | null>(null);

const title = computed(() => t('sold_out.dialog_title', { name: props.product.name }));

async function toggle(branchId: number, value: boolean): Promise<void> {
    busyBranch.value = branchId;
    error.value = null;
    try {
        const res = await setProductSoldOut(props.product.uuid, branchId, value);
        soldOut.value = res.data.sold_out_branch_ids;
        emit('updated', soldOut.value);
    } catch (err) {
        error.value = err instanceof ApiError && err.status === 403 ? t('sold_out.not_allowed') : t('sold_out.save_failed');
    } finally {
        busyBranch.value = null;
    }
}
</script>

<template>
    <BaseModal :title="title" size="md" @close="emit('close')">
        <p class="text-sm text-slate-600">{{ t('sold_out.hint') }}</p>
        <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200" data-test="sold-out-branches">
            <li v-for="branch in branches" :key="branch.id" class="flex items-center justify-between gap-3 px-3 py-2.5">
                <span class="text-sm font-medium text-slate-800">{{ branch.name }}</span>
                <label class="inline-flex items-center gap-2 text-xs font-semibold" :class="soldOut.includes(branch.id) ? 'text-rose-700' : 'text-emerald-700'">
                    <input
                        type="checkbox"
                        :checked="soldOut.includes(branch.id)"
                        :disabled="busyBranch !== null"
                        class="rounded border-slate-300 text-rose-600 focus:ring-2 focus:ring-rose-200"
                        data-test="sold-out-toggle"
                        @change="toggle(branch.id, ($event.target as HTMLInputElement).checked)"
                    >
                    {{ soldOut.includes(branch.id) ? t('sold_out.sold_out') : t('sold_out.on_sale') }}
                </label>
            </li>
            <li v-if="branches.length === 0" class="px-3 py-2.5 text-xs italic text-slate-500">{{ t('catalogue.branches.no_branches') }}</li>
        </ul>
        <p v-if="error" class="mt-2 text-xs text-rose-600">{{ error }}</p>
        <template #footer>
            <div class="flex justify-end">
                <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="emit('close')">{{ t('common.close') }}</button>
            </div>
        </template>
    </BaseModal>
</template>
