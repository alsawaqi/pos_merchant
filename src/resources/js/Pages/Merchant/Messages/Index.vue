<script setup lang="ts">
/**
 * Messaging (P-G6) — two one-way channels in three tabs:
 *
 *   Inbox / Sent     the portal → portal channel, open to every signed-in
 *                    user. Opening an unread inbox row marks it read.
 *   Announcements    the portal → POS devices channel (messages.send-
 *                    gated): compose to a staff member / a branch / all
 *                    branches, see read receipts ("sent ≠ seen"), retract.
 *
 * Branch pickers come from the scope-filtered /api/branches, so F5
 * restricted users only ever see (and target) their own branches.
 */

import { Megaphone, Send } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import Pagination from '@/Components/Pagination.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import {
    fetchRecipients,
    listInbox,
    listSent,
    listStaffMessages,
    markMessageRead,
    retractStaffMessage,
    sendPortalMessage,
    sendStaffMessage,
    type PortalMessage,
    type StaffMessage,
} from '@/lib/api/messages';
import { listPosStaff, type PosStaff } from '@/lib/api/posStaff';
import { MerchantPermission } from '@/lib/permissions';
import { refreshUnreadCount } from '@/stores/messages';

const { t } = useI18n();
const { can } = usePermissions();

const canAnnounce = computed(() => can(MerchantPermission.MessagesSend));

type TabKey = 'inbox' | 'sent' | 'announcements';
const activeTab = ref<TabKey>('inbox');

const error = ref<string | null>(null);
const success = ref<string | null>(null);

// ---- Inbox / Sent ------------------------------------------------
type PageMeta = { current_page: number; last_page: number; per_page: number; total: number };
const emptyMeta: PageMeta = { current_page: 1, last_page: 1, per_page: 25, total: 0 };

const inboxRows = ref<PortalMessage[]>([]);
const sentRows = ref<PortalMessage[]>([]);
const inboxMeta = ref<PageMeta>(emptyMeta);
const sentMeta = ref<PageMeta>(emptyMeta);
const inboxLoading = ref(true);
const sentLoading = ref(false);
const expandedUuid = ref<string | null>(null);

// ---- Announcements ------------------------------------------------
const announcementRows = ref<StaffMessage[]>([]);
const announcementsMeta = ref<PageMeta>(emptyMeta);
const announcementsLoading = ref(false);
const receiptsUuid = ref<string | null>(null);

// ---- Pickers -------------------------------------------------------
const branches = ref<Branch[]>([]);
const staff = ref<PosStaff[]>([]);
const recipientUsers = ref<{ id: number; name: string }[]>([]);
const recipientRoles = ref<string[]>([]);

// ---- Compose modals -------------------------------------------------
const composeOpen = ref(false);
const composeBusy = ref(false);
const composeError = ref<string | null>(null);
const composeForm = reactive({
    target_type: 'user' as 'user' | 'role' | 'branch',
    target_user_id: '' as string | number,
    target_role: '',
    target_branch_uuid: '',
    subject: '',
    body: '',
});

const announceOpen = ref(false);
const announceBusy = ref(false);
const announceError = ref<string | null>(null);
const announceForm = reactive({
    target_type: 'branch' as 'staff' | 'branch' | 'company',
    target_branch_uuid: '',
    target_staff_uuid: '',
    title: '',
    body: '',
});

function pageMeta(page: PageMeta & { data: unknown }): PageMeta {
    return {
        current_page: page.current_page,
        last_page: page.last_page,
        per_page: page.per_page,
        total: page.total,
    };
}

async function fetchInbox(page = 1): Promise<void> {
    inboxLoading.value = true;
    try {
        const inbox = await listInbox(page);
        inboxRows.value = inbox.data;
        inboxMeta.value = pageMeta(inbox);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : t('messages.load_failed');
    } finally {
        inboxLoading.value = false;
    }
}

async function fetchSent(page = 1): Promise<void> {
    sentLoading.value = true;
    try {
        const sent = await listSent(page);
        sentRows.value = sent.data;
        sentMeta.value = pageMeta(sent);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : t('messages.load_failed');
    } finally {
        sentLoading.value = false;
    }
}

