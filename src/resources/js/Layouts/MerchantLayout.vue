<script setup lang="ts">
/**
 * Authenticated merchant shell — sidebar nav + header + main slot.
 *
 * Sprint 4 ships a slim version: sidebar has only Dashboard for
 * now. Phase 4.5+ adds Portal Users, POS Staff, Branches view,
 * Floors/Tables, Catalogue (categories, products, add-ons), etc.
 * Each new section adds one entry to navigationCatalog with its
 * gating permission.
 *
 * Logout is a native form POST (no XHR) so the browser handles
 * the boundary navigation — eliminates the "did the cookie clear
 * before I navigated?" race that an XHR + window.location flow
 * has.
 */

import { computed, onMounted, ref, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { ChevronDown, Gauge, Globe, LogOut, Menu, X } from 'lucide-vue-next';
import { authState } from '@/stores/auth';
import { setLocale, type SupportedLocale } from '@/lib/i18n';

interface NavItem {
    key: string;
    to: string;
    icon: Component;
}

const { t, locale } = useI18n();

const sidebarOpen = ref(false);
const csrfToken = ref('');

onMounted(() => {
    csrfToken.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
});

const navigationCatalog: readonly NavItem[] = [
    { key: 'dashboard', to: '/', icon: Gauge },
];

const visibleNavigation = computed(() => navigationCatalog);

const userInitials = computed(() => {
    const name = authState.user?.name ?? 'M';
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
});

const isArabic = computed(() => locale.value === 'ar');

function toggleLocale(): void {
    const next: SupportedLocale = isArabic.value ? 'en' : 'ar';
    setLocale(next);
}
</script>

<template>
    <div class="min-h-screen bg-slate-100 text-slate-950">
        <!-- Mobile sidebar backdrop -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-sm lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 start-0 z-50 flex w-72 flex-col border-e border-white/10 bg-gradient-to-b from-slate-950 via-slate-900 to-indigo-950 text-white transition-transform duration-300 lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full'"
        >
            <div class="flex h-20 items-center justify-between px-5">
                <RouterLink to="/" class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-teal-400 to-cyan-400 text-base font-black text-slate-950 shadow-lg shadow-teal-500/30">
                        M
                    </span>
                    <span>
                        <span class="block text-sm font-semibold uppercase tracking-[0.18em] text-teal-300">
                            MITHQAL
                        </span>
                        <span class="block text-lg font-semibold">{{ t('app.name') }}</span>
                    </span>
                </RouterLink>

                <button
                    type="button"
                    class="grid size-10 place-items-center rounded-lg text-slate-300 transition hover:bg-white/10 hover:text-white lg:hidden"
                    :aria-label="t('nav.sign_out')"
                    @click="sidebarOpen = false"
                >
                    <X class="size-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 px-3 py-4">
                <RouterLink
                    v-for="item in visibleNavigation"
                    :key="item.key"
                    :to="item.to"
                    class="group flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-semibold text-slate-300 transition duration-200 hover:bg-white/10 hover:text-white"
                    active-class="bg-white text-slate-950 shadow-lg shadow-black/20"
                    exact-active-class="bg-white text-slate-950 shadow-lg shadow-black/20"
                >
                    <component
                        :is="item.icon"
                        class="size-5 transition duration-200 group-hover:scale-105"
                        stroke-width="2"
                    />
                    {{ t(`nav.${item.key}`) }}
                </RouterLink>
            </nav>

            <div class="m-4 rounded-xl border border-teal-300/20 bg-teal-400/10 p-4">
                <p class="text-sm font-semibold text-teal-200">{{ t('app.tagline') }}</p>
                <p class="mt-2 text-xs leading-5 text-slate-300">
                    {{ t('dashboard.subtitle') }}
                </p>
            </div>
        </aside>

        <!-- Main column -->
        <div class="lg:ps-72">
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/85 backdrop-blur-xl">
                <div class="flex h-20 items-center gap-4 px-4 sm:px-6 lg:px-8">
                    <button
                        type="button"
                        class="grid size-11 place-items-center rounded-lg border border-slate-200 text-slate-700 shadow-sm transition hover:bg-slate-50 lg:hidden"
                        :aria-label="t('nav.dashboard')"
                        @click="sidebarOpen = true"
                    >
                        <Menu class="size-5" />
                    </button>

                    <div class="ms-auto flex items-center gap-3">
                        <!-- Language toggle -->
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                            @click="toggleLocale"
                        >
                            <Globe class="size-4" />
                            {{ isArabic ? t('common.language_english') : t('common.language_arabic') }}
                        </button>

                        <!-- User chip -->
                        <button
                            type="button"
                            class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-2.5 py-2 shadow-sm transition hover:bg-slate-50"
                        >
                            <span class="grid size-9 place-items-center rounded-lg bg-gradient-to-br from-slate-900 to-indigo-900 text-sm font-semibold text-white">
                                {{ userInitials || 'M' }}
                            </span>
                            <span class="hidden text-start sm:block">
                                <span class="block text-sm font-semibold text-slate-950">{{ authState.user?.name ?? '—' }}</span>
                                <span class="block text-xs font-medium text-slate-500">{{ authState.user?.email ?? '' }}</span>
                            </span>
                            <ChevronDown class="hidden size-4 text-slate-400 sm:block" />
                        </button>

                        <!-- Logout: native form POST. -->
                        <form method="POST" action="/auth/logout" class="inline-flex">
                            <input type="hidden" name="_token" :value="csrfToken">
                            <button
                                type="submit"
                                class="grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:bg-rose-50 hover:text-rose-700"
                                :aria-label="t('nav.sign_out')"
                            >
                                <LogOut class="size-5" />
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="animate-merchant-in px-4 py-6 sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>
    </div>
</template>

<style scoped>
.animate-merchant-in {
    animation: merchant-in 420ms ease-out both;
}

@keyframes merchant-in {
    from {
        opacity: 0;
        transform: translateY(12px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
