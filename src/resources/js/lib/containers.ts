/**
 * LAUNCH review add-on (A2, B, C, D, F) — an item's containers in the portal.
 *
 * A container ("How do you buy it?") is a row the server sends in an
 * ingredient's alt_units: a LEAF holds an amount of the item ("bottle holds
 * 1 l"), a nested one holds N of exactly one other container ("crate holds 12
 * × bottle 1 l"). The same word may have different sizes, so a container is
 * named on the wire by its TOKEN ("#" + its uuid in base64url, 23 characters
 * — {@link containerToken}), never by its name, and shown by the server-made
 * display name ("bottle 1.5 l", "crate (12 × bottle 1 l)").
 *
 * Amounts by container: the amount fills in as Σ pieces × size and may be
 * LOWERED (a broken bottle, a half-used one) but never RAISED
 * ({@link capOf}, {@link amountProblem}). The breakdown by container is shown
 * under the live total; it is never used to compute stock.
 *
 * Self-contained (no imports) so the node tests can load it on its own.
 */

export interface ContainerSource {
    uuid: string;
    token?: string;
    name: string;
    name_ar?: string | null;
    /** Stored units in ONE (nesting included), a decimal string. */
    factor: string;
    display_name?: string;
    display_name_ar?: string;
    contains_unit_uuid?: string | null;
    contains_quantity?: string | null;
    leaf_uuid?: string;
    /** How many of the leaf ONE of this holds ("12" for a crate of 12 bottles). */
    leaf_pieces?: string;
    is_count_container?: boolean;
    /** True once used (purchases, stock, counts, recipes): the size cannot change. */
    size_locked?: boolean | null;
    barcodes?: { uuid: string; barcode: string; label: string | null }[];
}

export interface ContainerHolder {
    unit: string;
    alt_units?: ContainerSource[];
    count_container_uuid?: string | null;
}

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

/** "#" + the uuid's 16 bytes in base64url (mirrors App\Support\Inventory\ContainerToken). */
export function containerToken(uuid: string): string {
    const hex = String(uuid).replace(/-/g, '').toLowerCase();
    if (!/^[0-9a-f]{32}$/.test(hex)) return `#${uuid}`;
    const bytes: number[] = [];
    for (let i = 0; i < 32; i += 2) bytes.push(parseInt(hex.slice(i, i + 2), 16));
    let out = '';
    for (let i = 0; i < bytes.length; i += 3) {
        const a = bytes[i]!;
        const b = bytes[i + 1];
        const c = bytes[i + 2];
        out += B64[a >> 2];
        out += B64[((a & 3) << 4) | ((b ?? 0) >> 4)];
        if (b !== undefined) out += B64[((b & 15) << 2) | ((c ?? 0) >> 6)];
        if (c !== undefined) out += B64[c & 63];
    }
    return `#${out}`;
}

/**
 * Whether a unit value names a container (a token). Fix order B-1 (L9) —
 * only the exact formats: "#" + 22 base64url characters, or "#" + a uuid; an
 * older container named "#10 can" is a name, not a token.
 */
