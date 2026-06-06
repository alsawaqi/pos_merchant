<script setup lang="ts">
/**
 * Portal Users — merchant manages their own team.
 *
 * Sprint Phase 4.5. List teammates, create new ones (server
 * generates a one-shot password), edit (name / phone / role /
 * branch scope), suspend / reactivate, reset password.
 *
 * Permissions:
 *   - Page reachable when MerchantPermission.PortalUsersView
 *     (every role except SuperAdmin currently has it via the
 *     role matrix; SuperAdmin always passes via the super-admin
 *     short-circuit in usePermissions).
 *   - "Create teammate" button shows only when PortalUsersInvite.
 *   - "Edit" only when PortalUsersUpdate.
 *   - "Suspend / Reactivate" only when PortalUsersRevoke.
 *
 * The branch_scope picker reads the merchant's own branches list
 * (server already filters); "All branches" toggle sends
 * branch_scope=null, otherwise the multi-select array.
 */

import { Copy, KeyRound, Pencil, Plus, RotateCw, ShieldCheck, ShieldOff, Users } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createPortalUser,
    listPortalUsers,
    reactivatePortalUser,
    resetPortalUserPassword,
    suspendPortalUser,
    updatePortalUser,
    type CreatePortalUserPayload,
    type PortalUser,
    type PortalUserStatus,
} from '@/lib/api/portalUsers';
import { listBranches, type Branch } from '@/lib/api/branches';
import { assignRolesToPortalUser, listRoles, type Role } from '@/lib/api/roles';
import { MerchantPermission, MerchantRole, type MerchantRoleValue } from '@/lib/permissions';
import { authState } from '@/stores/auth';

const { t, locale } = useI18n();
const { can } = usePermissions();

