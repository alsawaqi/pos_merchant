<script setup lang="ts">
/**
 * LAUNCH packaging add-on (fix order PK-B1, L4) — the Order packaging lists on
 * their own page, for a catalogue user without inventory access (a recipe
 * editor edits them: "Edit recipes" + catalogue view). Inventory users have
 * the same lists as a tab of the Inventory page.
 */
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission } from '@/lib/permissions';
import OrderPackagingTab from './OrderPackagingTab.vue';

const { t } = useI18n();
const { can } = usePermissions();
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-7xl space-y-4">
            <h1 class="text-2xl font-bold text-slate-950">{{ t('order_packaging.page_title') }}</h1>
            <OrderPackagingTab :can-link="can(MerchantPermission.InventoryManage)" />
        </div>
    </MerchantLayout>
</template>
