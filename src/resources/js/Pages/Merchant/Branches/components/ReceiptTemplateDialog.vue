<script setup lang="ts">
/**
 * ReceiptTemplateDialog — author this branch's custom POS receipt.
 *
 * The merchant fills in the header/footer the device prints: business
 * name (EN/AR), Commercial Registration (CR) number, VAT number,
 * address, phone, plus free header/footer lines and a QR toggle. A
 * live monospace preview mirrors the 32-char device receipt so the
 * merchant sees exactly what comes out of the printer.
 *
 * The whole template is PUT as one object (branches.update gated).
 */

import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ImagePlus, Plus, Trash2 } from 'lucide-vue-next';
import BaseModal from '@/Components/BaseModal.vue';
import { ApiError } from '@/lib/api';
import {
    updateBranchReceiptTemplate,
    type MerchantBranch,
    type ReceiptTemplate,
} from '@/lib/api/branches';

const props = defineProps<{ branch: MerchantBranch }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved', branch: MerchantBranch): void }>();

const { t } = useI18n();

// Seed the form from the branch's existing template, falling back to
// the branch's own contact details so a first-time template starts
// pre-filled with something sensible the merchant can tweak.
const existing = props.branch.receipt_template;
const form = reactive<ReceiptTemplate>({
    business_name: existing?.business_name ?? props.branch.name ?? '',
    business_name_ar: existing?.business_name_ar ?? props.branch.name_ar ?? '',
    cr_number: existing?.cr_number ?? '',
    vat_number: existing?.vat_number ?? '',
    address: existing?.address ?? props.branch.address ?? '',
    phone: existing?.phone ?? props.branch.phone ?? '',
    header_lines: [...(existing?.header_lines ?? [])],
    footer_lines: existing?.footer_lines ? [...existing.footer_lines] : ['Thank you for your visit'],
    show_qr: existing?.show_qr ?? true,
    logo_base64: existing?.logo_base64 ?? null,
});

const saving = ref(false);
const error = ref<string | null>(null);
const logoError = ref<string | null>(null);

// Browser-side logo processing — resize to the receipt printer width and
// greyscale, so the device gets a small, print-ready PNG. Keeps the server
// free of any image library (no GD / container rebuild needed).
const MAX_LOGO_WIDTH = 384;

const logoSrc = computed(() =>
    form.logo_base64 ? `data:image/png;base64,${form.logo_base64}` : null,
);

function readFileAsDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as string);
        reader.onerror = () => reject(new Error('read failed'));
        reader.readAsDataURL(file);
    });
}

function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('decode failed'));
        img.src = src;
    });
}

async function onLogoFile(e: Event): Promise<void> {
    logoError.value = null;
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = ''; // let the same file be re-picked after a remove
    if (!file) return;
    if (!file.type.startsWith('image/')) {
        logoError.value = t('branches.receipt.logo_invalid');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        logoError.value = t('branches.receipt.logo_too_large');
        return;
    }
    try {
        const img = await loadImage(await readFileAsDataUrl(file));
        const scale = Math.min(1, MAX_LOGO_WIDTH / img.width);
        const w = Math.max(1, Math.round(img.width * scale));
        const h = Math.max(1, Math.round(img.height * scale));
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            logoError.value = t('branches.receipt.logo_invalid');
            return;
        }
        ctx.fillStyle = '#ffffff'; // flatten transparency to white (thermal paper)
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(img, 0, 0, w, h);
        const data = ctx.getImageData(0, 0, w, h);
        const px = data.data;
        for (let i = 0; i < px.length; i += 4) {
            const g = Math.round(0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]);
            px[i] = px[i + 1] = px[i + 2] = g;
        }
        ctx.putImageData(data, 0, 0);
        form.logo_base64 = canvas.toDataURL('image/png').split(',')[1] ?? null;
    } catch {
        logoError.value = t('branches.receipt.logo_invalid');
    }
}

