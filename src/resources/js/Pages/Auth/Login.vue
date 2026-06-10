<script setup lang="ts">
/**
 * Login page for the merchant portal.
 *
 * Visual goals:
 *   - Split-panel hero: animated gradient + decorative floating
 *     orbs on the left (hidden on mobile), glassmorphism form
 *     card on the right.
 *   - Staggered fade-in animations on mount so the page feels
 *     alive on first paint.
 *   - Focus + hover states that feel premium (gentle scale,
 *     shadow lift, ring expansion).
 *   - Fully RTL-aware via Tailwind logical properties (ms-/me-)
 *     and `dir="rtl"` toggling on <html>.
 *   - Respects prefers-reduced-motion (animations disabled
 *     globally via the CSS rule in app.css).
 *
 * Functional details:
 *   - Native form POST to /auth/login (server returns a redirect
 *     to / on success, redirect-back with errors on failure).
 *   - CSRF token pulled from the <meta name="csrf-token"> tag and
 *     submitted as the hidden `_token` field.
 *   - Server validation errors round-trip via Laravel's session
 *     flash; the SPA reads them from a `__INITIAL_ERRORS__` hint
 *     when present (Phase 5 polish — for v1 the form posts +
 *     reloads on errors so this lives on the server).
 *   - Loading state during submit is visual only (the browser
 *     handles the navigation, so the spinner buys ~200ms of
 *     perceived feedback before the redirect lands).
 */

import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowRight, Building2, Globe, Loader2, Lock, Mail, ShieldCheck, Sparkles, TrendingUp } from 'lucide-vue-next';
import { setLocale, type SupportedLocale } from '@/lib/i18n';

const { t, locale } = useI18n();

const email = ref('');
const password = ref('');
const remember = ref(false);
const submitting = ref(false);
const csrfToken = ref('');
const errorMessage = ref<string | null>(null);

onMounted(() => {
    csrfToken.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    // Surface any flashed Laravel validation error from the
    // previous redirect-back. We sniff window-injected hints
    // (placed by the blade view when present) or fall back to
    // the generic message — for v1 the server-side redirect
    // path doesn't yet inject these so this is forward-looking.
    const flashed = (window as Window & { __LOGIN_ERROR__?: string }).__LOGIN_ERROR__;
    if (flashed) {
        errorMessage.value = flashed;
    }
});

function onSubmit(): void {
    submitting.value = true;
    // The browser handles the navigation — no preventDefault.
    // The submitting flag is visual only.
}

function switchLocale(next: SupportedLocale): void {
    setLocale(next);
}

const isArabic = computed(() => locale.value === 'ar');

const features = [
    { icon: TrendingUp, key: 'sales' },
    { icon: ShieldCheck, key: 'compliance' },
    { icon: Building2, key: 'multi_branch' },
] as const;
</script>

