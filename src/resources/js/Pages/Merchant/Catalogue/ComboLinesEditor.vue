<script setup lang="ts">
/**
 * LAUNCH combo add-on — the lines of a combo or a meal (shared by the combo
 * editor and the meal page):
 *
 *   "Included items"  a product × quantity, always served; optional upgrades
 *                     (another product the customer may swap to, at an
 *                     upgrade price);
 *   "Choices"         a question ("Drinks"), a category, pick N; the whole
 *                     category is in, the merchant unticks items and may set
 *                     an extra price on any item; new products of the
 *                     category join automatically.
 */
import { ArrowUpCircle, ListChecks, Package, Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { choiceItems, lineIssues, type ChoiceLineDraft, type FixedLineDraft, type LineDraft, type PickableItem } from '@/lib/combo';

const props = defineProps<{
    modelValue: LineDraft[];
    items: PickableItem[];
    categories: { id: number; name: string; name_ar?: string | null }[];
    disabled?: boolean;
    /** The server's first error for a field path ("lines.2.items"), or null. */
    fieldError?: (key: string) => string | null;
    /** A product that must never be picked (the combo itself). */
    selfUuid?: string | null;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: LineDraft[]] }>();
const { t, locale } = useI18n();

let seq = 0;
const nextKey = (p: string): string => `${p}-${Date.now()}-${++seq}`;

const lines = computed(() => props.modelValue);
const fixedLines = computed(() => lines.value.filter((l): l is FixedLineDraft => l.kind === 'fixed'));
const choiceLines = computed(() => lines.value.filter((l): l is ChoiceLineDraft => l.kind === 'choice'));
const pickable = computed(() => props.items.filter((i) => i.uuid !== props.selfUuid));

/** The index of a line in the saved order: included items first, then choices. */
function savedIndex(line: LineDraft): number {
    return line.kind === 'fixed' ? fixedLines.value.indexOf(line) : fixedLines.value.length + choiceLines.value.indexOf(line);
}

function emitLines(next: LineDraft[]): void {
    emit('update:modelValue', next);
}

function addFixed(): void {
    emitLines([...lines.value, { key: nextKey('line'), id: null, kind: 'fixed', product_uuid: '', quantity: 1, upgrades: [] }]);
}

function addChoice(): void {
    emitLines([...lines.value, { key: nextKey('line'), id: null, kind: 'choice', name: '', name_ar: '', category_id: null, pick_count: 1, overrides: {} }]);
}

function removeLine(line: LineDraft): void {
    emitLines(lines.value.filter((l) => l !== line));
}

function itemName(item: PickableItem): string {
    return locale.value === 'ar' && item.name_ar ? item.name_ar : item.name;
}

function categoryName(id: number | null): string {
    const category = props.categories.find((c) => c.id === id);
    if (!category) return '—';
    return locale.value === 'ar' && category.name_ar ? category.name_ar : category.name;
}

function setOverride(line: ChoiceLineDraft, uuid: string, change: { excluded?: boolean; extra_price?: string }): void {
    const current = line.overrides[uuid] ?? { excluded: false, extra_price: '0' };
    line.overrides = { ...line.overrides, [uuid]: { ...current, ...change } };
}

function issues(line: LineDraft): string[] {
    return lineIssues(line, props.items).map((issue) => t(`combos.lines.issues.${issue}`));
}

function error(line: LineDraft, field: string): string | null {
    return props.fieldError ? props.fieldError(`lines.${savedIndex(line)}${field === '' ? '' : `.${field}`}`) : null;
}
</script>