// ---- Table state -------------------------------------------------
const users = ref<PortalUser[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const search = ref('');

// Per-row spinner so a click on Suspend on row 7 only spins
// row 7, not the whole table.
const rowBusy = ref<Record<number, boolean>>({});

// ---- Branches (for scope picker) --------------------------------
const branches = ref<Branch[]>([]);

// ---- Available roles (for the assign-roles modal) ---------------
const availableRoles = ref<Role[]>([]);

// ---- Assign roles modal -----------------------------------------
const assignRolesOpen = ref(false);
const assignRolesBusy = ref(false);
const assignRolesError = ref<string | null>(null);
const assignRolesTarget = ref<PortalUser | null>(null);
const assignRolesSelection = ref<Set<string>>(new Set());

// ---- Create modal -----------------------------------------------
const createOpen = ref(false);
const creating = ref(false);
const createFieldErrors = ref<Record<string, string[]>>({});
const createError = ref<string | null>(null);
const createForm = reactive<CreatePortalUserPayload & { scope_all: boolean }>({
    name: '',
    email: '',
    phone: '',
    role: MerchantRole.Manager as MerchantRoleValue,
    branch_scope: null,
    scope_all: true,
});

// ---- One-shot password modal ------------------------------------
const passwordModalOpen = ref(false);
const passwordModalUser = ref<PortalUser | null>(null);
const passwordModalSecret = ref('');
const passwordCopied = ref(false);

// ---- Edit modal -------------------------------------------------
const editOpen = ref(false);
const editing = ref(false);
const editFieldErrors = ref<Record<string, string[]>>({});
const editError = ref<string | null>(null);
const editTarget = ref<PortalUser | null>(null);
const editForm = reactive<{
    name: string;
    phone: string;
    role: MerchantRoleValue;
    scope_all: boolean;
    branch_scope: number[];
}>({
    name: '',
    phone: '',
    role: MerchantRole.Manager as MerchantRoleValue,
    scope_all: true,
    branch_scope: [],
});

// ---- Catalogues -------------------------------------------------

const roleOptions: { value: MerchantRoleValue; key: string }[] = [
    { value: MerchantRole.SuperAdmin as MerchantRoleValue, key: 'super_admin' },
    { value: MerchantRole.Manager as MerchantRoleValue, key: 'manager' },
    { value: MerchantRole.InventoryManager as MerchantRoleValue, key: 'inventory_manager' },
    { value: MerchantRole.CashierSupervisor as MerchantRoleValue, key: 'cashier_supervisor' },
    { value: MerchantRole.Viewer as MerchantRoleValue, key: 'viewer' },
];

function roleLabel(role: MerchantRoleValue | null | undefined): string {
    if (!role) {
        return '—';
    }
    const opt = roleOptions.find((r) => r.value === role);
    return opt ? t(`portal_users.roles.${opt.key}`) : role;
}

function statusLabel(status: PortalUserStatus | null): string {
    if (!status) {
        return '—';
    }
    return t(`portal_users.statuses.${status}`);
}

function statusBadgeClass(status: PortalUserStatus | null): string {
    switch (status) {
        case 'active': return 'bg-emerald-100 text-emerald-700';
        case 'suspended': return 'bg-rose-100 text-rose-700';
        case 'inactive': return 'bg-slate-100 text-slate-700';
        default: return 'bg-slate-100 text-slate-700';
    }
}

function formatTimestamp(iso: string | null): string {
    if (!iso) {
        return t('portal_users.never');
    }
    try {
        const date = new Date(iso);
        return date.toLocaleString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

const currentUserId = computed(() => authState.user?.id);
function isSelf(row: PortalUser): boolean {
    return currentUserId.value !== undefined && Number(currentUserId.value) === row.id;
}

// ---- Filtered list (in-memory; small dataset) -------------------
const filteredUsers = computed<PortalUser[]>(() => {
    const term = search.value.trim().toLowerCase();
    if (term === '') {
        return users.value;
    }
    return users.value.filter((u) =>
        u.name.toLowerCase().includes(term) || u.email.toLowerCase().includes(term),
    );
});

// ---- Fetchers ---------------------------------------------------
async function fetchUsers(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await listPortalUsers();
        users.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load portal users';
    } finally {
        loading.value = false;
    }
}

async function fetchBranches(): Promise<void> {
    try {
        const response = await listBranches();
        branches.value = response.data;
    } catch {
        branches.value = [];
    }
}

async function fetchAvailableRoles(): Promise<void> {
    // Roles list is only relevant to actors who can manage them.
    // Skip the round-trip for everyone else — they get the old
    // single-role dropdown sourced from the MerchantRole enum.
    if (!can(MerchantPermission.RolesView) && !can(MerchantPermission.RolesManage)) {
        return;
    }
    try {
        const response = await listRoles();
        availableRoles.value = response.data;
    } catch {
        availableRoles.value = [];
    }
}

onMounted(() => {
    void fetchUsers();
    void fetchBranches();
    void fetchAvailableRoles();
});

// ---- Create flow ------------------------------------------------

function openCreate(): void {
    createForm.name = '';
    createForm.email = '';
    createForm.phone = '';
    createForm.role = MerchantRole.Manager as MerchantRoleValue;
    createForm.scope_all = true;
    createForm.branch_scope = null;
    createFieldErrors.value = {};
    createError.value = null;
    createOpen.value = true;
}

async function submitCreate(): Promise<void> {
    creating.value = true;
    createFieldErrors.value = {};
    createError.value = null;
    try {
        const response = await createPortalUser({
            name: createForm.name,
            email: createForm.email,
            phone: createForm.phone || null,
            role: createForm.role,
            branch_scope: createForm.scope_all ? null : (createForm.branch_scope ?? []),
        });
        createOpen.value = false;
        passwordModalUser.value = response.data;
        passwordModalSecret.value = response.plaintext_password;
        passwordCopied.value = false;
        passwordModalOpen.value = true;
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            createFieldErrors.value = err.payload.errors;
            createError.value = t('portal_users.validation_summary');
        } else {
            createError.value = err instanceof Error ? err.message : 'Create failed';
        }
    } finally {
        creating.value = false;
    }
}

async function copyPassword(): Promise<void> {
    if (!passwordModalSecret.value) {
        return;
    }
    try {
        await navigator.clipboard.writeText(passwordModalSecret.value);
        passwordCopied.value = true;
        window.setTimeout(() => { passwordCopied.value = false; }, 2000);
    } catch {
        const el = document.getElementById('portal-user-password-out');
        if (el instanceof HTMLInputElement) {
            el.select();
        }
    }
}

function closePasswordModal(): void {
    passwordModalOpen.value = false;
    passwordModalUser.value = null;
    passwordModalSecret.value = '';
    void fetchUsers();
}

// ---- Edit flow --------------------------------------------------

function openEdit(row: PortalUser): void {
    editTarget.value = row;
    editForm.name = row.name;
    editForm.phone = row.phone ?? '';
    editForm.role = (row.role ?? MerchantRole.Manager) as MerchantRoleValue;
    editForm.scope_all = row.branch_scope === null;
    editForm.branch_scope = row.branch_scope ?? [];
    editFieldErrors.value = {};
    editError.value = null;
    editOpen.value = true;
}

async function submitEdit(): Promise<void> {
    if (!editTarget.value) {
        return;
    }
    editing.value = true;
    editFieldErrors.value = {};
    editError.value = null;
    try {
        await updatePortalUser(editTarget.value.id, {
            name: editForm.name,
            phone: editForm.phone || null,
            role: editForm.role,
            branch_scope: editForm.scope_all ? null : editForm.branch_scope,
        });
        editOpen.value = false;
        await fetchUsers();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            editFieldErrors.value = err.payload.errors;
            editError.value = t('portal_users.validation_summary');
        } else {
            editError.value = err instanceof Error ? err.message : 'Update failed';
        }
    } finally {
        editing.value = false;
    }
}

