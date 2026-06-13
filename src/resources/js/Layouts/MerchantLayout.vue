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
import { BadgeCheck, BadgePercent, Ban, Bike, Book, Boxes, Building2, ChefHat, ChevronDown, ClipboardList, Contact, FolderTree, Gauge, Gift, Globe, Hash, KeyRound, LayoutGrid, LineChart, LogOut, Mail, Menu, Percent, Receipt, ShieldAlert, ShoppingBag, Tags, Target, Users, X } from 'lucide-vue-next';
import { authState } from '@/stores/auth';
import { messagesState, refreshUnreadCount } from '@/stores/messages';
import { setLocale, type SupportedLocale } from '@/lib/i18n';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission, type MerchantPermissionValue } from '@/lib/permissions';

interface NavItem {
    key: string;
    to: string;
    icon: Component;
    /**
     * When null, the entry is always visible. When set, the entry
     * is gated by usePermissions().can(permission). Server-side
     * is still the real enforcement.
     */
    permission: MerchantPermissionValue | null;
}

const { t, locale } = useI18n();
const { can } = usePermissions();

const sidebarOpen = ref(false);
const csrfToken = ref('');

onMounted(() => {
    csrfToken.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    // P-G6 — the inbox unread badge (refreshed again by the Messages
    // page whenever reads change).
    void refreshUnreadCount();
});

const navigationCatalog: readonly NavItem[] = [
    { key: 'dashboard', to: '/', icon: Gauge, permission: null },
    { key: 'orders', to: '/orders', icon: ShoppingBag, permission: MerchantPermission.ReportsView },
    // P-G7 — provider-statement reconciliation for delivery orders.
    { key: 'deliveries', to: '/deliveries', icon: Bike, permission: MerchantPermission.DeliveriesManage },
    // P-G8 — branch sales targets config.
    { key: 'branch_targets', to: '/branch-targets', icon: Target, permission: MerchantPermission.TargetsManage },
    { key: 'branches', to: '/branches', icon: Building2, permission: MerchantPermission.BranchesView },
    { key: 'floor_plan', to: '/floor-plan', icon: LayoutGrid, permission: MerchantPermission.FloorPlanView },
    { key: 'catalogue', to: '/catalogue', icon: Book, permission: MerchantPermission.CatalogueView },
    { key: 'taxes', to: '/taxes', icon: Percent, permission: MerchantPermission.CatalogueView },
    { key: 'inventory', to: '/inventory', icon: Boxes, permission: MerchantPermission.InventoryView },
    // PD6 — Goods Received Notes (Saved Purchase Receipts).
    { key: 'purchase_receipts', to: '/inventory/receipts', icon: ClipboardList, permission: MerchantPermission.InventoryView },
    { key: 'production', to: '/production', icon: ChefHat, permission: MerchantPermission.ProductionView },
    // P-G6 — the inbox is for everyone; the page itself hides the
    // announcements tab without messages.send.
    { key: 'messages', to: '/messages', icon: Mail, permission: null },
    { key: 'customers', to: '/customers', icon: Contact, permission: MerchantPermission.CustomersView },
    { key: 'loyalty', to: '/loyalty', icon: Gift, permission: MerchantPermission.LoyaltyView },
    { key: 'discounts', to: '/discounts', icon: Tags, permission: MerchantPermission.DiscountsView },
    { key: 'offers', to: '/offers', icon: BadgePercent, permission: MerchantPermission.DiscountsView },
    { key: 'expenses', to: '/expenses', icon: Receipt, permission: MerchantPermission.ExpensesView },
    { key: 'expense_categories', to: '/expense-categories', icon: FolderTree, permission: MerchantPermission.ExpensesView },
    { key: 'reports', to: '/reports', icon: LineChart, permission: MerchantPermission.ReportsView },
    { key: 'audit_log', to: '/audit-log', icon: ShieldAlert, permission: MerchantPermission.AuditLogView },
    { key: 'portal_users', to: '/portal-users', icon: Users, permission: MerchantPermission.PortalUsersView },
    { key: 'pos_staff', to: '/pos-staff', icon: BadgeCheck, permission: MerchantPermission.PosStaffView },
    { key: 'roles', to: '/roles', icon: KeyRound, permission: MerchantPermission.RolesView },
    { key: 'order_cancellation', to: '/settings/order-cancellation', icon: Ban, permission: MerchantPermission.OrdersCancel },
    { key: 'order_numbering', to: '/settings/order-numbering', icon: Hash, permission: MerchantPermission.OrdersCancel },
];

const visibleNavigation = computed(() =>
    navigationCatalog.filter((item) => item.permission === null || can(item.permission)),
);

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
                    <!-- P-G6 — the inbox unread badge -->
                    <span
                        v-if="item.key === 'messages' && messagesState.unread > 0"
                        class="ms-auto inline-flex min-w-5 items-center justify-center rounded-full bg-teal-500 px-1.5 py-0.5 text-[10px] font-bold text-white"
                    >{{ messagesState.unread > 99 ? '99+' : messagesState.unread }}</span>
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

                        <!-- User chip → My Profile (Phase D7) -->
                        <RouterLink
                            to="/profile"
                            class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-2.5 py-2 shadow-sm transition hover:bg-slate-50"
                            :aria-label="t('nav.profile')"
                            :title="t('nav.profile')"
                        >
                            <span class="grid size-9 place-items-center rounded-lg bg-gradient-to-br from-slate-900 to-indigo-900 text-sm font-semibold text-white">
                                {{ userInitials || 'M' }}
                            </span>
                            <span class="hidden text-start sm:block">
                                <span class="block text-sm font-semibold text-slate-950">{{ authState.user?.name ?? '—' }}</span>
                                <span class="block text-xs font-medium text-slate-500">{{ authState.user?.email ?? '' }}</span>
                            </span>
                            <ChevronDown class="hidden size-4 text-slate-400 sm:block" />
                        </RouterLink>

                        <!-- Change password (self-service). -->
                        <RouterLink
                            to="/change-password"
                            class="grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:bg-teal-50 hover:text-teal-700"
                            :aria-label="t('auth.change_password.title')"
                            :title="t('auth.change_password.title')"
                        >
                            <KeyRound class="size-5" />
                        </RouterLink>

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
