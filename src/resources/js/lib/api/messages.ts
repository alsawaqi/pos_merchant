/**
 * Messaging API (P-G6) — mirror of
 * {@link \App\Http\Controllers\Pos\StaffMessagesController} (channel 1:
 * staff announcements to POS devices, messages.send-gated) and
 * {@link \App\Http\Controllers\Pos\PortalMessagesController} (channel 2:
 * the portal inbox, open to every signed-in user).
 */

import { apiDelete, apiGet, apiPost, type JsonValue } from '@/lib/api';

// ---- Channel 1 — staff announcements ----------------------------

export type StaffMessageTarget = 'staff' | 'branch' | 'company';

export interface StaffMessageRead {
    staff_name: string | null;
    read_at: string | null;
}

export interface StaffMessage {
    uuid: string;
    target_type: StaffMessageTarget;
    target_branch: { uuid: string; name: string } | null;
    target_staff: { uuid: string; name: string } | null;
    title: string | null;
    body: string;
    created_by_name: string | null;
    created_at: string | null;
    reads: StaffMessageRead[];
}

export interface StaffMessagePage {
    data: StaffMessage[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface SendStaffMessagePayload {
    target_type: StaffMessageTarget;
    target_branch_uuid?: string | null;
    target_staff_uuid?: string | null;
    title?: string | null;
    body: string;
}

export function listStaffMessages(page = 1): Promise<StaffMessagePage> {
    return apiGet<StaffMessagePage>(`/api/staff-messages?page=${page}`);
}

export function sendStaffMessage(payload: SendStaffMessagePayload): Promise<{ data: StaffMessage }> {
    return apiPost<{ data: StaffMessage }>('/api/staff-messages', payload as unknown as JsonValue);
}

export function retractStaffMessage(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/staff-messages/${uuid}`);
}

// ---- Channel 2 — portal inbox ------------------------------------

export type PortalMessageTarget = 'user' | 'role' | 'branch';

export interface PortalMessage {
    uuid: string;
    sender_name: string | null;
    target_type: PortalMessageTarget;
    target_user_name: string | null;
    target_role: string | null;
    target_branch: { uuid: string; name: string } | null;
    subject: string | null;
    body: string;
    created_at: string | null;
    /** Inbox rows only. */
    is_read?: boolean;
    /** Sent rows only. */
    read_count?: number;
}

export interface PortalMessagePage {
    data: PortalMessage[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface SendPortalMessagePayload {
    target_type: PortalMessageTarget;
    target_user_id?: number | null;
    target_role?: string | null;
    target_branch_uuid?: string | null;
    subject?: string | null;
    body: string;
}

export function listInbox(page = 1): Promise<PortalMessagePage> {
    return apiGet<PortalMessagePage>(`/api/messages/inbox?page=${page}`);
}

export function listSent(page = 1): Promise<PortalMessagePage> {
    return apiGet<PortalMessagePage>(`/api/messages/sent?page=${page}`);
}

export function fetchUnreadCount(): Promise<{ data: { unread: number } }> {
    return apiGet<{ data: { unread: number } }>('/api/messages/unread-count');
}

export function fetchRecipients(): Promise<{ data: { users: { id: number; name: string }[]; roles: string[] } }> {
    return apiGet<{ data: { users: { id: number; name: string }[]; roles: string[] } }>('/api/messages/recipients');
}

export function sendPortalMessage(payload: SendPortalMessagePayload): Promise<{ data: PortalMessage }> {
    return apiPost<{ data: PortalMessage }>('/api/messages', payload as unknown as JsonValue);
}

export function markMessageRead(uuid: string): Promise<{ data: { read: boolean } }> {
    return apiPost<{ data: { read: boolean } }>(`/api/messages/${uuid}/read`);
}
