<script setup lang="ts">
/**
 * My Profile (Phase D7) — the signed-in user's own account page.
 *
 * Shows name / email / roles and lets the user edit ONLY the
 * display name (PATCH /auth/profile). Email is the admin-managed
 * login identifier (globally unique across both portals) so it
 * renders read-only with an explanatory note; password changes
 * link out to the existing /change-password page.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { BadgeCheck, KeyRound, Loader2, Mail, Save, UserRound } from 'lucide-vue-next';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { ApiError, apiPatch } from '@/lib/api';
import { authState, setAuthUserName } from '@/stores/auth';

const { t } = useI18n();

const name = ref(authState.user?.name ?? '');
const submitting = ref(false);
const saved = ref(false);
const errorMessage = ref<string | null>(null);
const fieldErrors = ref<Record<string, string>>({});

const email = computed(() => authState.user?.email ?? '');
const roles = computed(() => authState.user?.roles ?? []);

const userInitials = computed(() => {
    const source = authState.user?.name ?? 'M';
    return source
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
});

async function onSubmit(): Promise<void> {
    errorMessage.value = null;
    fieldErrors.value = {};
    saved.value = false;
    submitting.value = true;

    try {
        const response = await apiPatch<{ user: { name: string } }>('/auth/profile', {
            name: name.value,
        });
        setAuthUserName(response.user.name);
        saved.value = true;
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            for (const [key, messages] of Object.entries(err.payload.errors)) {
                if (Array.isArray(messages) && messages[0]) {
                    fieldErrors.value[key] = messages[0];
                }
            }
            errorMessage.value = err.firstValidationMessage();
        } else {
            errorMessage.value = t('profile.error_generic');
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-2xl">
            <h1 class="text-2xl font-bold tracking-tight text-slate-950">{{ t('profile.title') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ t('profile.subtitle') }}</p>

            <!-- Identity card -->
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="flex items-center gap-4">
                    <span class="grid size-14 place-items-center rounded-2xl bg-gradient-to-br from-slate-900 to-indigo-900 text-lg font-semibold text-white">
                        {{ userInitials || 'M' }}
                    </span>
                    <div>
                        <p class="text-base font-semibold text-slate-950">{{ authState.user?.name ?? '—' }}</p>
                        <p class="text-sm text-slate-500">{{ email }}</p>
                    </div>
                </div>

                <!-- Roles / positions -->
                <div v-if="roles.length" class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ t('profile.positions') }}
                    </span>
                    <span
                        v-for="role in roles"
                        :key="role"
                        class="inline-flex items-center gap-1.5 rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800"
                    >
                        <BadgeCheck class="size-3.5" />
                        {{ role }}
                    </span>
                </div>

                <div
                    v-if="errorMessage"
                    class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
                >
                    {{ errorMessage }}
                </div>
                <div
                    v-else-if="saved"
                    class="mt-6 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-semibold text-teal-700"
                >
                    {{ t('profile.saved') }}
                </div>

                <form class="mt-6 space-y-5" @submit.prevent="onSubmit">
                    <!-- Editable name -->
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-800">{{ t('profile.name') }}</span>
                        <div class="group relative mt-2">
                            <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                <UserRound class="size-4" />
                            </span>
                            <input
                                v-model="name"
                                type="text"
                                required
                                maxlength="255"
                                class="w-full rounded-xl border border-slate-200 bg-white ps-11 pe-4 py-3 text-sm font-medium text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                        </div>
                        <p v-if="fieldErrors.name" class="mt-1.5 text-xs font-semibold text-rose-600">
                            {{ fieldErrors.name }}
                        </p>
                    </label>

                    <!-- Read-only email -->
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-800">{{ t('profile.email') }}</span>
                        <div class="group relative mt-2">
                            <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400">
                                <Mail class="size-4" />
                            </span>
                            <input
                                :value="email"
                                type="email"
                                disabled
                                class="w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-50 ps-11 pe-4 py-3 text-sm font-medium text-slate-500 shadow-sm"
                            >
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">{{ t('profile.email_note') }}</p>
                    </label>

                    <button
                        type="submit"
                        :disabled="submitting"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 to-indigo-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl disabled:cursor-wait disabled:opacity-70 disabled:hover:translate-y-0"
                    >
                        <Loader2 v-if="submitting" class="size-4 animate-spin" />
                        <Save v-else class="size-4" />
                        <span>{{ submitting ? t('profile.saving') : t('profile.save') }}</span>
                    </button>
                </form>
            </div>

            <!-- Password card — links to the existing change-password page -->
            <div class="mt-6 flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="flex items-center gap-4">
                    <span class="grid size-11 place-items-center rounded-xl bg-slate-100 text-slate-700">
                        <KeyRound class="size-5" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-slate-950">{{ t('profile.change_password_cta') }}</p>
                        <p class="text-xs text-slate-500">{{ t('profile.change_password_note') }}</p>
                    </div>
                </div>
                <RouterLink
                    to="/change-password"
                    class="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-teal-50 hover:text-teal-700"
                >
                    {{ t('profile.change_password_cta') }}
                </RouterLink>
            </div>
        </div>
    </MerchantLayout>
</template>
