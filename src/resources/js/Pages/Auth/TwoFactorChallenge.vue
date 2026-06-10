<script setup lang="ts">
/**
 * Two-factor challenge (Phase D8) — the code step between a correct
 * password and a real session for a TOTP-enrolled account.
 *
 * The login POST parks the pending state SERVER-SIDE (session) and
 * redirects here, so this page holds no secrets: it just collects a
 * 6-digit authenticator code (or a one-time recovery code) and
 * POSTs /auth/two-factor-challenge. Success completes the login
 * exactly like the normal flow — we hard-navigate to / so the SPA
 * re-bootstraps off the fresh session (and the router guard still
 * honors must_change_password).
 *
 * A visit with no pending challenge (deep link, expired state)
 * bounces back to /login.
 */
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowRight, KeyRound, Loader2, ShieldCheck, Smartphone, TriangleAlert } from 'lucide-vue-next';
import { ApiError, apiGet, apiPost } from '@/lib/api';

const { t } = useI18n();
const router = useRouter();

const checking = ref(true);
const useRecovery = ref(false);
const code = ref('');
const recoveryCode = ref('');
const submitting = ref(false);
const expired = ref(false);
const errorMessage = ref<string | null>(null);

onMounted(async () => {
    try {
        const status = await apiGet<{ pending: boolean }>('/auth/two-factor-challenge', {
            skipAuthInterceptor: true,
        });
        if (!status.pending) {
            await router.replace('/login');
            return;
        }
    } catch {
        await router.replace('/login');
        return;
    }
    checking.value = false;
});

function toggleMode(): void {
    useRecovery.value = !useRecovery.value;
    errorMessage.value = null;
}

async function onSubmit(): Promise<void> {
    errorMessage.value = null;
    submitting.value = true;

    try {
        await apiPost(
            '/auth/two-factor-challenge',
            useRecovery.value ? { recovery_code: recoveryCode.value } : { code: code.value },
        );
        // Full reload — the fresh session bootstraps __INITIAL_AUTH__
        // and the router guard handles must_change_password.
        window.location.assign('/');
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            if (err.payload.errors.challenge) {
                // Pending state gone/expired — restart from /login.
                expired.value = true;
            } else {
                errorMessage.value = err.firstValidationMessage() ?? t('auth.two_factor.invalid_code');
            }
        } else {
            errorMessage.value = t('auth.two_factor.error_generic');
        }
        submitting.value = false;
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

                <!-- Probing the pending state -->
                <template v-if="checking">
                    <div class="mt-10 grid place-items-center pb-4">
                        <Loader2 class="size-7 animate-spin text-teal-600" />
                    </div>
                </template>

                <!-- Challenge expired — restart from login -->
                <template v-else-if="expired">
                    <div class="mt-8 grid place-items-center">
                        <span class="grid size-14 place-items-center rounded-2xl bg-amber-50 text-amber-600">
                            <TriangleAlert class="size-7" />
                        </span>
                    </div>
                    <h1 class="mt-6 text-center text-2xl font-bold tracking-tight text-slate-950">
                        {{ t('auth.two_factor.expired_title') }}
                    </h1>
                    <p class="mt-3 text-center text-sm leading-relaxed text-slate-600">
                        {{ t('auth.two_factor.expired_body') }}
                    </p>
                    <RouterLink
                        to="/login"
                        class="mt-8 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 to-indigo-600 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                    >
                        {{ t('auth.two_factor.back_to_login') }}
                    </RouterLink>
                </template>

                <!-- Code form -->
                <template v-else>
                    <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-950">
                        {{ t('auth.two_factor.title') }}
                    </h1>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        {{ useRecovery ? t('auth.two_factor.subtitle_recovery') : t('auth.two_factor.subtitle') }}
                    </p>

                    <div
                        v-if="errorMessage"
                        class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
                    >
                        {{ errorMessage }}
                    </div>

                    <form class="mt-8 space-y-5" @submit.prevent="onSubmit">
                        <!-- TOTP code -->
                        <label v-if="!useRecovery" class="block">
                            <span class="text-sm font-semibold text-slate-800">{{ t('auth.two_factor.code') }}</span>
                            <div class="group relative mt-2">
                                <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                    <Smartphone class="size-4" />
                                </span>
                                <input
                                    v-model="code"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    pattern="[0-9]*"
                                    maxlength="6"
                                    required
                                    autofocus
                                    :placeholder="t('auth.two_factor.code_placeholder')"
                                    class="w-full rounded-xl border border-slate-200 bg-white ps-11 pe-4 py-3 text-center text-lg font-bold tracking-[0.4em] text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                >
                            </div>
                        </label>

                        <!-- Recovery code -->
                        <label v-else class="block">
                            <span class="text-sm font-semibold text-slate-800">{{ t('auth.two_factor.recovery_code') }}</span>
                            <div class="group relative mt-2">
                                <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                    <KeyRound class="size-4" />
                                </span>
                                <input
                                    v-model="recoveryCode"
                                    type="text"
                                    autocomplete="off"
                                    spellcheck="false"
                                    maxlength="12"
                                    required
                                    :placeholder="t('auth.two_factor.recovery_code_placeholder')"
                                    class="w-full rounded-xl border border-slate-200 bg-white ps-11 pe-4 py-3 text-center text-sm font-bold uppercase tracking-widest text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                >
                            </div>
                        </label>

                        <button
                            type="submit"
                            :disabled="submitting"
                            class="group inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 to-indigo-600 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl disabled:cursor-wait disabled:opacity-70 disabled:hover:translate-y-0"
                        >
                            <Loader2 v-if="submitting" class="size-4 animate-spin" />
                            <span>{{ submitting ? t('auth.two_factor.verifying') : t('auth.two_factor.verify') }}</span>
                            <ArrowRight v-if="!submitting" class="size-4 transition group-hover:translate-x-1 rtl:group-hover:-translate-x-1" />
                        </button>
                    </form>

                    <button
                        type="button"
                        class="mt-6 block w-full text-center text-xs font-semibold text-teal-700 transition hover:text-teal-900"
                        @click="toggleMode"
                    >
                        {{ useRecovery ? t('auth.two_factor.use_code_instead') : t('auth.two_factor.use_recovery_instead') }}
                    </button>

                    <RouterLink
                        to="/login"
                        class="mt-4 block w-full text-center text-xs font-semibold text-slate-500 transition hover:text-slate-800"
                    >
                        {{ t('auth.two_factor.back_to_login') }}
                    </RouterLink>
                </template>
            </div>
        </div>
    </div>
</template>