function removeLogo(): void {
    form.logo_base64 = null;
    logoError.value = null;
}

function addLine(which: 'header_lines' | 'footer_lines'): void {
    if (form[which].length < 6) form[which].push('');
}
function removeLine(which: 'header_lines' | 'footer_lines', i: number): void {
    form[which].splice(i, 1);
}

/** Clean payload — drop blank lines, trim strings, empty → null. */
function payload(): ReceiptTemplate {
    const s = (v: string | null): string | null => {
        const trimmed = (v ?? '').trim();
        return trimmed === '' ? null : trimmed;
    };
    const lines = (arr: string[]): string[] =>
        arr.map((l) => l.trim()).filter((l) => l !== '');
    return {
        business_name: s(form.business_name),
        business_name_ar: s(form.business_name_ar),
        cr_number: s(form.cr_number),
        vat_number: s(form.vat_number),
        address: s(form.address),
        phone: s(form.phone),
        header_lines: lines(form.header_lines),
        footer_lines: lines(form.footer_lines),
        show_qr: form.show_qr,
        logo_base64: form.logo_base64,
    };
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    try {
        const { data } = await updateBranchReceiptTemplate(props.branch.uuid, payload());
        emit('saved', data);
    } catch (err) {
        error.value = err instanceof ApiError ? err.message : t('branches.receipt.save_failed');
    } finally {
        saving.value = false;
    }
}

// ---- Live preview (mirrors SunmiReceiptService's 32-char layout) ----
const preview = computed(() => {
    const p = payload();
    const lines: { text: string; center?: boolean; bold?: boolean }[] = [];
    // The logo (rendered above) substitutes for the default name; only show the
    // "MITHQAL 2.0" fallback when there's neither a business name nor a logo.
    const name = p.business_name ?? (p.logo_base64 ? null : 'MITHQAL 2.0');
    if (name) lines.push({ text: name, center: true, bold: true });
    if (p.business_name_ar) lines.push({ text: p.business_name_ar, center: true, bold: true });
    for (const l of p.header_lines) lines.push({ text: l, center: true });
    if (p.address) lines.push({ text: p.address, center: true });
    if (p.phone) lines.push({ text: `Tel: ${p.phone}`, center: true });
    if (p.cr_number) lines.push({ text: `CR No.: ${p.cr_number}`, center: true });
    if (p.vat_number) lines.push({ text: `VAT No.: ${p.vat_number}`, center: true });
    lines.push({ text: '--------------------------------', center: true });
    lines.push({ text: 'Sample Item x1            1.500 OMR' });
    lines.push({ text: '--------------------------------', center: true });
    lines.push({ text: 'TOTAL                     1.500 OMR', bold: true });
    if (p.show_qr) lines.push({ text: '[ QR CODE ]', center: true });
    for (const l of p.footer_lines) lines.push({ text: l, center: true });
    return lines;
});
</script>

