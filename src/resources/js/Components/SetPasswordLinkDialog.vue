<script setup lang="ts">
/**
 * "Copy set-password link" dialog — owner follow-up 2026-10-01.
 *
 * Shown right after the owner creates a teammate's login or resets a
 * teammate's password. No password is ever generated or shown: the
 * server returns a single-use link once. The owner copies it (e.g. to
 * send by WhatsApp); it was also emailed when mail is configured. The
 * link stays in memory only while this dialog is open. Mirrors
 * pos_admin's SetPasswordLinkDialog.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckCircle2, Copy, KeyRound, MailCheck, MailWarning } from 'lucide-vue-next';
import BaseModal from '@/Components/BaseModal.vue';
import type { SetPasswordLink } from '@/lib/api/portalUsers';

const props = defineProps<{
    link: SetPasswordLink;
    userName: string;
    userEmail: string;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const { t, locale } = useI18n();
const copied = ref(false);
const copyFailed = ref(false);

const expiresLabel = computed(() => new Date(props.link.expires_at).toLocaleString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
}));

const deliveryText = computed(() => {
    if (props.link.emailed) {
        return t('portal_users.link_dialog.emailed', { email: props.userEmail });
    }
    if (props.link.email_error) {
        return t('portal_users.link_dialog.email_failed');
    }
    return t('portal_users.link_dialog.not_emailed');
});

async function copyLink(): Promise<void> {
    copyFailed.value = false;
    try {
        await navigator.clipboard.writeText(props.link.url);
        copied.value = true;
        window.setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        copyFailed.value = true;
        const input = document.getElementById('team-set-password-link-url') as HTMLInputElement | null;
        input?.select();
    }
}
</script>

<template>
    <BaseModal
        :title="link.purpose === 'invite' ? t('portal_users.link_dialog.title_invite') : t('portal_users.link_dialog.title_reset')"
        size="lg"
        :close-on-backdrop="false"
        @close="emit('close')"
    >
        <template #icon>
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-teal-100 text-teal-700">
                <KeyRound class="size-5" />
            </span>
        </template>

        <div class="space-y-4" data-testid="set-password-link-dialog">
            <p class="text-sm text-slate-700">{{ t('portal_users.link_dialog.intro', { name: userName }) }}</p>

            <div
                class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm font-semibold"
                :class="link.emailed ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800'"
                role="status"
            >
                <MailCheck v-if="link.emailed" class="mt-0.5 size-4 shrink-0" />
                <MailWarning v-else class="mt-0.5 size-4 shrink-0" />
                <span>{{ deliveryText }}</span>
            </div>

            <div>
                <label for="team-set-password-link-url" class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {{ t('portal_users.link_dialog.link_label') }}
                </label>
                <div class="mt-1 flex gap-2">
                    <input
                        id="team-set-password-link-url"
                        :value="link.url"
                        readonly
                        dir="ltr"
                        class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-800"
                        @focus="($event.target as HTMLInputElement).select()"
                    >
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-slate-950 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                        data-testid="copy-set-password-link"
                        @click="copyLink"
                    >
                        <CheckCircle2 v-if="copied" class="size-4" />
                        <Copy v-else class="size-4" />
                        {{ copied ? t('portal_users.link_dialog.copied') : t('portal_users.link_dialog.copy') }}
                    </button>
                </div>
                <p v-if="copyFailed" class="mt-1 text-xs font-semibold text-rose-700">{{ t('portal_users.link_dialog.copy_failed') }}</p>
            </div>

            <p class="text-sm text-slate-700">
                <span class="text-slate-500">{{ t('portal_users.link_dialog.expires') }}</span>
                <span class="font-semibold"> {{ expiresLabel }} ({{ link.purpose === 'invite' ? t('portal_users.link_dialog.valid_invite') : t('portal_users.link_dialog.valid_reset') }})</span>
            </p>

            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                {{ t('portal_users.link_dialog.warning', { email: userEmail }) }}
            </p>
        </div>

        <template #footer>
            <div class="flex justify-end">
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    @click="emit('close')"
                >
                    {{ t('portal_users.link_dialog.done') }}
                </button>
            </div>
        </template>
    </BaseModal>
</template>
