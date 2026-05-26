<script setup lang="ts">
/**
 * Sprint 4 landing page — a welcome card + a peek at what's
 * coming in Phase 4.5+. As the merchant portal gains features
 * (portal users, POS staff, branches, floors, catalogue, etc.),
 * this dashboard will fill in with KPI tiles per blueprint §5.2.
 */

import { useI18n } from 'vue-i18n';
import { Sparkles, CheckCircle2 } from 'lucide-vue-next';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { authState } from '@/stores/auth';

const { t, tm } = useI18n();

// tm() returns the array as-is so we can iterate it; t() would
// flatten it to a single string.
const comingSoon = tm('dashboard.coming_soon_list') as string[];
</script>

<template>
    <MerchantLayout>
        <section class="space-y-8">
            <!-- Welcome card -->
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-indigo-950 to-teal-900 px-8 py-10 text-white shadow-2xl shadow-slate-900/20 sm:px-12 sm:py-14">
                <!-- Background decoration -->
                <div class="pointer-events-none absolute -end-20 -top-20 size-72 rounded-full bg-teal-500/30 blur-3xl" />
                <div class="pointer-events-none absolute -bottom-24 -start-16 size-80 rounded-full bg-indigo-500/25 blur-3xl" />

                <div class="relative">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-teal-200 backdrop-blur">
                        <Sparkles class="size-3.5" />
                        {{ t('app.tagline') }}
                    </span>
                    <h1 class="mt-6 text-3xl font-bold tracking-tight sm:text-4xl">
                        {{ t('dashboard.title') }}{{ authState.user?.name ? `, ${authState.user.name}` : '' }}
                    </h1>
                    <p class="mt-3 max-w-2xl text-sm leading-relaxed text-white/80 sm:text-base">
                        {{ t('dashboard.subtitle') }}
                    </p>
                </div>
            </div>

            <!-- Roadmap card -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ t('dashboard.coming_soon_title') }}
                </h2>
                <ul class="mt-5 space-y-3">
                    <li
                        v-for="(item, i) in comingSoon"
                        :key="i"
                        class="flex items-start gap-3 text-sm leading-relaxed text-slate-700"
                    >
                        <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-teal-600" />
                        <span>{{ item }}</span>
                    </li>
                </ul>
            </div>
        </section>
    </MerchantLayout>
</template>