export function isContainerToken(unit: string | null | undefined): boolean {
    return typeof unit === 'string'
        && (/^#[A-Za-z0-9_-]{22}$/.test(unit) || /^#[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/.test(unit));
}

/** The token of a container (the server's, or worked out from its uuid). */
export function tokenOf(container: ContainerSource): string {
    return container.token ?? containerToken(container.uuid);
}

/** The item's containers (rows with a uuid), in the server's order. */
export function containersOf(holder: ContainerHolder | null | undefined): ContainerSource[] {
    return (holder?.alt_units ?? []).filter((c) => typeof c.uuid === 'string' && c.uuid !== '');
}

/** A container of the item by uuid or token, or null. */
export function findContainer(holder: ContainerHolder | null | undefined, ref: string | null | undefined): ContainerSource | null {
    if (!ref) return null;
    return containersOf(holder).find((c) => c.uuid === ref || tokenOf(c) === ref) ?? null;
}

function round4(value: number): number {
    return Math.round((value + Number.EPSILON * Math.sign(value)) * 10000) / 10000;
}

function trim(value: number): string {
    if (!Number.isFinite(value)) return '';
    const text = round4(value).toFixed(4).replace(/\.?0+$/, '');
    return text === '-0' ? '0' : text;
}

function num(value: string | number | null | undefined): number {
    const n = typeof value === 'number' ? value : parseFloat(String(value ?? '').trim());
    return Number.isFinite(n) ? n : NaN;
}

/** An amount as people read it: 1000 g / ml and above in kg / l (mirrors friendlyAmount in lib/itemKind). */
export function friendly(quantity: number, storedUnit: string): string {
    if ((storedUnit === 'g' || storedUnit === 'ml') && Math.abs(quantity) >= 1000) {
        return `${trim(quantity / 1000)} ${storedUnit === 'g' ? 'kg' : 'l'}`;
    }
    return `${trim(quantity)} ${storedUnit}`;
}

/** The name people read, in the reader's language ("bottle 1.5 l", "زجاجة 1.5 l"). */
export function containerLabel(container: ContainerSource, locale?: string | null, storedUnit?: string): string {
    if (locale === 'ar' && container.display_name_ar) return container.display_name_ar;
    if (container.display_name) return container.display_name;
    const name = locale === 'ar' && container.name_ar ? container.name_ar : container.name;
    return storedUnit ? `${name} ${friendly(num(container.factor), storedUnit)}` : name;
}

/** The leaf container ONE of this ends in and how many of it ("crate" → bottle × 12). */
export function leafOf(container: ContainerSource, all: ContainerSource[]): { leaf: ContainerSource; perLeaf: number } {
    let current = container;
    let perLeaf = 1;
    for (let depth = 0; depth < 4 && current.contains_unit_uuid; depth += 1) {
        const child = all.find((c) => c.uuid === current.contains_unit_uuid);
        if (!child) break;
        perLeaf *= num(current.contains_quantity) || 1;
        current = child;
    }
    return { leaf: current, perLeaf };
}

/** What the pieces hold, in the stored unit (4 decimals): Σ pieces × factor. */
export function capOf(rows: { factor: string | number; pieces: string | number }[]): number {
    let total = 0;
    for (const row of rows) {
        const pieces = num(row.pieces);
        const factor = num(row.factor);
        if (Number.isFinite(pieces) && Number.isFinite(factor)) total += pieces * factor;
    }
    return round4(total);
}

const UNIT_SIZE: Record<string, number> = {
    kg: 1000, g: 1, lb: 453.59237, oz: 28.349523125,
    l: 1000, ml: 1, gal: 3785.411784, 'fl oz': 29.5735295625,
};

/** An amount typed in a unit of the item's kind ('' = the stored unit), in the stored unit; null when blank / unknown. */
export function amountInStored(amount: string | number | null | undefined, unit: string, storedUnit: string): number | null {
    const text = String(amount ?? '').trim();
    if (text === '') return null;
    const n = num(text);
    if (!Number.isFinite(n)) return null;
    if (unit === '' || unit === storedUnit) return round4(n);
    const from = UNIT_SIZE[unit];
    const to = UNIT_SIZE[storedUnit];
    return from !== undefined && to !== undefined ? round4((n * from) / to) : null;
}

/** The [{container_uuid, pieces}] rows of an item → their cap in the stored unit (unknown containers count 0). */
export function rowsCap(holder: ContainerHolder | null | undefined, rows: { container_uuid: string; pieces: string | number }[]): number {
    return capOf(rows.map((r) => ({ factor: findContainer(holder, r.container_uuid)?.factor ?? '0', pieces: r.pieces })));
}

/**
 * Why a typed amount (already in the stored unit) cannot be saved against
 * the containers' cap, or null: more than the containers hold ('raised'),
 * or not above 0 ('not_positive' — a count may be 0 when allowZero).
 */
export function amountProblem(amountStored: number | null, cap: number, allowZero = false): 'raised' | 'not_positive' | null {
    if (amountStored === null || !Number.isFinite(amountStored)) return null;
    if (amountStored < 0 || (!allowZero && amountStored === 0)) return 'not_positive';
    return round4(amountStored) > round4(cap) + 1e-9 ? 'raised' : null;
}

/**
 * The live line of a purchase / transfer / waste by container: "= 24 ×
 * bottle 1 l = 24 l", and the cost per kg / l / piece when a price was typed
 * ("0.200 per l"). A free line (price 0) says it is free.
 */
export function containerLineText(opts: {
    container: ContainerSource | null;
    all: ContainerSource[];
    pieces: string | number;
    amountStored: number | null;
    storedUnit: string;
    lineCost?: string | number | null;
    locale?: string | null;
}): { leaf: string | null; amount: string | null; costPer: { cost: string; unit: string } | null; free: boolean } {
    const pieces = num(opts.pieces);
    const amount = opts.amountStored;
    let leaf: string | null = null;
    if (opts.container && Number.isFinite(pieces) && pieces > 0 && opts.container.contains_unit_uuid) {
        const { leaf: leafContainer, perLeaf } = leafOf(opts.container, opts.all);
        leaf = `${trim(pieces * perLeaf)} × ${containerLabel(leafContainer, opts.locale, opts.storedUnit)}`;
    }
    const amountText = amount !== null && Number.isFinite(amount) && amount > 0 ? friendly(amount, opts.storedUnit) : null;
    const costText = opts.lineCost === null || opts.lineCost === undefined ? '' : String(opts.lineCost).trim();
    const cost = costText === '' ? NaN : num(costText);
    let costPer: { cost: string; unit: string } | null = null;
    if (Number.isFinite(cost) && cost > 0 && amount !== null && amount > 0) {
        const perBase = cost / amount;
        const big = opts.storedUnit === 'g' || opts.storedUnit === 'ml';
        const value = big ? perBase * 1000 : perBase;
        const fixed = (Math.round(value * 1e6) / 1e6).toFixed(6);
        const [whole, decimals = ''] = fixed.split('.');
        costPer = { cost: `${whole}.${decimals.replace(/0+$/, '').padEnd(3, '0')}`, unit: big ? (opts.storedUnit === 'g' ? 'kg' : 'l') : opts.storedUnit };
    }
    return { leaf, amount: amountText, costPer, free: Number.isFinite(cost) && cost === 0 };
}

/** One breakdown row from the server. */
export interface BreakdownRow {
    container_uuid: string;
    display_name: string;
    display_name_ar: string;
    pieces: string;
    amount: string;
    removed?: boolean;
}

/** The breakdown as one line: "2 × bottle 1.5 l + 3 × bottle 500 ml" ('' when empty). */
export function breakdownText(rows: BreakdownRow[] | null | undefined, locale?: string | null): string {
    return (rows ?? [])
        .map((row) => `${row.pieces} × ${locale === 'ar' && row.display_name_ar ? row.display_name_ar : row.display_name}`)
        .join(' + ');
}

/**
 * F2 — "the same scan again makes it 2": the index of the line a scan adds
 * to (same item and container), or -1 when a new line is needed.
 */
export function scanTargetIndex<T>(lines: T[], matches: (line: T) => boolean): number {
    return lines.findIndex(matches);
}

/** A pieces value plus one, as typed text (blank / not a number → "1"). */
export function plusOne(pieces: string | number | null | undefined): string {
    const n = num(pieces);
    return Number.isFinite(n) && n > 0 ? trim(n + 1) : '1';
}
