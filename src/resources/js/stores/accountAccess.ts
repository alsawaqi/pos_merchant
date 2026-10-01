import { reactive } from 'vue';

/**
 * LAUNCH-P1 P1-13: the server refuses a merchant only when it is
 * suspended or closed (inactive), or no longer exists — each with its
 * own code — so the blocked screen can say which one it is. A merchant
 * in onboarding is never blocked.
 */
export type AccountBlock = 'company_suspended' | 'company_inactive' | 'company_unavailable';

const BLOCK_CODES: readonly AccountBlock[] = ['company_suspended', 'company_inactive', 'company_unavailable'];

export const accountAccess = reactive<{ suspended: boolean; reason: AccountBlock | null }>({
    suspended: false,
    reason: null,
});

export function observeAccountAccess(status: number, payload: unknown): void {
    if (status === 403 && payload !== null && typeof payload === 'object' && 'code' in payload
        && BLOCK_CODES.includes(payload.code as AccountBlock)) {
        accountAccess.suspended = true;
        accountAccess.reason = payload.code as AccountBlock;
    }
}