// ---- Suspend / Reactivate ---------------------------------------

async function toggleSuspension(row: PortalUser): Promise<void> {
    rowBusy.value[row.id] = true;
    try {
        if (row.status === 'suspended') {
            await reactivatePortalUser(row.id);
        } else {
            await suspendPortalUser(row.id);
        }
        await fetchUsers();
    } catch (err) {
        error.value = err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload
            ? String((err.payload as { message?: unknown }).message ?? 'Action failed')
            : err instanceof Error ? err.message : 'Action failed';
    } finally {
        rowBusy.value[row.id] = false;
    }
}

// ---- Reset password ---------------------------------------------

async function onResetPassword(row: PortalUser): Promise<void> {
    rowBusy.value[row.id] = true;
    try {
        const response = await resetPortalUserPassword(row.id);
        passwordModalUser.value = response.data;
        passwordModalSecret.value = response.plaintext_password;
        passwordCopied.value = false;
        passwordModalOpen.value = true;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Reset failed';
    } finally {
        rowBusy.value[row.id] = false;
    }
}

// ---- Assign roles flow ------------------------------------------

function openAssignRoles(row: PortalUser): void {
    assignRolesTarget.value = row;
    assignRolesSelection.value = new Set(row.roles ?? []);
    assignRolesError.value = null;
    assignRolesOpen.value = true;
}

function toggleRoleAssignment(roleName: string): void {
    const next = new Set(assignRolesSelection.value);
    if (next.has(roleName)) {
        next.delete(roleName);
    } else {
        next.add(roleName);
    }
    assignRolesSelection.value = next;
}

async function submitAssignRoles(): Promise<void> {
    if (!assignRolesTarget.value) return;
    assignRolesBusy.value = true;
    assignRolesError.value = null;
    try {
        await assignRolesToPortalUser(
            assignRolesTarget.value.id,
            Array.from(assignRolesSelection.value),
        );
        assignRolesOpen.value = false;
        await fetchUsers();
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            assignRolesError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            assignRolesError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        assignRolesBusy.value = false;
    }
}

// ---- Scope chip helpers ------------------------------------------

function scopeLabel(row: PortalUser): string {
    if (row.branch_scope === null) {
        return t('portal_users.scope.all');
    }
    return t('portal_users.scope.restricted', { count: row.branch_scope.length });
}

function toggleBranchInEdit(branchId: number): void {
    const idx = editForm.branch_scope.indexOf(branchId);
    if (idx >= 0) {
        editForm.branch_scope = editForm.branch_scope.filter((id) => id !== branchId);
    } else {
        editForm.branch_scope = [...editForm.branch_scope, branchId];
    }
}

