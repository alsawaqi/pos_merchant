<script setup lang="ts">
/**
 * Roles & Permissions — the role builder.
 *
 * Phase 4.8. List all roles (system + custom), create new
 * roles, edit which permissions a role holds, delete custom
 * roles. The 5 system roles cannot be deleted or renamed but
 * their permission sets ARE editable.
 *
 * Permission gating:
 *   - Page visible when MerchantPermission.RolesView
 *   - "Create role" + Edit + Delete buttons visible only
 *     when MerchantPermission.RolesManage
 */

import { Lock, Pencil, Plus, ShieldCheck, Trash2, Users } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createRole,
    deleteRole,
    getPermissionCatalog,
    listRoles,
    updateRole,
    type CreateRolePayload,
    type PermissionGroup,
    type Role,
} from '@/lib/api/roles';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();

const isArabic = computed(() => locale.value === 'ar');

// ---- State ------------------------------------------------------
const roles = ref<Role[]>([]);
const catalog = ref<PermissionGroup[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

// ---- Editor modal -----------------------------------------------
const editorOpen = ref(false);
const editorBusy = ref(false);
const editorMode = ref<'create' | 'edit'>('create');
const editorTarget = ref<Role | null>(null);
const editorFieldErrors = ref<Record<string, string[]>>({});
const editorError = ref<string | null>(null);
const editorForm = reactive<{
    name: string;
    description: string;
    permissions: Set<string>;
}>({
    name: '',
    description: '',
    permissions: new Set(),
});

// ---- Delete confirm ---------------------------------------------
const deleteTarget = ref<Role | null>(null);
const deleting = ref(false);

// ---- Catalog lookup ---------------------------------------------
function groupLabel(group: PermissionGroup): string {
    return isArabic.value ? group.label_ar : group.label_en;
}

function permissionLabel(key: string): string {
    for (const group of catalog.value) {
        const match = group.permissions.find((p) => p.key === key);
        if (match) {
            return isArabic.value ? match.label_ar : match.label_en;
        }
    }
    return key;
}

// ---- Fetchers ---------------------------------------------------
async function fetchAll(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const [rolesRes, catalogRes] = await Promise.all([
            listRoles(),
            getPermissionCatalog(),
        ]);
        roles.value = rolesRes.data;
        catalog.value = catalogRes.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load roles';
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    void fetchAll();
});

// ---- Create / edit flow -----------------------------------------

function openCreate(): void {
    editorMode.value = 'create';
    editorTarget.value = null;
    editorForm.name = '';
    editorForm.description = '';
    editorForm.permissions = new Set();
    editorFieldErrors.value = {};
    editorError.value = null;
    editorOpen.value = true;
}

function openEdit(role: Role): void {
    editorMode.value = 'edit';
    editorTarget.value = role;
    editorForm.name = role.name;
    editorForm.description = role.description ?? '';
    editorForm.permissions = new Set(role.permissions);
    editorFieldErrors.value = {};
    editorError.value = null;
    editorOpen.value = true;
}

function togglePermission(key: string): void {
    if (editorForm.permissions.has(key)) {
        editorForm.permissions.delete(key);
    } else {
        editorForm.permissions.add(key);
    }
    // Vue reactivity nudge — Set mutations don't trigger reads of
    // the same Set reference. Reassign to force the dependent
    // computeds to re-evaluate.
    editorForm.permissions = new Set(editorForm.permissions);
}

function toggleGroup(group: PermissionGroup): void {
    const allInGroup = group.permissions.every((p) => editorForm.permissions.has(p.key));
    const next = new Set(editorForm.permissions);
    if (allInGroup) {
        // Currently fully checked — uncheck all.
        for (const p of group.permissions) next.delete(p.key);
    } else {
        for (const p of group.permissions) next.add(p.key);
    }
    editorForm.permissions = next;
}

function isGroupFullyChecked(group: PermissionGroup): boolean {
    return group.permissions.every((p) => editorForm.permissions.has(p.key));
}

function isGroupPartiallyChecked(group: PermissionGroup): boolean {
    const count = group.permissions.filter((p) => editorForm.permissions.has(p.key)).length;
    return count > 0 && count < group.permissions.length;
}

