<script setup lang="ts">
/**
 * LAUNCH combo add-on (owner decision 4) — the meal setups list: each meal
 * offers "Make it a meal? +meal price" on the mains of its categories.
 */
import { ArrowLeft, Plus, UtensilsCrossed } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { listCategories, listMeals, type Category, type Meal } from '@/lib/api/catalogue';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const router = useRouter();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.CatalogueManage));

const meals = ref<Meal[]>([]);
const categories = ref<Category[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);

function mealName(meal: Meal): string {
    return locale.value === 'ar' && meal.name_ar ? meal.name_ar : meal.name;
}

function categoryNames(meal: Meal): string {
    return meal.category_ids
        .map((id) => categories.value.find((c) => c.id === id))
        .map((c) => (c ? (locale.value === 'ar' && c.name_ar ? c.name_ar : c.name) : '—'))
        .join(', ');
}

onMounted(async () => {
    try {
        const [m, c] = await Promise.all([listMeals(), listCategories()]);
        meals.value = m.data;
        categories.value = c.data;
    } catch {
        loadError.value = t('meals.load_failed');
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-5xl">
            <RouterLink :to="{ path: '/catalogue', query: { tab: 'products' } }" class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900">
                <ArrowLeft class="size-3.5" />
                {{ t('catalogue.wizard.back') }}
            </RouterLink>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-bold text-slate-950"><UtensilsCrossed class="size-6 text-amber-600" /> {{ t('meals.title') }}</h1>
                    <p class="mt-1 max-w-3xl text-sm text-slate-500">{{ t('meals.subtitle') }}</p>
                </div>
                <button v-if="canManage" type="button" class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700" data-test="add-meal" @click="router.push('/catalogue/meals/new')">
                    <Plus class="size-4" /> {{ t('meals.add') }}
                </button>
            </div>

            <div v-if="loading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">{{ t('common.loading') }}</div>
            <div v-else-if="loadError" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">{{ loadError }}</div>
            <div v-else-if="meals.length === 0" class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500" data-test="meals-empty">{{ t('meals.empty') }}</div>
            <ul v-else class="mt-6 divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-test="meals-list">
                <li v-for="meal in meals" :key="meal.uuid" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4" data-test="meal-row">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-900">
                            {{ mealName(meal) }}
                            <span class="ms-2 rounded bg-amber-50 px-1.5 py-0.5 text-xs font-semibold text-amber-800" dir="ltr">+{{ meal.meal_price }}</span>
                            <span v-if="meal.status !== 'active'" class="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-600">{{ t('meals.inactive') }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ t('meals.row_summary', { categories: categoryNames(meal), mains: meal.mains_count, lines: meal.lines.length }) }}</p>
                    </div>
                    <button type="button" class="rounded border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" data-test="meal-open" @click="router.push(`/catalogue/meals/${meal.uuid}/edit`)">{{ canManage ? t('meals.edit') : t('meals.view') }}</button>
                </li>
            </ul>
        </div>
    </MerchantLayout>
</template>