<template>
    <BaseModal :title="t('branches.receipt.title')" size="4xl" :loading="saving" @close="emit('close')">
        <div class="grid gap-6 lg:grid-cols-[1.3fr_0.7fr]">
            <!-- Form -->
            <div class="space-y-4">
                <p class="text-xs text-slate-500">{{ t('branches.receipt.intro') }}</p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-slate-600">{{ t('branches.receipt.business_name') }}</span>
                        <input v-model="form.business_name" type="text" maxlength="120" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-slate-600">{{ t('branches.receipt.business_name_ar') }}</span>
                        <input v-model="form.business_name_ar" type="text" maxlength="120" dir="rtl" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-slate-600">{{ t('branches.receipt.cr_number') }}</span>
                        <input v-model="form.cr_number" type="text" maxlength="60" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-slate-600">{{ t('branches.receipt.vat_number') }}</span>
                        <input v-model="form.vat_number" type="text" maxlength="60" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-slate-600">{{ t('branches.receipt.phone') }}</span>
                        <input v-model="form.phone" type="text" maxlength="40" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" />
                    </label>
                </div>

                <label class="block">
                    <span class="mb-1 block text-xs font-semibold text-slate-600">{{ t('branches.receipt.address') }}</span>
                    <textarea v-model="form.address" rows="2" maxlength="280" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" />
                </label>

                <!-- Logo -->
                <div>
                    <span class="mb-1 block text-xs font-semibold text-slate-600">{{ t('branches.receipt.logo') }}</span>
                    <div class="flex items-center gap-3">
                        <div class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                            <img v-if="logoSrc" :src="logoSrc" alt="logo" class="max-h-16 max-w-16 object-contain" />
                            <ImagePlus v-else class="size-5 text-slate-300" />
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                <ImagePlus class="size-3.5" />
                                {{ logoSrc ? t('branches.receipt.logo_replace') : t('branches.receipt.logo_upload') }}
                                <input type="file" accept="image/*" class="hidden" @change="onLogoFile" />
                            </label>
                            <button v-if="logoSrc" type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50" @click="removeLogo">{{ t('branches.receipt.logo_remove') }}</button>
                        </div>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-400">{{ t('branches.receipt.logo_hint') }}</p>
                    <p v-if="logoError" class="mt-1 text-xs text-rose-600">{{ logoError }}</p>
                </div>

                <!-- Header lines -->
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-600">{{ t('branches.receipt.header_lines') }}</span>
                        <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-teal-700 hover:text-teal-900 disabled:opacity-40" :disabled="form.header_lines.length >= 6" @click="addLine('header_lines')">
                            <Plus class="size-3.5" />{{ t('branches.receipt.add_line') }}
                        </button>
                    </div>
                    <div v-for="(_, i) in form.header_lines" :key="`h${i}`" class="mb-2 flex items-center gap-2">
                        <input v-model="form.header_lines[i]" type="text" maxlength="120" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" />
                        <button type="button" class="grid size-8 shrink-0 place-items-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="removeLine('header_lines', i)"><Trash2 class="size-4" /></button>
                    </div>
                </div>

                <!-- Footer lines -->
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-600">{{ t('branches.receipt.footer_lines') }}</span>
                        <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-teal-700 hover:text-teal-900 disabled:opacity-40" :disabled="form.footer_lines.length >= 6" @click="addLine('footer_lines')">
                            <Plus class="size-3.5" />{{ t('branches.receipt.add_line') }}
                        </button>
                    </div>
                    <div v-for="(_, i) in form.footer_lines" :key="`f${i}`" class="mb-2 flex items-center gap-2">
                        <input v-model="form.footer_lines[i]" type="text" maxlength="120" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" />
                        <button type="button" class="grid size-8 shrink-0 place-items-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="removeLine('footer_lines', i)"><Trash2 class="size-4" /></button>
                    </div>
                </div>

                <label class="flex items-center gap-2">
                    <input v-model="form.show_qr" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                    <span class="text-sm text-slate-700">{{ t('branches.receipt.show_qr') }}</span>
                </label>

                <p v-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>
            </div>

            <!-- Live preview -->
            <div>
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.receipt.preview') }}</span>
                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3">
                    <img v-if="logoSrc" :src="logoSrc" alt="logo" class="mx-auto mb-2 max-h-20 object-contain grayscale" />
                    <pre class="whitespace-pre-wrap break-words font-mono text-[11px] leading-snug text-slate-800">{{ preview.map((l) => l.text).join('\n') }}</pre>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-3">
                <button type="button" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100" :disabled="saving" @click="emit('close')">{{ t('common.cancel') }}</button>
                <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700 disabled:opacity-50" :disabled="saving" @click="save">
                    {{ saving ? t('common.saving') : t('common.save') }}
                </button>
            </div>
        </template>
    </BaseModal>
</template>
