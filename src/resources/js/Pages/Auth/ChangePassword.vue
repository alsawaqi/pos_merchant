<script setup lang="ts">
/**
 * Self-service + forced password change.
 *
 * Two entry paths converge here:
 *   - Forced: a freshly-minted account (must_change_password) is
 *     redirected here by the router guard and cannot leave until it
 *     sets a new password. The cancel link is hidden.
 *   - Self-service: reached from the account menu; the user can cancel
 *     back to where they came from.
 *
 * A visually-hidden username field + new-password autocomplete hints let
 * the browser's password manager update the stored credential.
 */
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowRight, Eye, EyeOff, Loader2, Lock, ShieldCheck } from 'lucide-vue-next';
import { ApiError, apiPost } from '@/lib/api';
import { authState, clearMustChangePassword } from '@/stores/auth';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const currentPassword = ref('');
const newPassword = ref('');
const confirmPassword = ref('');
const showNew = ref(false);
const submitting = ref(false);
const errorMessage = ref<string | null>(null);
const fieldErrors = ref<Record<string, string>>({});

const isForced = computed(() => authState.user?.must_change_password === true);
const username = computed(() => authState.user?.email ?? '');

async function onSubmit(): Promise<void> {
    errorMessage.value = null;
    fieldErrors.value = {};

    if (newPassword.value !== confirmPassword.value) {
        fieldErrors.value.new_password = t('auth.change_password.mismatch');
        return;
    }

    submitting.value = true;
    try {
        await apiPost('/auth/change-password', {
            current_password: currentPassword.value,
            new_password: newPassword.value,
            new_password_confirmation: confirmPassword.value,
        });
        clearMustChangePassword();
        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/';
        await router.replace(redirect);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            for (const [key, messages] of Object.entries(err.payload.errors)) {
                if (Array.isArray(messages) && messages[0]) {
                    fieldErrors.value[key] = messages[0];
                }
            }
            errorMessage.value = err.firstValidationMessage();
        } else {
            errorMessage.value = t('auth.change_password.error_generic');
        }
    } finally {
        submitting.value = false;
    }
}

function cancel(): void {
    if (window.history.length > 1) {
        router.back();
    } else {
        void router.replace('/');
    }
}
</script>

<template>
    <div class="relative grid min-h-screen place-items-center overflow-hidden bg-slate-50 px-4 py-12">
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-br from-teal-50 via-white to-indigo-50" />

        <div class="relative z-10 w-full max-w-md auth-card-in">
            <div class="rounded-3xl border border-white/40 bg-white/85 p-8 shadow-2xl shadow-slate-300/40 backdrop-blur-xl sm:p-10">
                <div class="flex items-center gap-3">
                    <span class="grid size-11 place-items-center rounded-2xl bg-gradient-to-br from-teal-500 to-indigo-600 text-white shadow-lg shadow-teal-500/30">
                        <ShieldCheck class="size-5" />
                    </span>
                    <span class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-600">
                        {{ t('app.name') }}
                    </span>
                </div>

                <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-950">
                    {{ isForced ? t('auth.change_password.title_forced') : t('auth.change_password.title') }}
                </h1>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    {{ isForced ? t('auth.change_password.subtitle_forced') : t('auth.change_password.subtitle') }}
                </p>

                <div
                    v-if="errorMessage"
                    class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
                >
                    {{ errorMessage }}
                </div>

                <form class="mt-8 space-y-5" @submit.prevent="onSubmit">
                    <!-- Hidden username so the password manager associates the
                         updated credential with this account. -->
                    <input
                        type="text"
                        name="username"
                        autocomplete="username"
                        :value="username"
                        class="sr-only"
                        tabindex="-1"
                        aria-hidden="true"
                        readonly
                    >

                    <!-- Current password -->
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-800">{{ t('auth.change_password.current') }}</span>
                        <div class="group relative mt-2">
                            <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                <Lock class="size-4" />
                            </span>
                            <input
                                v-model="currentPassword"
                                type="password"
                                autocomplete="current-password"
                                required
                                class="w-full rounded-xl border border-slate-200 bg-white ps-11 pe-4 py-3 text-sm font-medium text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                        </div>
                        <p v-if="fieldErrors.current_password" class="mt-1.5 text-xs font-semibold text-rose-600">
                            {{ fieldErrors.current_password }}
                        </p>
                    </label>

                    <!-- New password -->
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-800">{{ t('auth.change_password.new') }}</span>
                        <div class="group relative mt-2">
                            <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                <Lock class="size-4" />
                            </span>
                            <input
                                v-model="newPassword"
                                :type="showNew ? 'text' : 'password'"
                                autocomplete="new-password"
                                required
                                minlength="8"
                                class="w-full rounded-xl border border-slate-200 bg-white ps-11 pe-11 py-3 text-sm font-medium text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                            <button
                                type="button"
                                class="absolute inset-y-0 end-0 grid w-11 place-items-center text-slate-400 transition hover:text-slate-700"
                                @click="showNew = !showNew"
                            >
                                <component :is="showNew ? EyeOff : Eye" class="size-4" />
                            </button>
                        </div>
                        <p v-if="fieldErrors.new_password" class="mt-1.5 text-xs font-semibold text-rose-600">
                            {{ fieldErrors.new_password }}
                        </p>
                        <p v-else class="mt-1.5 text-xs text-slate-500">{{ t('auth.change_password.hint') }}</p>
                    </label>

                    <!-- Confirm -->
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-800">{{ t('auth.change_password.confirm') }}</span>
                        <div class="group relative mt-2">
                            <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                <Lock class="size-4" />
                            </span>
                            <input
                                v-model="confirmPassword"
                                :type="showNew ? 'text' : 'password'"
                                autocomplete="new-password"
                                required
                                minlength="8"
                                class="w-full rounded-xl border border-slate-200 bg-white ps-11 pe-4 py-3 text-sm font-medium text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                        </div>
                    </label>

                    <button
                        type="submit"
                        :disabled="submitting"
                        class="group inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 to-indigo-600 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl disabled:cursor-wait disabled:opacity-70 disabled:hover:translate-y-0"
                    >
                        <Loader2 v-if="submitting" class="size-4 animate-spin" />
                        <span>{{ submitting ? t('auth.change_password.submitting') : t('auth.change_password.submit') }}</span>
                        <ArrowRight v-if="!submitting" class="size-4 transition group-hover:translate-x-1 rtl:group-hover:-translate-x-1" />
                    </button>
                </form>

                <button
                    v-if="!isForced"
                    type="button"
                    class="mt-6 w-full text-center text-xs font-semibold text-slate-500 transition hover:text-slate-800"
                    @click="cancel"
                >
                    {{ t('auth.change_password.cancel') }}
                </button>
            </div>
        </div>
    </div>
</template>
