<script setup lang="ts">
/**
 * Reports landing page — Phase 7b-6.
 *
 * One tile per blueprint report (§5.11.1 -- §5.11.10). Clicking a
 * tile routes to the per-report page that renders the actual data.
 *
 * Permission: ReportsView gates the page; the sidebar nav entry is
 * already gated too. Round-Up tile renders even though it's still
 * stubbed -- the tile description carries the "lands in Phase 9"
 * messaging so the merchant sees the roadmap.
 */

import {
    BarChart3,
    Users,
    Percent,
    Package2,
    ChefHat,
    UserCheck,
    Boxes,
    Trash2,
    Truck,
    HandHeart,
    type LucideIcon,
} from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';

const { t } = useI18n();

interface Tile {
    key: string;
    to: string;
    icon: LucideIcon;
}

// Order roughly follows blueprint §5.11 sequencing -- merchants
// scan top to bottom in this order in workshops.
const tiles: Tile[] = [
    { key: 'sales', to: '/reports/sales', icon: BarChart3 },
    { key: 'customers', to: '/reports/customers', icon: Users },
    { key: 'discounts', to: '/reports/discounts', icon: Percent },
    { key: 'product_performance', to: '/reports/product-performance', icon: Package2 },
    { key: 'recipe_cost', to: '/reports/recipe-cost', icon: ChefHat },
    { key: 'staff_activity', to: '/reports/staff-activity', icon: UserCheck },
    { key: 'inventory_consumption', to: '/reports/inventory-consumption', icon: Boxes },
    { key: 'loss_waste', to: '/reports/loss-waste', icon: Trash2 },
    { key: 'restock_purchasing', to: '/reports/restock-purchasing', icon: Truck },
    { key: 'round_up_donation', to: '/reports/round-up-donation', icon: HandHeart },
];
</script>

<template>
    <MerchantLayout>
        <div class="max-w-7xl">
            <header class="mb-6 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-teal-600">
                    {{ t('reports.section_label') }}
                </span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('reports.title') }}</h1>
                <p class="max-w-2xl text-sm text-slate-600">{{ t('reports.subtitle') }}</p>
            </header>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <RouterLink
                    v-for="tile in tiles"
                    :key="tile.key"
                    :to="tile.to"
                    class="group flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-teal-400 hover:shadow-md"
                >
                    <span class="grid size-11 place-items-center rounded-lg bg-gradient-to-br from-teal-500 to-cyan-500 text-white shadow-sm">
                        <component :is="tile.icon" class="size-5" />
                    </span>
                    <h2 class="text-base font-semibold text-slate-950 group-hover:text-teal-700">
                        {{ t(`reports.landing.tile.${tile.key}.title`) }}
                    </h2>
                    <p class="text-sm leading-5 text-slate-600">
                        {{ t(`reports.landing.tile.${tile.key}.desc`) }}
                    </p>
                </RouterLink>
            </div>
        </div>
    </MerchantLayout>
</template>
