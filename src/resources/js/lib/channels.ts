/**
 * LAUNCH-P4 B3 — channels (owner decision 8): a product or combo is sold
 *   - in store (till + handheld order types), at its branches;
 *   - on the QR menu (show_on_customer_tablet; the QR price is the in-store
 *     price);
 *   - on delivery, per provider: listed or not, at the provider's own price,
 *     else the product's delivery price, else its base price.
 * Pure helpers shared by the product wizard and the combo editor (no imports,
 * so the node tests can load this file as is).
 */

export interface ProviderChannelRow {
    listed: boolean;
    /** '' = the product's delivery price (else its base price). */
    price: string;
}

export interface ProviderLike {
    uuid: string;
}

export interface StoredProviderPrice {
    price: string | null;
    listed?: boolean;
    delivery_provider?: { uuid: string } | null;
}

/** Editor rows for every provider: stored rows where they exist, else listed at the default price. */
export function providerRowsFrom(providers: ProviderLike[], stored: StoredProviderPrice[]): Record<string, ProviderChannelRow> {
    const rows: Record<string, ProviderChannelRow> = {};
    for (const provider of providers) {
        rows[provider.uuid] = { listed: true, price: '' };
    }
    for (const row of stored) {
        const uuid = row.delivery_provider?.uuid;
        if (!uuid) continue;
        rows[uuid] = { listed: row.listed !== false, price: row.price ?? '' };
    }
    return rows;
}

/**
 * The rows worth sending on create: a provider that is NOT listed, or one
 * with its own price. Listed at the default price needs no row.
 */
export function providerPayload(
    providers: ProviderLike[],
    rows: Record<string, ProviderChannelRow>,
): { provider_uuid: string; listed: boolean; price: string | null }[] {
    const out: { provider_uuid: string; listed: boolean; price: string | null }[] = [];
    for (const provider of providers) {
        const row = rows[provider.uuid];
        if (!row) continue;
        const price = String(row.price ?? '').trim();
        if (row.listed && price === '') continue;
        out.push({ provider_uuid: provider.uuid, listed: row.listed, price: price === '' ? null : price });
    }
    return out;
}

/** What a provider charges: its own price, else the delivery price, else the base price. */
export function effectiveProviderPrice(row: ProviderChannelRow | undefined, deliveryPrice: string, basePrice: string): string {
    const own = String(row?.price ?? '').trim();
    if (own !== '') return own;
    const delivery = String(deliveryPrice ?? '').trim();
    if (delivery !== '') return delivery;
    return String(basePrice ?? '').trim();
}

export type ChannelKey = 'in_store' | 'qr' | 'delivery';

/** The three channel badges of a catalogue row, in display order. */
export function channelBadges(product: { sold_in_store?: boolean; show_on_customer_tablet?: boolean; sold_on_delivery?: boolean }): { key: ChannelKey; on: boolean }[] {
    return [
        { key: 'in_store', on: product.sold_in_store !== false },
        { key: 'qr', on: product.show_on_customer_tablet !== false },
        { key: 'delivery', on: product.sold_on_delivery !== false },
    ];
}

/** The branch rule as the API takes it ('all' never carries ids). */
export function branchScopePayload(scope: 'all' | 'selected', ids: number[]): { branch_scope: 'all' | 'selected'; branch_ids: number[] } {
    return scope === 'all'
        ? { branch_scope: 'all', branch_ids: [] }
        : { branch_scope: 'selected', branch_ids: [...new Set(ids)].sort((a, b) => a - b) };
}

/** The selected branch ids of a stored product (rows with is_available). */
export function selectedBranchIds(rows: { branch_id: number; is_available: boolean }[] | undefined): number[] {
    return (rows ?? []).filter((r) => r.is_available).map((r) => r.branch_id);
}
