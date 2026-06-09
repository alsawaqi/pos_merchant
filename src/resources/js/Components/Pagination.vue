<script setup lang="ts">
/**
 * Pagination — a tiny, presentational prev/next pager for the
 * server-paginated list pages (v2 #12).
 *
 * Renders the shared "Page {current} of {last} ({total} items)"
 * summary line + prev/next buttons. Prev is disabled on page 1,
 * next on the last page, and both while `loading`. The parent
 * owns the page state; this component only emits `update:page`
 * with the target page number on a prev/next click.
 *
 * Mirrors the inline pager that previously lived in
 * Orders/Index.vue + Customers/Index.vue, factored into one place.
 */
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
    defineProps<{
        meta: { current_page: number; last_page: number; per_page: number; total: number };
        loading?: boolean;
    }>(),
    { loading: false },
);

const emit = defineEmits<{ (e: 'update:page', page: number): void }>();

const { t } = useI18n();

const atFirst = computed(() => props.meta.current_page <= 1);
const atLast = computed(() => props.meta.current_page >= props.meta.last_page);

function prev(): void {
    if (atFirst.value || props.loading) return;
    emit('update:page', props.meta.current_page - 1);
}

function next(): void {
    if (atLast.value || props.loading) return;
    emit('update:page', props.meta.current_page + 1);
}
</script>

<template>
    <div class="flex items-center justify-between gap-3 text-xs text-slate-600">
        <div>
            {{ t('pagination.summary', { current: meta.current_page, last: meta.last_page, total: meta.total }) }}
        </div>
        <div class="flex items-center gap-2">
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold transition hover:bg-slate-50 disabled:opacity-50"
                :disabled="atFirst || loading"
                @click="prev"
            >
                <ChevronLeft class="size-3.5" />
                {{ t('pagination.prev') }}
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold transition hover:bg-slate-50 disabled:opacity-50"
                :disabled="atLast || loading"
                @click="next"
            >
                {{ t('pagination.next') }}
                <ChevronRight class="size-3.5" />
            </button>
        </div>
    </div>
</template>
