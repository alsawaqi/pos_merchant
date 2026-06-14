<script setup lang="ts">
/**
 * Assign (hire) a staff member straight into THIS branch, inline from the branch
 * control center. Reuses POST /api/pos-staff (createPosStaff) with branch_id
 * pinned to the branch; the server mints a one-shot PIN we reveal once.
 */
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import { ApiError } from '@/lib/api';
import { createPosStaff } from '@/lib/api/posStaff';
import { StaffPosition, type StaffPositionValue } from '@/lib/staff';

const props = defineProps<{ branchId: number; branchName: string }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>();

const { t } = useI18n();

const positions = Object.values(StaffPosition);

const form = reactive<{ name: string; position: StaffPositionValue; phone: string }>({
    name: '',
    position: StaffPosition.Cashier,
    phone: '',
});
const busy = ref(false);
const error = ref<string | null>(null);
const createdPin = ref<string | null>(null);
const createdName = ref('');

async function submit(): Promise<void> {
    if (form.name.trim() === '' || busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        const res = await createPosStaff({
            name: form.name.trim(),
            branch_id: props.branchId,
            position: form.position,
            phone: form.phone.trim() || null,
        });
        createdName.value = res.data.name;
        createdPin.value = res.plaintext_pin;
        emit('saved');
    } catch (e) {
        error.value = e instanceof ApiError
            ? (e.firstValidationMessage() ?? e.message ?? t('branches.assign_staff.failed'))
            : t('branches.assign_staff.failed');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <BaseModal
        :title="t('branches.assign_staff.title', { branch: branchName })"
        size="md"
        :loading="busy"
        @close="emit('close')"
    >
        <!-- One-shot PIN reveal after a successful hire. -->
        <div v-if="createdPin" class="space-y-3 text-center">
            <p class="text-sm text-slate-600">{{ t('branches.assign_staff.created', { name: createdName }) }}</p>
            <div class="rounded-xl border border-teal-200 bg-teal-50 px-4 py-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">{{ t('branches.assign_staff.pin_label') }}</p>
                <p class="mt-1 font-mono text-3xl font-bold tracking-[0.3em] text-teal-900">{{ createdPin }}</p>
            </div>
            <p class="text-xs text-rose-600">{{ t('branches.assign_staff.pin_hint') }}</p>
        </div>

        <div v-else class="space-y-4">
            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ t('branches.assign_staff.name') }} *</span>
                <input v-model="form.name" type="text" maxlength="120" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ t('branches.assign_staff.position') }}</span>
                <select v-model="form.position" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <option v-for="p in positions" :key="p" :value="p">{{ t(`pos_staff.positions.${p}`) }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ t('branches.assign_staff.phone') }}</span>
                <input v-model="form.phone" type="text" maxlength="40" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
            </label>
            <p class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                {{ t('branches.assign_staff.branch_note', { branch: branchName }) }}
            </p>
            <p v-if="error" class="text-sm text-rose-600">{{ error }}</p>
        </div>

        <template #footer>
            <div class="flex justify-end gap-3">
                <button type="button" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="emit('close')">
                    {{ createdPin ? t('common.close') : t('common.cancel') }}
                </button>
                <button
                    v-if="!createdPin"
                    type="button"
                    :disabled="busy || form.name.trim() === ''"
                    class="rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-60"
                    @click="submit"
                >
                    {{ busy ? t('common.saving') : t('branches.assign_staff.submit') }}
                </button>
            </div>
        </template>
    </BaseModal>
</template>
