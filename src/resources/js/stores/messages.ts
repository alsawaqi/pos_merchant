/**
 * P-G6 — the inbox unread counter shared between MerchantLayout (the
 * sidebar badge) and the Messages page (which refreshes it after
 * marking reads). Same module-level reactive-store pattern as
 * stores/auth.ts. Failures are swallowed — a badge is never worth an
 * error banner.
 */

import { reactive } from 'vue';
import { fetchUnreadCount } from '@/lib/api/messages';

interface MessagesState {
    unread: number;
    loaded: boolean;
}

export const messagesState = reactive<MessagesState>({
    unread: 0,
    loaded: false,
});

export async function refreshUnreadCount(): Promise<void> {
    try {
        const res = await fetchUnreadCount();
        messagesState.unread = res.data.unread;
        messagesState.loaded = true;
    } catch {
        // Keep the last known value.
    }
}