<template>
    <div class="space-y-5">
        <!-- Included items -->
        <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-test="combo-included">
            <div>
                <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900"><Package class="size-4 text-indigo-600" /> {{ t('combos.lines.included_title') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500">{{ t('combos.lines.included_hint') }}</p>
            </div>
            <article v-for="line in fixedLines" :key="line.key" class="rounded-xl border border-slate-200 p-3" data-test="combo-fixed-line">
                <div class="grid gap-2 sm:grid-cols-[1fr_6rem_auto]">
                    <label class="block">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('combos.lines.item') }} *</span>
                        <select v-model="line.product_uuid" :disabled="disabled" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="fixed-item">
                            <option value="">{{ t('combos.lines.pick_item') }}</option>
                            <option v-for="item in pickable" :key="item.uuid" :value="item.uuid">{{ itemName(item) }}<template v-if="item.base_price"> — {{ item.base_price }}</template></option>
                        </select>
                        <span v-if="error(line, 'product_uuid')" class="mt-1 block text-xs text-rose-600">{{ error(line, 'product_uuid') }}</span>
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('combos.lines.quantity') }}</span>
                        <input v-model.number="line.quantity" type="number" min="1" max="99" step="1" :disabled="disabled" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="fixed-quantity">
                    </label>
                    <div class="flex items-end">
                        <button type="button" :disabled="disabled" class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1.5 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50" data-test="remove-line" @click="removeLine(line)">
                            <Trash2 class="size-3" /> {{ t('combos.lines.remove') }}
                        </button>
                    </div>
                </div>
                <!-- Upgrades -->
                <div class="mt-2 rounded-lg bg-slate-50 p-2" data-test="fixed-upgrades">
                    <p class="inline-flex items-center gap-1 text-xs font-semibold text-slate-700"><ArrowUpCircle class="size-3.5 text-teal-600" /> {{ t('combos.lines.upgrades_title') }}</p>
                    <p class="text-[11px] text-slate-500">{{ t('combos.lines.upgrades_hint') }}</p>
                    <div v-for="(upgrade, ui) in line.upgrades" :key="ui" class="mt-1.5 grid gap-2 sm:grid-cols-[1fr_8rem_auto]" data-test="upgrade-row">
                        <select v-model="upgrade.product_uuid" :disabled="disabled" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="upgrade-item">
                            <option value="">{{ t('combos.lines.pick_item') }}</option>
                            <option v-for="item in pickable" :key="item.uuid" :value="item.uuid">{{ itemName(item) }}<template v-if="item.base_price"> — {{ item.base_price }}</template></option>
                        </select>
                        <label class="flex items-center gap-1 text-xs text-slate-600">
                            +
                            <input v-model="upgrade.upgrade_price" type="number" step="0.001" min="0" :disabled="disabled" :aria-label="t('combos.lines.upgrade_price')" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-end text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="upgrade-price">
                        </label>
                        <button type="button" :disabled="disabled" class="rounded p-1 text-rose-500 hover:bg-rose-100" :title="t('combos.lines.remove_upgrade')" @click="line.upgrades.splice(ui, 1)">
                            <Trash2 class="size-3.5" />
                        </button>
                        <span v-if="error(line, `upgrades.${ui}.product_uuid`)" class="text-xs text-rose-600 sm:col-span-3">{{ error(line, `upgrades.${ui}.product_uuid`) }}</span>
                    </div>
                    <button type="button" :disabled="disabled" class="mt-1.5 inline-flex items-center gap-1 rounded border border-teal-200 bg-white px-2 py-1 text-[11px] font-semibold text-teal-700 hover:bg-teal-50" data-test="add-upgrade" @click="line.upgrades.push({ product_uuid: '', upgrade_price: '0' })">
                        <Plus class="size-3" /> {{ t('combos.lines.add_upgrade') }}
                    </button>
                </div>
                <ul v-if="issues(line).length > 0" class="mt-2 space-y-0.5 text-xs font-semibold text-rose-700" data-test="line-issues">
                    <li v-for="msg in issues(line)" :key="msg">{{ msg }}</li>
                </ul>
            </article>
            <button type="button" :disabled="disabled" class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100" data-test="add-fixed" @click="addFixed">
                <Plus class="size-3.5" /> {{ t('combos.lines.add_included') }}
            </button>
        </section>

        <!-- Choices -->
        <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-test="combo-choices">
            <div>
                <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900"><ListChecks class="size-4 text-indigo-600" /> {{ t('combos.lines.choices_title') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500">{{ t('combos.lines.choices_hint') }}</p>
            </div>
            <article v-for="line in choiceLines" :key="line.key" class="rounded-xl border border-slate-200 p-3" data-test="combo-choice-line">
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('combos.lines.question') }} *</span>
                        <input v-model="line.name" type="text" maxlength="64" :placeholder="t('combos.lines.question_placeholder')" :disabled="disabled" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="choice-name">
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('combos.lines.question_ar') }}</span>
                        <input v-model="line.name_ar" type="text" dir="rtl" maxlength="64" :disabled="disabled" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="choice-name-ar">
                    </label>
                </div>
                <div class="mt-2 grid gap-2 sm:grid-cols-[1fr_6rem_auto]">
                    <label class="block">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('combos.lines.category') }} *</span>
                        <select v-model="line.category_id" :disabled="disabled" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="choice-category">
                            <option :value="null">{{ t('combos.lines.pick_category') }}</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ categoryName(cat.id) }}</option>
                        </select>
                        <span v-if="error(line, 'category_id')" class="mt-1 block text-xs text-rose-600">{{ error(line, 'category_id') }}</span>
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('combos.lines.pick') }}</span>
                        <input v-model.number="line.pick_count" type="number" min="1" max="20" step="1" :disabled="disabled" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="choice-pick">
                    </label>
                    <div class="flex items-end">
                        <button type="button" :disabled="disabled" class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1.5 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50" data-test="remove-line" @click="removeLine(line)">
                            <Trash2 class="size-3" /> {{ t('combos.lines.remove') }}
                        </button>
                    </div>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ t('combos.lines.pick_hint', { n: line.pick_count || 1 }) }}</p>
                <table v-if="line.category_id !== null" class="mt-2 w-full text-sm" data-test="choice-items">
                    <thead class="text-[11px] uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="w-16 py-1 text-start font-semibold">{{ t('combos.lines.in_choice') }}</th>
                            <th class="py-1 text-start font-semibold">{{ t('combos.lines.item') }}</th>
                            <th class="w-36 py-1 text-end font-semibold">{{ t('combos.lines.extra_price') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in choiceItems(line, pickable)" :key="row.item.uuid" :class="row.excluded ? 'text-slate-400' : ''" data-test="choice-item">
                            <td class="py-1.5"><input type="checkbox" :checked="!row.excluded" :disabled="disabled" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200" data-test="choice-item-tick" @change="setOverride(line, row.item.uuid, { excluded: !($event.target as HTMLInputElement).checked })"></td>
                            <td class="py-1.5">{{ itemName(row.item) }}<span v-if="row.item.base_price" class="ms-1 text-xs text-slate-400">({{ row.item.base_price }})</span></td>
                            <td class="py-1.5"><input :value="line.overrides[row.item.uuid]?.extra_price ?? '0'" type="number" step="0.001" min="0" :disabled="disabled || row.excluded" class="w-full rounded-lg border border-slate-200 px-2 py-1 text-end text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="choice-item-extra" @input="setOverride(line, row.item.uuid, { extra_price: ($event.target as HTMLInputElement).value })"></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="line.category_id !== null" class="mt-1 text-[11px] text-slate-500">{{ t('combos.lines.joins_hint') }}</p>
                <p v-if="error(line, 'items')" class="mt-1 text-xs text-rose-600">{{ error(line, 'items') }}</p>
                <ul v-if="issues(line).length > 0" class="mt-2 space-y-0.5 text-xs font-semibold text-rose-700" data-test="line-issues">
                    <li v-for="msg in issues(line)" :key="msg">{{ msg }}</li>
                </ul>
            </article>
            <button type="button" :disabled="disabled" class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100" data-test="add-choice" @click="addChoice">
                <Plus class="size-3.5" /> {{ t('combos.lines.add_choice') }}
            </button>
        </section>
    </div>
</template>
