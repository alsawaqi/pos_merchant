<script setup lang="ts">
/**
 * Reset password (Phase D7) — guest-only page reached from the
 * emailed link: /reset-password?token=…&email=…
 *
 * Posts {email, token, password, password_confirmation} to
 * POST /auth/reset-password. A missing/invalid/expired token shows
 * a dead-link state with a path back to /forgot-password; success
 * shows a confirmation with a link to sign in.
 *
 * The visually-hidden username field + new-password autocomplete
 * hints let the browser's password manager store the credential
 * (same trick as ChangePassword.vue).
 */
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowRight, CheckCircle2, Eye, EyeOff, Loader2, Lock, ShieldCheck, TriangleAlert } from 'lucide-vue-next';
import { ApiError, apiPost } from '@/lib/api';

const { t } = useI18n();
const route = useRoute();

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''));
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''));
/** A link without both params is dead on arrival. */
const linkBroken = computed(() => token.value === '' || email.value === '');

const password = ref('');
const confirmPassword = ref('');
const showNew = ref(false);
const submitting = ref(false);
const done = ref(false);
const tokenRejected = ref(false);
const errorMessage = ref<string | null>(null);
const fieldErrors = ref<Record<string, string>>({});

async function onSubmit(): Promise<void> {
    errorMessage.value = null;
    fieldErrors.value = {};

    if (password.value !== confirmPassword.value) {
        fieldErrors.value.password = t('auth.reset.mismatch');
        return;
    }

    submitting.value = true;
    try {
        await apiPost('/auth/reset-password', {
            email: email.value,
            token: token.value,
            password: password.value,
            password_confirmation: confirmPassword.value,
        });
        done.value = true;
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            if (err.payload.errors.token) {
                // Invalid / expired / consumed token — flip to the
                // dead-link state so the user requests a fresh one.
                tokenRejected.value = true;
            } else {
                for (const [key, messages] of Object.entries(err.payload.errors)) {
                    if (Array.isArray(messages) && messages[0]) {
                        fieldErrors.value[key] = messages[0];
                    }
                }
                errorMessage.value = err.firstValidationMessage();
            }
        } else {
            errorMessage.value = t('auth.reset.error_generic');
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

                <!-- Success -->
                <template v-if="done">
                    <div class="mt-8 grid place-items-center">
                        <span class="grid size-14 place-items-center rounded-2xl bg-teal-50 text-teal-600">
                            <CheckCircle2 class="size-7" />
                        </span>
                    </div>
                    <h1 class="mt-6 text-center text-2xl font-bold tracking-tight text-slate-950">
                        {{ t('auth.reset.success_title') }}
                    </h1>
                    <p class="mt-3 text-center text-sm leading-relaxed text-slate-600">
                        {{ t('auth.reset.success_body') }}
                    </p>
                    <RouterLink
                        to="/login"
                        class="mt-8 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 to-indigo-600 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                    >
                        {{ t('auth.reset.go_to_login') }}
                    </RouterLink>
                </template>

                <!-- Dead link (missing params or server rejected the token) -->
                <template v-else-if="linkBroken || tokenRejected">
                    <div class="mt-8 grid place-items-center">
                        <span class="grid size-14 place-items-center rounded-2xl bg-amber-50 text-amber-600">
                            <TriangleAlert class="size-7" />
                        </span>
                    </div>
                    <h1 class="mt-6 text-center text-2xl font-bold tracking-tight text-slate-950">
                        {{ t('auth.reset.invalid_title') }}
                    </h1>
                    <p class="mt-3 text-center text-sm leading-relaxed text-slate-600">
                        {{ t('auth.reset.invalid_body') }}
                    </p>
                    <RouterLink
                        to="/forgot-password"
                        class="mt-8 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 to-indigo-600 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                    >
                        {{ t('auth.reset.request_new') }}
                    </RouterLink>
                    <RouterLink
                        to="/login"
                        class="mt-6 block w-full text-center text-xs font-semibold text-slate-500 transition hover:text-slate-800"
                    >
                        {{ t('auth.forgot.back_to_login') }}
                    </RouterLink>
                </template>

                <!-- New password form -->
                <template v-else>
                    <h1 class="mt-8 text-2xl font-bold tracking-tight text-slate-950">
                        {{ t('auth.reset.title') }}
                    </h1>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        {{ t('auth.reset.subtitle', { email }) }}
                    </p>

                    <div
                        v-if="errorMessage"
                        class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
                    >
                        {{ errorMessage }}
                    </div>

                    <form class="mt-8 space-y-5" @submit.prevent="onSubmit">
                        <!-- Hidden username so the password manager associates
                             the new credential with this account. -->
                        <input
                            type="text"
                            name="username"
                            autocomplete="username"
                            :value="email"
                            class="sr-only"
                            tabindex="-1"
                            aria-hidden="true"
                            readonly
                        >

                        <!-- New password -->
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">{{ t('auth.reset.new') }}</span>
                            <div class="group relative mt-2">
                                <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                    <Lock class="size-4" />
                                </span>
                                <input
                                    v-model="password"
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
                            <p v-if="fieldErrors.password" class="mt-1.5 text-xs font-semibold text-rose-600">
                                {{ fieldErrors.password }}
                            </p>
                            <p v-else class="mt-1.5 text-xs text-slate-500">{{ t('auth.reset.hint') }}</p>
                        </label>

                        <!-- Confirm -->
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">{{ t('auth.reset.confirm') }}</span>
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
                            <span>{{ submitting ? t('auth.reset.submitting') : t('auth.reset.submit') }}</span>
                            <ArrowRight v-if="!submitting" class="size-4 transition group-hover:translate-x-1 rtl:group-hover:-translate-x-1" />
                        </button>
                    </form>
                </template>
            </div>
        </div>
    </div>
</template>
