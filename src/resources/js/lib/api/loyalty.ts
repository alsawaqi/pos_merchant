/**
 * Loyalty API — rules + per-customer accounts + transactions,
 * plus the (unchanged) wallet store-credit path.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\LoyaltyController}.
 * Permission gates server-side: loyalty.view for reads,
 * loyalty.manage for every write.
 *
 * Money is STRING (decimal:3 OMR); points + stamps are integers.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export type LoyaltyRuleType = 'visit_based' | 'spend_based';
export type LoyaltyRuleStatus = 'active' | 'paused';
export type LoyaltyTransactionType = 'earn' | 'redeem' | 'adjust' | 'expire';
export type WalletEntryType = 'topup' | 'redemption_use' | 'adjustment' | 'refund_in';

// ---- Domain types -----------------------------------------------

export interface LoyaltyRule {
    id: number;
    uuid: string;
    name: string;
    type: LoyaltyRuleType;
    /** Per-type config + §5.8 restrictions. Free-form object. */
    config: Record<string, unknown>;
    validity_start: string | null;
    validity_end: string | null;
    status: LoyaltyRuleStatus;
    currently_active: boolean;
    accounts_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface LoyaltyTransaction {
    id: number;
    uuid: string;
    loyalty_account_id: number;
    type: LoyaltyTransactionType;
    points_delta: number;
    stamps_delta: number;
    balance_after_points: number;
    balance_after_stamps: number;
    reason: string | null;
    order_id: number | null;
    recorded_by?: string | null;
    occurred_at: string | null;
}

export interface LoyaltyAccount {
    id: number;
    uuid: string;
    stamp_count: number;
    point_balance: number;
    last_activity_at: string | null;
    rule?: { id: number; uuid: string; name: string; type: LoyaltyRuleType };
    recent_transactions?: LoyaltyTransaction[];
}

export interface WalletLedgerEntry {
    id: number;
    uuid: string;
    entry_type: WalletEntryType;
    /** SIGNED OMR string (decimal:3). Never parseFloat for money. */
    amount_delta: string;
    balance_after: string;
    reason: string | null;
    occurred_at: string | null;
    recorded_by?: { id: number; name: string } | null;
}

export interface CustomerLoyaltySummary {
    customer: {
        id: number;
        uuid: string;
        name: string;
        phone: string;
        wallet_balance: string;
    };
    accounts: LoyaltyAccount[];
    recent_transactions: LoyaltyTransaction[];
    recent_wallet: WalletLedgerEntry[];
}

export interface PaginatedTransactions {
    data: LoyaltyTransaction[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface PaginatedWallet {
    data: WalletLedgerEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

// ---- Payloads ---------------------------------------------------

export interface LoyaltyRulePayload {
    name: string;
    type: LoyaltyRuleType;
    config_json?: Record<string, unknown>;
    validity_start?: string | null;
    validity_end?: string | null;
    status?: LoyaltyRuleStatus;
}

export type UpdateLoyaltyRulePayload = Partial<Omit<LoyaltyRulePayload, 'type'>>;

export interface AdjustLoyaltyPayload {
    loyalty_rule_uuid: string;
    points_delta?: number;
    stamps_delta?: number;
    reason: string;
}

export interface TopUpWalletPayload {
    amount: string;
    reason?: string | null;
}

export interface AdjustWalletPayload {
    amount_delta: string;
    reason: string;
}

// ---- Rules ------------------------------------------------------

export function listLoyaltyRules(): Promise<{ data: LoyaltyRule[] }> {
    return apiGet<{ data: LoyaltyRule[] }>('/api/loyalty/rules');
}

export function createLoyaltyRule(payload: LoyaltyRulePayload): Promise<{ data: LoyaltyRule }> {
    return apiPost<{ data: LoyaltyRule }>('/api/loyalty/rules', payload as unknown as JsonValue);
}

export function updateLoyaltyRule(uuid: string, payload: UpdateLoyaltyRulePayload): Promise<{ data: LoyaltyRule }> {
    return apiPatch<{ data: LoyaltyRule }>(`/api/loyalty/rules/${uuid}`, payload as unknown as JsonValue);
}

export function pauseLoyaltyRule(uuid: string): Promise<{ data: LoyaltyRule }> {
    return apiPost<{ data: LoyaltyRule }>(`/api/loyalty/rules/${uuid}/pause`);
}

export function resumeLoyaltyRule(uuid: string): Promise<{ data: LoyaltyRule }> {
    return apiPost<{ data: LoyaltyRule }>(`/api/loyalty/rules/${uuid}/resume`);
}

export function deleteLoyaltyRule(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/loyalty/rules/${uuid}`);
}

// ---- Per-customer ----------------------------------------------

export function getCustomerLoyalty(customerUuid: string): Promise<{ data: CustomerLoyaltySummary }> {
    return apiGet<{ data: CustomerLoyaltySummary }>(`/api/customers/${customerUuid}/loyalty`);
}

export function adjustLoyalty(
    customerUuid: string,
    payload: AdjustLoyaltyPayload,
): Promise<{ data: { transaction: LoyaltyTransaction; account: LoyaltyAccount } }> {
    return apiPost(`/api/customers/${customerUuid}/loyalty/adjust`, payload as unknown as JsonValue);
}

export function getLoyaltyTransactions(customerUuid: string, page = 1, perPage = 50): Promise<PaginatedTransactions> {
    return apiGet<PaginatedTransactions>(`/api/customers/${customerUuid}/loyalty/transactions`, {
        query: { page, per_page: perPage },
    });
}

// ---- Wallet (unchanged) ----------------------------------------

export function topUpWallet(
    customerUuid: string,
    payload: TopUpWalletPayload,
): Promise<{ data: { entry: WalletLedgerEntry; wallet_balance: string } }> {
    return apiPost(`/api/customers/${customerUuid}/wallet/topup`, payload as unknown as JsonValue);
}

export function adjustWallet(
    customerUuid: string,
    payload: AdjustWalletPayload,
): Promise<{ data: { entry: WalletLedgerEntry; wallet_balance: string } }> {
    return apiPost(`/api/customers/${customerUuid}/wallet/adjust`, payload as unknown as JsonValue);
}

export function getWalletLedger(customerUuid: string, page = 1, perPage = 50): Promise<PaginatedWallet> {
    return apiGet<PaginatedWallet>(`/api/customers/${customerUuid}/wallet/ledger`, {
        query: { page, per_page: perPage },
    });
}
