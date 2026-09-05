import { apiGet, apiPut } from '@/lib/api';

export type DineInRoundMode = 'kitchen_direct' | 'staff_confirm';
export type BranchDineInRoundMode = 'inherit' | DineInRoundMode;

export interface DineInRoundModeBranch {
    uuid: string;
    name: string;
    name_ar: string | null;
    code: string;
    mode: DineInRoundMode | null;
    effective: DineInRoundMode;
}

export interface DineInRoundModeSetting {
    company_default: DineInRoundMode;
    company_default_editable: boolean;
    branches: DineInRoundModeBranch[];
}

/** Reads require branches.view; writes require branches.update plus scope. */
export function getDineInRoundModeSetting(): Promise<{ data: DineInRoundModeSetting }> {
    return apiGet<{ data: DineInRoundModeSetting }>('/api/settings/dine-in-round-mode');
}

export function updateDineInRoundModeDefault(mode: DineInRoundMode): Promise<{ data: DineInRoundModeSetting }> {
    return apiPut<{ data: DineInRoundModeSetting }>('/api/settings/dine-in-round-mode', { mode });
}

export function updateBranchDineInRoundMode(uuid: string, mode: BranchDineInRoundMode): Promise<{ data: DineInRoundModeSetting }> {
    return apiPut<{ data: DineInRoundModeSetting }>(`/api/settings/dine-in-round-mode/branches/${encodeURIComponent(uuid)}`, { mode });
}