async function submitEditor(): Promise<void> {
    editorBusy.value = true;
    editorFieldErrors.value = {};
    editorError.value = null;
    try {
        const payload = {
            name: editorForm.name.trim(),
            description: editorForm.description.trim() || null,
            permissions: Array.from(editorForm.permissions),
        } as CreateRolePayload;

        if (editorMode.value === 'create') {
            await createRole(payload);
        } else if (editorTarget.value) {
            // Don't send `name` if the role is a system role —
            // the action layer would refuse anyway, but
            // skipping it client-side keeps the payload clean
            // when no rename was attempted.
            const updatePayload = editorTarget.value.is_system
                ? { description: payload.description, permissions: payload.permissions }
                : payload;
            await updateRole(editorTarget.value.id, updatePayload);
        }
        editorOpen.value = false;
        await fetchAll();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            editorFieldErrors.value = err.payload.errors;
            editorError.value = t('roles.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            editorError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            editorError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        editorBusy.value = false;
    }
}

// ---- Delete flow ------------------------------------------------

function openDelete(role: Role): void {
    deleteTarget.value = role;
}

async function confirmDelete(): Promise<void> {
    if (!deleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteRole(deleteTarget.value.id);
        deleteTarget.value = null;
        await fetchAll();
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            error.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            error.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        deleting.value = false;
    }
}

const canManage = computed(() => can(MerchantPermission.RolesManage));
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('roles.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('roles.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('roles.subtitle') }}
                    </p>
                </div>

                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-teal-600/40"
                    @click="openCreate"
                >
                    <Plus class="size-4" />
                    {{ t('roles.create.button') }}
                </button>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <!-- Roles list -->
            <section v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm font-medium text-slate-500 shadow-sm">
                {{ t('common.loading') }}
            </section>

            <section v-else class="space-y-4">
                <article
                    v-for="role in roles"
                    :key="role.id"
                    class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-teal-200"
                >
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="flex-1 space-y-2">
                            <div class="flex items-center gap-2">
                                <ShieldCheck class="size-4 text-teal-600" />
                                <h2 class="text-lg font-semibold text-slate-950">{{ role.name }}</h2>
                                <span
                                    v-if="role.is_system"
                                    class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-600"
                                    :title="t('roles.system_hint')"
                                >
                                    <Lock class="size-3" />
                                    {{ t('roles.system_badge') }}
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-indigo-700">
                                    <Users class="size-3" />
                                    {{ t('roles.user_count', { count: role.user_count }) }}
                                </span>
                            </div>
                            <p v-if="role.description" class="text-sm text-slate-600">{{ role.description }}</p>
                            <p v-else class="text-sm italic text-slate-400">{{ t('roles.no_description') }}</p>
                            <div class="flex flex-wrap gap-1.5 pt-1">
                                <span
                                    v-for="permKey in role.permissions"
                                    :key="permKey"
                                    class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700"
                                >
                                    {{ permissionLabel(permKey) }}
                                </span>
                                <span v-if="role.permissions.length === 0" class="text-xs italic text-slate-400">
                                    {{ t('roles.no_permissions') }}
                                </span>
                            </div>
                        </div>

                        <div v-if="canManage" class="flex flex-shrink-0 gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                @click="openEdit(role)"
                            >
                                <Pencil class="size-3.5" />
                                {{ t('roles.actions.edit') }}
                            </button>
                            <button
                                v-if="!role.is_system"
                                type="button"
                                :disabled="role.user_count > 0"
                                :title="role.user_count > 0 ? t('roles.delete_blocked_hint') : ''"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="openDelete(role)"
                            >
                                <Trash2 class="size-3.5" />
                                {{ t('roles.actions.delete') }}
                            </button>
                        </div>
                    </div>
                </article>

                <div v-if="roles.length === 0" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm font-medium text-slate-500 shadow-sm">
                    {{ t('roles.empty_state') }}
                </div>
            </section>
        </section>

        <!-- =============== EDITOR MODAL =============== -->
        <div v-if="editorOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ editorMode === 'create' ? t('roles.editor.create_title') : t('roles.editor.edit_title') }}
                    </h2>
                    <p v-if="editorTarget?.is_system" class="mt-1 text-xs text-amber-700">
                        <Lock class="me-1 inline size-3" />
                        {{ t('roles.editor.system_role_hint') }}
                    </p>
                </div>

                <form class="space-y-5 p-6" @submit.prevent="submitEditor">
                    <div v-if="editorError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ editorError }}
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('roles.fields.name') }} *</span>
                            <input
                                v-model="editorForm.name"
                                required
                                type="text"
                                :disabled="editorTarget?.is_system"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500"
                            >
                            <p v-if="editorFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ editorFieldErrors.name[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('roles.fields.description') }}</span>
                            <input v-model="editorForm.description" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                    </div>

                    <!-- Grouped permissions -->
                    <div class="space-y-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('roles.fields.permissions') }}</h3>
                        <div
                            v-for="group in catalog"
                            :key="group.key"
                            class="rounded-lg border border-slate-200 bg-slate-50/50 p-4"
                        >
                            <label class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-800">
                                <input
                                    type="checkbox"
                                    :checked="isGroupFullyChecked(group)"
                                    :indeterminate="isGroupPartiallyChecked(group)"
                                    class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                    @change="toggleGroup(group)"
                                >
                                {{ groupLabel(group) }}
                            </label>
                            <div class="grid gap-2 sm:grid-cols-2 ms-6">
                                <label
                                    v-for="perm in group.permissions"
                                    :key="perm.key"
                                    class="flex items-center gap-2 text-sm text-slate-700"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="editorForm.permissions.has(perm.key)"
                                        class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                        @change="togglePermission(perm.key)"
                                    >
                                    <span>{{ isArabic ? perm.label_ar : perm.label_en }}</span>
                                </label>
                            </div>
                        </div>
                        <p v-if="editorFieldErrors.permissions" class="text-xs text-rose-600">{{ editorFieldErrors.permissions[0] }}</p>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="editorOpen = false">
                            {{ t('common.cancel') }}
                        </button>
                        <button type="submit" :disabled="editorBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ editorBusy ? t('roles.editor.submitting') : t('roles.editor.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- =============== DELETE CONFIRM =============== -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('roles.delete_dialog.title') }}</h2>
                </div>
                <div class="px-6 py-5 text-sm text-slate-700">
                    <p>{{ t('roles.delete_dialog.body', { name: deleteTarget.name }) }}</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="deleteTarget = null">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDelete">
                        {{ deleting ? t('roles.delete_dialog.submitting') : t('roles.delete_dialog.confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>
