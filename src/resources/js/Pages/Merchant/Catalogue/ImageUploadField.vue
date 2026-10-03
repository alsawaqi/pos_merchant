<script setup lang="ts">
/**
 * LAUNCH-P4 B5 — the photo of a product, combo or category: upload a photo
 * (resized in the browser to 800 px, JPEG, about 300 KB, then stored on our
 * own host) or, as a fallback, paste a link. The value is the image URL.
 */
import { Image as ImageIcon, Trash2, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import { uploadCatalogueImage } from '@/lib/api/catalogue';
import { resizeToJpeg } from '@/lib/imageResize';

const props = defineProps<{
    kind: 'product' | 'category';
    disabled?: boolean;
    error?: string | null;
}>();
const url = defineModel<string>({ required: true });

const { t } = useI18n();
const busy = ref(false);
const uploadError = ref<string | null>(null);
const broken = ref(false);

async function onFile(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    uploadError.value = null;
    if (!file.type.startsWith('image/')) {
        uploadError.value = t('photo.not_an_image');
        return;
    }
    if (file.size > 15 * 1024 * 1024) {
        uploadError.value = t('photo.too_large');
        return;
    }
    busy.value = true;
    try {
        const small = await resizeToJpeg(file);
        const res = await uploadCatalogueImage(small, props.kind);
        url.value = res.data.url;
        broken.value = false;
    } catch (err) {
        uploadError.value = err instanceof ApiError && err.isValidationError()
            ? (err.firstValidationMessage() ?? t('photo.upload_failed'))
            : t('photo.upload_failed');
    } finally {
        busy.value = false;
    }
}

function remove(): void {
    url.value = '';
    uploadError.value = null;
}
</script>

<template>
    <div class="space-y-2" data-test="photo-field">
        <span class="text-sm font-medium text-slate-700">
            <ImageIcon class="me-1 inline size-3" />
            {{ t('photo.label') }}
        </span>
        <div class="flex flex-wrap items-center gap-3">
            <div class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                <img v-if="url && !broken" :src="url" alt="" class="size-full object-cover" data-test="photo-preview" @error="broken = true">
                <ImageIcon v-else class="size-6 text-slate-300" />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <label
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100"
                    :class="disabled || busy ? 'pointer-events-none opacity-60' : ''"
                >
                    <Upload class="size-3.5" />
                    {{ busy ? t('photo.uploading') : url ? t('photo.replace') : t('photo.upload') }}
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" :disabled="disabled || busy" data-test="photo-input" @change="onFile">
                </label>
                <button v-if="url" type="button" :disabled="disabled || busy" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 disabled:opacity-60" @click="remove">
                    <Trash2 class="size-3.5" /> {{ t('photo.remove') }}
                </button>
            </div>
        </div>
        <p class="text-[11px] text-slate-500">{{ t('photo.hint') }}</p>
        <label class="block">
            <span class="text-xs text-slate-500">{{ t('photo.link_fallback') }}</span>
            <input v-model="url" type="url" maxlength="500" :disabled="disabled || busy" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="photo-link" @input="broken = false">
        </label>
        <p v-if="uploadError" class="text-xs text-rose-600">{{ uploadError }}</p>
        <p v-if="error" class="text-xs text-rose-600">{{ error }}</p>
    </div>
</template>
