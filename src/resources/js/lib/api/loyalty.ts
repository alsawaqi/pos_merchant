/**
 * Loyalty API — config + per-customer balances + ledger
 * adjustments + paginated history.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\LoyaltyController}.
 *
 * Permission gates server-side: loyalty.view for read endpoints,
 * loyalty.manage for every write.
 *
 * Money + signed-delta types are STRINGS for OMR (decimal:3
 * precision contract) and integers for points.
 */

import { apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export type PointEntryType = 'earn' | 'redeem' | 'adjustment' | 'refund_in' | 'expiry';
export type WalletEntryType = 'topup' | 'redemption_use' | 'adjustment' | 'refund_in';

// ---- Domain types -----------------------------------------------

export interface LoyaltyConfig {
    id: number;
    /** Whole points earned per 1 OMR spent. 0 = no auto-earn. */
    points_per_omr: number;
    /** How many baisas (1/1000 OMR) one point is worth on redemption. */
    baisas_per_point: number;
    /** When false, Phase 8+ POS sale pipeline skips writing earn entries. */
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export interface PointLedgerEntry {
    id: number;
    uuid: string;
    entry_type: PointEntryType;
    /** SIGNED — positive on inflow, negative on outflow. */
    points_delta: number;
    balance_after: number;
    reason: string | null;
    reference_type: string | null;
    reference_id: number | null;
    occurred_at: string | null;
    recorded_by?: { id: number; name: string } | null;
}

export interface WalletLedgerEntry {
    id: number;
    uuid: string;
    entry_type: WalletEntryType;
    /** SIGNED OMR string (decimal:3 precision). Never parseFloat. */
    amount_delta: string;
    balance_after: string;
    reason: string | null;
    reference_type: string | null;
    reference_id: number | null;
    occurred_at: string | null;
    recorded_by?: { id: number; name: string } | null;
}

export interface CustomerLoyaltySummary {
    customer: {
        id: number;
        uuid: string;
        name: string;
        phone: string;
        points_balance: number;
        wallet_balance: string;
    };
    config: LoyaltyConfig;
    recent_points: PointLedgerEntry[];
    recent_wallet: WalletLedgerEntry[];
}

export interface PaginatedPoints {
    data: PointLedgerEntry[];
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

export interface UpsertLoyaltyConfigPayload {
    points_per_omr?: number;
    baisas_per_point?: number;
    is_active?: boolean;
}

export interface AdjustPointsPayload {
    points_delta: number;
    reason: string;
}

export interface TopUpWalletPayload {
    /** Positive OMR amount as a string (decimal:3 precision). */
    amount: string;
    reason?: string | null;
}

export interface AdjustWalletPayload {
    /** SIGNED OMR string. */
    amount_delta: string;
    reason: string;
}

// ---- Endpoints --------------------------------------------------

export function getLoyaltyConfig(): Promise<{ data: LoyaltyConfig }> {
    return apiGet<{ data: LoyaltyConfig }>('/api/loyalty/config');
}

export function upsertLoyaltyConfig(
    payload: UpsertLoyaltyConfigPayload,
): Promise<{ data: LoyaltyConfig }> {
    return apiPatch<{ data: LoyaltyConfig }>(
        '/api/loyalty/config',
        payload as unknown as JsonValue,
    );
}

export function getCustomerLoyalty(customerUuid: string): Promise<{ data: CustomerLoyaltySummary }> {
    return apiGet<{ data: CustomerLoyaltySummary }>(`/api/customers/${customerUuid}/loyalty`);
}

export function adjustPoints(
    customerUuid: string,
    payload: AdjustPointsPayload,
): Promise<{ data: { entry: PointLedgerEntry; points_balance: number } }> {
    return apiPost(
        `/api/customers/${customerUuid}/points/adjust`,
        payload as unknown as JsonValue,
    );
}

export function topUpWallet(
    customerUuid: string,
    payload: TopUpWalletPayload,
): Promise<{ data: { entry: WalletLedgerEntry; wallet_balance: string } }> {
    return apiPost(
        `/api/customers/${customerUuid}/wallet/topup`,
        payload as unknown as JsonValue,
    );
}

export function adjustWallet(
    customerUuid: string,
    payload: AdjustWalletPayload,
): Promise<{ data: { entry: WalletLedgerEntry; wallet_balance: string } }> {
    return apiPost(
        `/api/customers/${customerUuid}/wallet/adjust`,
        payload as unknown as JsonValue,
    );
}

export function getPointLedger(customerUuid: string, page = 1, perPage = 50): Promise<PaginatedPoints> {
    return apiGet<PaginatedPoints>(`/api/customers/${customerUuid}/points/ledger`, {
        query: { page, per_page: perPage },
    });
}

export function getWalletLedger(customerUuid: string, page = 1, perPage = 50): Promise<PaginatedWallet> {
    return apiGet<PaginatedWallet>(`/api/customers/${customerUuid}/wallet/ledger`, {
        query: { page, per_page: perPage },
    });
}
