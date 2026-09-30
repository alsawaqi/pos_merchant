import { reactive } from 'vue';

export const accountAccess = reactive({ suspended: false });

export function observeAccountAccess(status: number, payload: unknown): void {
    if (status === 403 && payload !== null && typeof payload === 'object' &&
        'code' in payload && payload.code === 'company_suspended') {
        accountAccess.suspended = true;
    }
}
