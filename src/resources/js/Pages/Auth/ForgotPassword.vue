<script setup lang="ts">
/**
 * Forgot password (Phase D7) — guest-only page reached from the
 * Login page's "Forgot your password?" link.
 *
 * Submits the email to POST /auth/forgot-password and then shows a
 * GENERIC success state regardless of whether the email matched an
 * account (the server is enumeration-safe and this page mirrors
 * that: no "no such account" branch exists).
 */
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowRight, Loader2, Mail, MailCheck, ShieldCheck } from 'lucide-vue-next';
import { ApiError, apiPost } from '@/lib/api';

const { t } = useI18n();

const email = ref('');
const submitting = ref(false);
const sent = ref(false);
const errorMessage = ref<string | null>(null);

async function onSubmit(): Promise<void> {
    errorMessage.value = null;
    submitting.value = true;

    try {
        await apiPost('/auth/forgot-password', { email: email.value });
        sent.value = true;
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            // Format errors or the throttle message — both arrive
            // keyed on `email`.
            errorMessage.value = err.firstValidationMessage();
        } else {
            errorMessage.value = t('auth.forgot.error_generic');
        }
    } finally {
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

                <!-- Generic success state — same screen whether or not
                     the email matched an account. -->
                <template v-if="sent">
                    <div class="mt-8 grid place-items-center">
                        <span class="grid size-14 place-items-center rounded-2xl bg-teal-50 text-teal-600">
                            <MailCheck class="size-7" />
                        </span>
                    </div>
                    <h1 class="mt-6 text-center text-2xl font-bold tracking-tight text-slate-950">
                        {{ t('auth.forgot.success_title') }}
                    </h1>
                    <p class="mt-3 text-center text-sm leading-relaxed text-slate-600">
                        {{ t('auth.forgot.success_body', { email }) }}
                    </p>
                    <RouterLink
                        to="/login"
                        class="mt-8 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 to-indigo-600 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                    >
                        {{ t('auth.forgot.back_to_login') }}
                    </RouterLink>
                </template>

                <template v-else>
                    <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-950">
                        {{ t('auth.forgot.title') }}
                    </h1>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        {{ t('auth.forgot.subtitle') }}
                    </p>

                    <div
                        v-if="errorMessage"
                        class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
                    >
                        {{ errorMessage }}
                    </div>

                    <form class="mt-8 space-y-5" @submit.prevent="onSubmit">
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">{{ t('auth.email') }}</span>
                            <div class="group relative mt-2">
                                <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                    <Mail class="size-4" />
                                </span>
                                <input
                                    v-model="email"
                                    type="email"
                                    autocomplete="username"
                                    required
                                    :placeholder="t('auth.email_placeholder')"
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
                            <span>{{ submitting ? t('auth.forgot.submitting') : t('auth.forgot.submit') }}</span>
                            <ArrowRight v-if="!submitting" class="size-4 transition group-hover:translate-x-1 rtl:group-hover:-translate-x-1" />
                        </button>
                    </form>

                    <RouterLink
                        to="/login"
                        class="mt-6 block w-full text-center text-xs font-semibold text-slate-500 transition hover:text-slate-800"
                    >
                        {{ t('auth.forgot.back_to_login') }}
                    </RouterLink>
                </template>
            </div>
        </div>
    </div>
</template>