function toggleBranchInCreate(branchId: number): void {
    const current = createForm.branch_scope ?? [];
    const idx = current.indexOf(branchId);
    if (idx >= 0) {
        createForm.branch_scope = current.filter((id) => id !== branchId);
    } else {
        createForm.branch_scope = [...current, branchId];
    }
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header strip -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('portal_users.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('portal_users.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('portal_users.subtitle') }}
                    </p>
                </div>

                <button
                    v-if="can(MerchantPermission.PortalUsersInvite)"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-teal-600/40"
                    @click="openCreate"
                >
                    <Plus class="size-4" />
                    {{ t('portal_users.create.button') }}
                </button>
            </div>

            <!-- Search filter -->
            <input
                v-model="search"
                type="search"
                :placeholder="t('portal_users.search_placeholder')"
                class="w-full max-w-md rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
            >

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <!-- Data table -->
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>

                <div v-else-if="filteredUsers.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Users class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('portal_users.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('portal_users.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('portal_users.table.role') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('portal_users.table.scope') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('portal_users.table.status') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('portal_users.table.last_login') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('portal_users.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in filteredUsers" :key="row.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ row.name }}</span>
                                    <span class="block text-xs text-slate-500">{{ row.email }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="roleName in (row.roles ?? [])"
                                            :key="roleName"
                                            class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700"
                                        >
                                            {{ roleName }}
                                        </span>
                                        <span v-if="(row.roles ?? []).length === 0" class="text-xs italic text-slate-400">
                                            {{ t('portal_users.no_roles') }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                        {{ scopeLabel(row) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass(row.status)">
                                        {{ statusLabel(row.status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-xs font-mono text-slate-500">{{ formatTimestamp(row.last_login_at) }}</td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            v-if="can(MerchantPermission.PortalUsersUpdate)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            @click="openEdit(row)"
                                        >
                                            <Pencil class="size-3.5" />
                                            {{ t('portal_users.actions.edit') }}
                                        </button>
                                        <button
                                            v-if="can(MerchantPermission.PortalUsersInvite)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                                            :disabled="rowBusy[row.id]"
                                            @click="onResetPassword(row)"
                                        >
                                            <RotateCw class="size-3.5" :class="{ 'animate-spin': rowBusy[row.id] }" />
                                            {{ t('portal_users.actions.reset_password') }}
                                        </button>
                                        <button
                                            v-if="can(MerchantPermission.RolesManage)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            @click="openAssignRoles(row)"
                                        >
                                            <KeyRound class="size-3.5" />
                                            {{ t('portal_users.actions.assign_roles') }}
                                        </button>
                                        <button
                                            v-if="can(MerchantPermission.PortalUsersRevoke) && !isSelf(row)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-60"
                                            :class="row.status === 'suspended'
                                                ? 'border-teal-200 text-teal-700 hover:bg-teal-50'
                                                : 'border-rose-200 text-rose-700 hover:bg-rose-50'"
                                            :disabled="rowBusy[row.id]"
                                            @click="toggleSuspension(row)"
                                        >
                                            <ShieldCheck v-if="row.status === 'suspended'" class="size-3.5" />
                                            <ShieldOff v-else class="size-3.5" />
                                            {{ row.status === 'suspended' ? t('portal_users.actions.reactivate') : t('portal_users.actions.suspend') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- ================= CREATE MODAL ================== -->
        <BaseModal v-if="createOpen" size="lg" :loading="creating" @close="createOpen = false">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('portal_users.create.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ t('portal_users.create.subtitle') }}</p>
            </template>

            <form id="portal-user-create-form" class="space-y-4" @submit.prevent="submitCreate">
                <div v-if="createError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ createError }}
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('portal_users.fields.name') }} *</span>
                    <input v-model="createForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="createFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ createFieldErrors.name[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('portal_users.fields.email') }} *</span>
                    <input v-model="createForm.email" required type="email" autocomplete="off" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="createFieldErrors.email" class="mt-1 text-xs text-rose-600">{{ createFieldErrors.email[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('portal_users.fields.phone') }}</span>
                    <input v-model="createForm.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('portal_users.fields.role') }} *</span>
                    <select v-model="createForm.role" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="opt in roleOptions" :key="opt.value" :value="opt.value">
                            {{ t(`portal_users.roles.${opt.key}`) }}
                        </option>
                    </select>
                </label>

                <div>
                    <span class="text-sm font-medium text-slate-700">{{ t('portal_users.fields.branch_scope') }}</span>
                    <label class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="createForm.scope_all" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        {{ t('portal_users.fields.scope_all') }}
                    </label>
                    <div v-if="!createForm.scope_all" class="mt-2 grid gap-2 max-h-48 overflow-y-auto rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
                        <label v-for="branch in branches" :key="branch.id" class="flex items-center gap-2 text-xs">
                            <input
                                type="checkbox"
                                :checked="(createForm.branch_scope ?? []).includes(branch.id)"
                                class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                @change="toggleBranchInCreate(branch.id)"
                            >
                            <span class="font-medium text-slate-800">{{ branch.name }}</span>
                        </label>
                        <p v-if="branches.length === 0" class="col-span-full text-xs text-slate-500">{{ t('portal_users.no_branches') }}</p>
                    </div>
                    <p v-if="createFieldErrors.branch_scope" class="mt-1 text-xs text-rose-600">{{ createFieldErrors.branch_scope[0] }}</p>
                </div>
            </form>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="createOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="portal-user-create-form" :disabled="creating" class="rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-md transition hover:-translate-y-0.5 hover:shadow-lg disabled:cursor-wait disabled:opacity-60">
                        {{ creating ? t('portal_users.create.submitting') : t('portal_users.create.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ============== ONE-SHOT PASSWORD MODAL ============== -->
        <BaseModal v-if="passwordModalOpen && passwordModalUser" size="lg" @close="closePasswordModal">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('portal_users.password_modal.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">
                    {{ t('portal_users.password_modal.subtitle', { name: passwordModalUser.name, email: passwordModalUser.email }) }}
                </p>
            </template>

            <div class="space-y-4">
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                    {{ t('portal_users.password_modal.one_shot_warning') }}
                </div>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('portal_users.password_modal.password_label') }}</span>
                    <div class="mt-2 flex gap-2">
                        <input
                            id="portal-user-password-out"
                            :value="passwordModalSecret"
                            readonly
                            class="flex-1 rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono tracking-wider text-slate-950 focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-semibold transition"
                            :class="passwordCopied ? 'border-teal-300 bg-teal-50 text-teal-700' : 'text-slate-700 hover:bg-slate-50'"
                            @click="copyPassword"
                        >
                            <Copy class="size-4" />
                            {{ passwordCopied ? t('portal_users.password_modal.copied') : t('portal_users.password_modal.copy') }}
                        </button>
                    </div>
                </label>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800" @click="closePasswordModal">
                        {{ t('portal_users.password_modal.done') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================= EDIT MODAL ================== -->
        <BaseModal v-if="editOpen && editTarget" size="lg" :loading="editing" @close="editOpen = false">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('portal_users.edit.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ editTarget.email }}</p>
            </template>

            <form id="portal-user-edit-form" class="space-y-4" @submit.prevent="submitEdit">
                <div v-if="editError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ editError }}
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('portal_users.fields.name') }}</span>
                    <input v-model="editForm.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="editFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.name[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('portal_users.fields.phone') }}</span>
                    <input v-model="editForm.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('portal_users.fields.role') }}</span>
                    <select v-model="editForm.role" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="opt in roleOptions" :key="opt.value" :value="opt.value">
                            {{ t(`portal_users.roles.${opt.key}`) }}
                        </option>
                    </select>
                </label>

                <div>
                    <span class="text-sm font-medium text-slate-700">{{ t('portal_users.fields.branch_scope') }}</span>
                    <label class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="editForm.scope_all" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        {{ t('portal_users.fields.scope_all') }}
                    </label>
                    <div v-if="!editForm.scope_all" class="mt-2 grid gap-2 max-h-48 overflow-y-auto rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
                        <label v-for="branch in branches" :key="branch.id" class="flex items-center gap-2 text-xs">
                            <input
                                type="checkbox"
                                :checked="editForm.branch_scope.includes(branch.id)"
                                class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                @change="toggleBranchInEdit(branch.id)"
                            >
                            <span class="font-medium text-slate-800">{{ branch.name }}</span>
                        </label>
                        <p v-if="branches.length === 0" class="col-span-full text-xs text-slate-500">{{ t('portal_users.no_branches') }}</p>
                    </div>
                    <p v-if="editFieldErrors.branch_scope" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.branch_scope[0] }}</p>
                </div>
            </form>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="editOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="portal-user-edit-form" :disabled="editing" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ editing ? t('portal_users.edit.submitting') : t('portal_users.edit.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ============== ASSIGN ROLES MODAL ============== -->
        <BaseModal v-if="assignRolesOpen && assignRolesTarget" size="md" :loading="assignRolesBusy" @close="assignRolesOpen = false">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('portal_users.assign_roles.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ assignRolesTarget.name }} ({{ assignRolesTarget.email }})</p>
            </template>

            <div class="space-y-3">
                <div v-if="assignRolesError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ assignRolesError }}
                </div>

                <p class="text-xs text-slate-500">{{ t('portal_users.assign_roles.hint') }}</p>

                <div v-if="availableRoles.length === 0" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-4 text-center text-sm text-slate-500">
                    {{ t('portal_users.assign_roles.no_roles') }}
                </div>

                <label
                    v-for="role in availableRoles"
                    :key="role.id"
                    class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 transition hover:bg-slate-50"
                >
                    <input
                        type="checkbox"
                        :checked="assignRolesSelection.has(role.name)"
                        class="mt-1 size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                        @change="toggleRoleAssignment(role.name)"
                    >
                    <span class="flex-1">
                        <span class="block text-sm font-semibold text-slate-950">{{ role.name }}</span>
                        <span v-if="role.description" class="block text-xs text-slate-500">{{ role.description }}</span>
                    </span>
                </label>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="assignRolesOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="button" :disabled="assignRolesBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60" @click="submitAssignRoles">
                        {{ assignRolesBusy ? t('portal_users.assign_roles.submitting') : t('portal_users.assign_roles.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>