<template>
    <div class="relative min-h-screen overflow-hidden bg-slate-50">
        <!-- =================== HERO PANEL (left) ====================
             Hidden on mobile; takes up the left 60% on lg+. The
             gradient pulses slowly while the orbs float in front
             of it. -->
        <div class="hidden lg:flex lg:fixed lg:inset-y-0 lg:start-0 lg:w-3/5 auth-animated-bg overflow-hidden">
            <!-- Decorative floating orbs -->
            <div class="auth-orb size-72 bg-teal-300 -top-10 start-20" />
            <div class="auth-orb size-96 bg-indigo-400 top-1/3 start-1/2" style="animation-delay: -4s;" />
            <div class="auth-orb size-64 bg-cyan-300 bottom-10 start-1/4" style="animation-delay: -8s;" />

            <!-- Hero copy + feature pills -->
            <div class="relative z-10 m-auto px-16 max-w-2xl text-white">
                <div class="auth-card-in">
                    <div class="flex items-center gap-3">
                        <span class="grid size-12 place-items-center rounded-2xl bg-white/15 backdrop-blur-md ring-1 ring-white/20">
                            <Sparkles class="size-6" />
                        </span>
                        <span class="text-sm font-semibold uppercase tracking-[0.24em] text-white/80">
                            {{ t('app.name') }}
                        </span>
                    </div>

                    <h1 class="mt-10 text-5xl font-bold leading-tight tracking-tight">
                        {{ t('auth.hero_title') }}
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-relaxed text-white/85">
                        {{ t('auth.hero_subtitle') }}
                    </p>

                    <!-- Feature pills — staggered entry -->
                    <ul class="mt-12 space-y-4">
                        <li
                            v-for="(f, i) in features"
                            :key="f.key"
                            class="auth-field-in flex items-center gap-3 text-sm font-medium text-white/90"
                            :style="{ animationDelay: `${300 + i * 100}ms` }"
                        >
                            <span class="grid size-9 place-items-center rounded-xl bg-white/15 backdrop-blur-md ring-1 ring-white/20">
                                <component :is="f.icon" class="size-4" />
                            </span>
                            <span>
                                <template v-if="f.key === 'sales'">
                                    {{ isArabic ? 'تابع مبيعاتك لحظة بلحظة' : 'Track your sales in real time' }}
                                </template>
                                <template v-else-if="f.key === 'compliance'">
                                    {{ isArabic ? 'متوافق مع متطلبات سلطنة عُمان' : 'Built to Oman compliance standards' }}
                                </template>
                                <template v-else>
                                    {{ isArabic ? 'إدارة فروع متعددة من شاشة واحدة' : 'Run every branch from one screen' }}
                                </template>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ================== FORM PANEL (right) =================== -->
        <div class="relative z-10 flex min-h-screen items-center justify-center px-4 py-12 lg:ms-[60%] lg:px-12">
            <!-- Language toggle pinned top-right -->
            <div class="absolute top-6 end-6 flex items-center gap-1 rounded-full border border-slate-200 bg-white/90 p-1 shadow-sm backdrop-blur">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition"
                    :class="!isArabic ? 'bg-slate-950 text-white shadow' : 'text-slate-600 hover:text-slate-900'"
                    @click="switchLocale('en')"
                >
                    <Globe class="size-3.5" />
                    {{ t('common.language_english') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition"
                    :class="isArabic ? 'bg-slate-950 text-white shadow' : 'text-slate-600 hover:text-slate-900'"
                    @click="switchLocale('ar')"
                >
                    {{ t('common.language_arabic') }}
                </button>
            </div>

            <div class="w-full max-w-md auth-card-in">
                <!-- Mobile-only branding row (hero is hidden on small screens) -->
                <div class="mb-8 lg:hidden">
                    <div class="flex items-center gap-3">
                        <span class="grid size-11 place-items-center rounded-2xl bg-gradient-to-br from-teal-500 to-indigo-600 text-white shadow-lg shadow-teal-500/30">
                            <Sparkles class="size-5" />
                        </span>
                        <span class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-600">
                            {{ t('app.name') }}
                        </span>
                    </div>
                </div>

                <div class="rounded-3xl border border-white/40 bg-white/85 p-8 shadow-2xl shadow-slate-300/40 backdrop-blur-xl sm:p-10">
                    <h2 class="text-3xl font-bold tracking-tight text-slate-950">
                        {{ t('auth.welcome_back') }}
                    </h2>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        {{ t('auth.sign_in_subtitle') }}
                    </p>

                    <!-- Error banner (server-flashed validation failures) -->
                    <div
                        v-if="errorMessage"
                        class="auth-field-in mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
                        style="animation-delay: 100ms;"
                    >
                        {{ errorMessage }}
                    </div>

                    <!-- Native POST form. Browser handles navigation
                         so there's no XHR + window.location race that
                         could lose the freshly-issued session cookie. -->
                    <form
                        method="POST"
                        action="/auth/login"
                        class="mt-8 space-y-5"
                        @submit="onSubmit"
                    >
                        <input type="hidden" name="_token" :value="csrfToken">

                        <!-- Email -->
                        <label
                            class="auth-field-in block"
                            style="animation-delay: 150ms;"
                        >
                            <span class="text-sm font-semibold text-slate-800">{{ t('auth.email') }}</span>
                            <div class="group relative mt-2">
                                <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                    <Mail class="size-4" />
                                </span>
                                <input
                                    v-model="email"
                                    type="email"
                                    name="email"
                                    autocomplete="username"
                                    required
                                    :placeholder="t('auth.email_placeholder')"
                                    class="w-full rounded-xl border border-slate-200 bg-white ps-11 pe-4 py-3 text-sm font-medium text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                >
                            </div>
                        </label>

                        <!-- Password -->
                        <label
                            class="auth-field-in block"
                            style="animation-delay: 200ms;"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-slate-800">{{ t('auth.password') }}</span>
                                <!-- Phase D7 — real self-service reset flow. -->
                                <RouterLink
                                    to="/forgot-password"
                                    class="text-xs font-semibold text-teal-700 transition hover:text-teal-900"
                                >
                                    {{ t('auth.forgot_password') }}
                                </RouterLink>
                            </div>
                            <div class="group relative mt-2">
                                <span class="pointer-events-none absolute inset-y-0 start-0 grid w-11 place-items-center text-slate-400 transition group-focus-within:text-teal-600">
                                    <Lock class="size-4" />
                                </span>
                                <input
                                    v-model="password"
                                    type="password"
                                    name="password"
                                    autocomplete="current-password"
                                    required
                                    :placeholder="t('auth.password_placeholder')"
                                    class="w-full rounded-xl border border-slate-200 bg-white ps-11 pe-4 py-3 text-sm font-medium text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                >
                            </div>
                        </label>

                        <!-- Remember me -->
                        <label
                            class="auth-field-in flex items-center gap-2 text-sm font-medium text-slate-700"
                            style="animation-delay: 250ms;"
                        >
                            <input
                                v-model="remember"
                                type="checkbox"
                                name="remember"
                                class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                            >
                            {{ t('auth.remember_me') }}
                        </label>

                        <!-- Submit -->
                        <button
                            type="submit"
                            :disabled="submitting"
                            class="auth-field-in group inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 to-indigo-600 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-teal-600/40 disabled:cursor-wait disabled:opacity-70 disabled:hover:-translate-y-0"
                            style="animation-delay: 300ms;"
                        >
                            <Loader2 v-if="submitting" class="size-4 animate-spin" />
                            <span>{{ submitting ? t('auth.signing_in') : t('auth.sign_in') }}</span>
                            <ArrowRight v-if="!submitting" class="size-4 transition group-hover:translate-x-1 rtl:group-hover:-translate-x-1" />
                        </button>
                    </form>

                    <!-- Footer hint about how accounts are provisioned -->
                    <p
                        class="auth-field-in mt-6 text-center text-xs leading-5 text-slate-500"
                        style="animation-delay: 350ms;"
                    >
                        {{ t('auth.forgot_password_hint') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