async function fetchAnnouncements(page = 1): Promise<void> {
    if (!canAnnounce.value) return;
    announcementsLoading.value = true;
    try {
        const announcements = await listStaffMessages(page);
        announcementRows.value = announcements.data;
        announcementsMeta.value = pageMeta(announcements);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : t('messages.load_failed');
    } finally {
        announcementsLoading.value = false;
    }
}

async function fetchPickers(): Promise<void> {
    try {
        const [b, r] = await Promise.all([listBranches(), fetchRecipients()]);
        branches.value = b.data;
        recipientUsers.value = r.data.users;
        recipientRoles.value = r.data.roles;
    } catch {
        // Pickers degrade — compose still works for typed targets.
    }
    if (canAnnounce.value) {
        try {
            staff.value = (await listPosStaff()).data;
        } catch {
            // The staff picker just stays empty.
        }
    }
}

onMounted(() => {
    void fetchInbox();
    void fetchSent();
    void fetchAnnouncements();
    void fetchPickers();
});

async function openMessage(row: PortalMessage): Promise<void> {
    expandedUuid.value = expandedUuid.value === row.uuid ? null : row.uuid;
    if (expandedUuid.value === row.uuid && row.is_read === false) {
        try {
            await markMessageRead(row.uuid);
            // Flip only AFTER the server accepted the receipt — an
            // optimistic flip would defeat the is_read===false retry
            // guard and strand the row unread server-side.
            row.is_read = true;
            await refreshUnreadCount();
        } catch {
            // Re-marks on the next open; the badge heals on refresh.
        }
    }
}

async function submitCompose(): Promise<void> {
    composeBusy.value = true;
    composeError.value = null;
    try {
        await sendPortalMessage({
            target_type: composeForm.target_type,
            target_user_id: composeForm.target_type === 'user' ? Number(composeForm.target_user_id) : null,
            target_role: composeForm.target_type === 'role' ? composeForm.target_role : null,
            target_branch_uuid: composeForm.target_type === 'branch' ? composeForm.target_branch_uuid : null,
            subject: composeForm.subject || null,
            body: composeForm.body,
        });
        composeOpen.value = false;
        composeForm.subject = '';
        composeForm.body = '';
        success.value = t('messages.sent_ok');
        await Promise.all([fetchInbox(), fetchSent()]);
        await refreshUnreadCount();
    } catch (e) {
        composeError.value = e instanceof ApiError ? e.message : t('messages.send_failed');
    } finally {
        composeBusy.value = false;
    }
}

async function submitAnnounce(): Promise<void> {
    announceBusy.value = true;
    announceError.value = null;
    try {
        await sendStaffMessage({
            target_type: announceForm.target_type,
            target_branch_uuid: announceForm.target_type === 'branch' ? announceForm.target_branch_uuid : null,
            target_staff_uuid: announceForm.target_type === 'staff' ? announceForm.target_staff_uuid : null,
            title: announceForm.title || null,
            body: announceForm.body,
        });
        announceOpen.value = false;
        announceForm.title = '';
        announceForm.body = '';
        success.value = t('messages.announced_ok');
        await fetchAnnouncements();
    } catch (e) {
        announceError.value = e instanceof ApiError ? e.message : t('messages.send_failed');
    } finally {
        announceBusy.value = false;
    }
}

async function retract(row: StaffMessage): Promise<void> {
    // Retraction purges the announcement from every device cache and has
    // no undo — same confirm convention as the portal's other deletes.
    if (!window.confirm(t('messages.retract_confirm'))) return;
    try {
        await retractStaffMessage(row.uuid);
        success.value = t('messages.retracted_ok');
        await fetchAnnouncements(announcementsMeta.value.current_page);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : t('messages.send_failed');
    }
}

function targetLabel(row: PortalMessage): string {
    if (row.target_type === 'user') return row.target_user_name ?? t('messages.target_user');
    if (row.target_type === 'role') return t('messages.target_role_label', { role: row.target_role ?? '' });
    return t('messages.target_branch_label', { branch: row.target_branch?.name ?? '' });
}

function announcementTarget(row: StaffMessage): string {
    if (row.target_type === 'staff') return row.target_staff?.name ?? t('messages.target_staff');
    if (row.target_type === 'branch') return row.target_branch?.name ?? t('messages.target_branch');
    return t('messages.target_all_branches');
}

function fmtDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <MerchantLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-slate-950">{{ t('messages.title') }}</h1>
                    <p class="text-sm text-slate-500">{{ t('messages.subtitle') }}</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700" @click="composeOpen = true">
                        <Send class="size-4" /> {{ t('messages.compose') }}
                    </button>
                    <button v-if="canAnnounce" type="button" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:opacity-90" @click="announceOpen = true">
                        <Megaphone class="size-4" /> {{ t('messages.announce') }}
                    </button>
                </div>
            </div>

            <p v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</p>
            <p v-if="success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ success }}</p>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="tab in ([['inbox', t('messages.tabs.inbox')], ['sent', t('messages.tabs.sent')], ...(canAnnounce ? [['announcements', t('messages.tabs.announcements')]] : [])] as [TabKey, string][])"
                    :key="tab[0]"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTab === tab[0] ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                    @click="activeTab = tab[0]"
                >{{ tab[1] }}</button>
            </div>

            <!-- Inbox -->
            <div v-if="activeTab === 'inbox'" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <p v-if="inboxLoading" class="px-5 py-6 text-sm text-slate-500">{{ t('messages.loading') }}</p>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="row in inboxRows" :key="row.uuid" class="cursor-pointer px-5 py-4 transition hover:bg-slate-50" @click="openMessage(row)">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm" :class="row.is_read ? 'font-medium text-slate-600' : 'font-bold text-slate-950'">
                                    {{ row.subject || t('messages.no_subject') }}
                                </p>
                                <p class="truncate text-xs text-slate-500">
                                    {{ row.sender_name ?? '—' }} · {{ targetLabel(row) }} · {{ fmtDate(row.created_at) }}
                                </p>
                            </div>
                            <span v-if="!row.is_read" class="inline-flex shrink-0 items-center rounded-full bg-teal-600 px-2 py-0.5 text-[10px] font-bold uppercase text-white">{{ t('messages.unread') }}</span>
                        </div>
                        <p v-if="expandedUuid === row.uuid" class="mt-3 whitespace-pre-wrap rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700">{{ row.body }}</p>
                    </li>
                    <li v-if="inboxRows.length === 0" class="px-5 py-8 text-center text-sm text-slate-400">{{ t('messages.empty_inbox') }}</li>
                </ul>
                <div v-if="inboxMeta.last_page > 1" class="border-t border-slate-100 px-5 py-3">
                    <Pagination :meta="inboxMeta" :loading="inboxLoading" @update:page="fetchInbox($event)" />
                </div>
            </div>

            <!-- Sent -->
            <div v-else-if="activeTab === 'sent'" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <ul class="divide-y divide-slate-100">
                    <li v-for="row in sentRows" :key="row.uuid" class="px-5 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ row.subject || t('messages.no_subject') }}</p>
                                <p class="truncate text-xs text-slate-500">{{ targetLabel(row) }} · {{ fmtDate(row.created_at) }}</p>
                            </div>
                            <span class="shrink-0 text-xs tabular-nums text-slate-500">{{ t('messages.read_by_n', { n: row.read_count ?? 0 }) }}</span>
                        </div>
                        <p class="mt-2 line-clamp-2 whitespace-pre-wrap text-sm text-slate-600">{{ row.body }}</p>
                    </li>
                    <li v-if="sentRows.length === 0" class="px-5 py-8 text-center text-sm text-slate-400">{{ t('messages.empty_sent') }}</li>
                </ul>
                <div v-if="sentMeta.last_page > 1" class="border-t border-slate-100 px-5 py-3">
                    <Pagination :meta="sentMeta" :loading="sentLoading" @update:page="fetchSent($event)" />
                </div>
            </div>

            <!-- Announcements -->
            <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <p v-if="announcementsLoading" class="px-5 py-6 text-sm text-slate-500">{{ t('messages.loading') }}</p>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="row in announcementRows" :key="row.uuid" class="px-5 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ row.title || t('messages.no_subject') }}</p>
                                <p class="truncate text-xs text-slate-500">
                                    {{ announcementTarget(row) }} · {{ row.created_by_name ?? '—' }} · {{ fmtDate(row.created_at) }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button type="button" class="rounded border border-teal-200 px-2 py-1 text-[11px] font-semibold text-teal-700 hover:bg-teal-50" @click="receiptsUuid = receiptsUuid === row.uuid ? null : row.uuid">
                                    {{ t('messages.read_by_n', { n: row.reads.length }) }}
                                </button>
                                <button type="button" class="rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50" @click="retract(row)">
                                    {{ t('messages.retract') }}
                                </button>
                            </div>
                        </div>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-slate-600">{{ row.body }}</p>
                        <ul v-if="receiptsUuid === row.uuid" class="mt-3 space-y-1 rounded-lg bg-slate-50 px-4 py-3">
                            <li v-for="(r, i) in row.reads" :key="i" class="text-xs text-slate-600">
                                {{ r.staff_name ?? '—' }} · {{ fmtDate(r.read_at) }}
                            </li>
                            <li v-if="row.reads.length === 0" class="text-xs text-slate-400">{{ t('messages.no_reads') }}</li>
                        </ul>
                    </li>
                    <li v-if="announcementRows.length === 0" class="px-5 py-8 text-center text-sm text-slate-400">{{ t('messages.empty_announcements') }}</li>
                </ul>
                <div v-if="announcementsMeta.last_page > 1" class="border-t border-slate-100 px-5 py-3">
                    <Pagination :meta="announcementsMeta" :loading="announcementsLoading" @update:page="fetchAnnouncements($event)" />
                </div>
            </div>
        </div>

        <!-- Compose (portal inbox) -->
        <BaseModal v-if="composeOpen" :title="t('messages.compose')" @close="composeOpen = false">
            <form class="space-y-4" @submit.prevent="submitCompose">
                <p v-if="composeError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ composeError }}</p>
                <div class="flex flex-wrap gap-3">
                    <select v-model="composeForm.target_type" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <option value="user">{{ t('messages.target_user') }}</option>
                        <option value="role">{{ t('messages.target_role') }}</option>
                        <option value="branch">{{ t('messages.target_branch') }}</option>
                    </select>
                    <select v-if="composeForm.target_type === 'user'" v-model="composeForm.target_user_id" class="min-w-44 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <option v-for="u in recipientUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-else-if="composeForm.target_type === 'role'" v-model="composeForm.target_role" class="min-w-44 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <option v-for="r in recipientRoles" :key="r" :value="r">{{ r }}</option>
                    </select>
                    <select v-else v-model="composeForm.target_branch_uuid" class="min-w-44 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                    </select>
                </div>
                <input v-model="composeForm.subject" type="text" :placeholder="t('messages.subject_optional')" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                <textarea v-model="composeForm.body" rows="5" required :placeholder="t('messages.body_placeholder')" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></textarea>
                <button type="submit" :disabled="composeBusy || !composeForm.body.trim()" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ t('messages.send') }}</button>
            </form>
        </BaseModal>

        <!-- Announce (to devices) -->
        <BaseModal v-if="announceOpen" :title="t('messages.announce')" @close="announceOpen = false">
            <form class="space-y-4" @submit.prevent="submitAnnounce">
                <p class="text-xs text-slate-500">{{ t('messages.announce_hint') }}</p>
                <p v-if="announceError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ announceError }}</p>
                <div class="flex flex-wrap gap-3">
                    <select v-model="announceForm.target_type" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <option value="branch">{{ t('messages.target_branch') }}</option>
                        <option value="staff">{{ t('messages.target_staff') }}</option>
                        <option value="company">{{ t('messages.target_all_branches') }}</option>
                    </select>
                    <select v-if="announceForm.target_type === 'branch'" v-model="announceForm.target_branch_uuid" class="min-w-44 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                    </select>
                    <select v-else-if="announceForm.target_type === 'staff'" v-model="announceForm.target_staff_uuid" class="min-w-44 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <option v-for="s in staff" :key="s.uuid" :value="s.uuid">{{ s.name }}</option>
                    </select>
                </div>
                <input v-model="announceForm.title" type="text" :placeholder="t('messages.title_optional')" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                <textarea v-model="announceForm.body" rows="4" required :placeholder="t('messages.body_placeholder')" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></textarea>
                <button type="submit" :disabled="announceBusy || !announceForm.body.trim()" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ t('messages.send') }}</button>
            </form>
        </BaseModal>
    </MerchantLayout>
</template>
