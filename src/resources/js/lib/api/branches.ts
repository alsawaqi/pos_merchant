/**
 * Read-only branches listing for the actor's company. Used by
 * the Portal Users create modal's branch-scope multi-select.
 */

import { apiGet } from '@/lib/api';

export type BranchStatus = 'active' | 'inactive';

export interface Branch {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    code: string | null;
    status: BranchStatus | null;
}

export function listBranches(): Promise<{ data: Branch[] }> {
    return apiGet<{ data: Branch[] }>('/api/branches');
}
