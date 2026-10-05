/**
 * LAUNCH review add-on (owner decisions D9–D12) — pure helpers for the menu
 * screens: limited-time dates, cooking time, the combo's main slot ("Make it
 * a meal?") and the recipe lines ticked "Can be removed". No imports, so the
 * node tests load this file as is.
 *
 * Dates are calendar days 'YYYY-MM-DD' in Asia/Muscat, both bounds inclusive;
 * null = no bound. They are not the daily hours ('HH:MM').
 */

export const MAX_COOKING_MINUTES = 240;

/** 'YYYY-MM-DD', or null for anything else (blank = no bound). */
export function saleDay(value: string | null | undefined): string | null {
    const text = String(value ?? '').trim();
    return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : null;
}

/** Today in Asia/Muscat as 'YYYY-MM-DD' (the server's business day). */
export function muscatToday(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Muscat', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/** "1 Nov" / "1 نوفمبر" (with the year when it is not this year's). */
export function shortDay(day: string, locale: string, today: string): string {
    const options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', timeZone: 'UTC' };
    if (day.slice(0, 4) !== today.slice(0, 4)) options.year = 'numeric';
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-OM' : 'en-GB', options).format(new Date(`${day}T00:00:00Z`));
}

export type SaleBadgeKind = 'starts' | 'ends' | 'ended';

/**
 * The list badge of a limited-time item: "Ended" once the last day has
 * passed, "Starts {date}" before the first day, else "Ends {date}" while an
 * end date is set. No badge for an item without dates (or only a past start).
 */
export function saleBadge(
    from: string | null | undefined,
    until: string | null | undefined,
    today: string,
): { kind: SaleBadgeKind; date: string } | null {
    const start = saleDay(from);
    const end = saleDay(until);
    if (end !== null && end < today) return { kind: 'ended', date: end };
    if (start !== null && start > today) return { kind: 'starts', date: start };
    if (end !== null) return { kind: 'ends', date: end };
    return null;
}

/** Sold today (inside both inclusive bounds)? */
export function onSaleOn(from: string | null | undefined, until: string | null | undefined, today: string): boolean {
    const start = saleDay(from);
    const end = saleDay(until);
    return (start === null || start <= today) && (end === null || end >= today);
}

/** Has sale dates at all (a limited-time item). */
export function isLimited(item: { on_sale_from?: string | null; on_sale_until?: string | null } | null | undefined): boolean {
    return item != null && (saleDay(item.on_sale_from) !== null || saleDay(item.on_sale_until) !== null);
}

/** What blocks saving the two dates. */
export function datesProblem(from: string | null | undefined, until: string | null | undefined): 'until_before_from' | null {
    const start = saleDay(from);
    const end = saleDay(until);
    return start !== null && end !== null && end < start ? 'until_before_from' : null;
}

/** A blank box = not set; else a whole number of minutes, 0..240. */
export function cookingProblem(value: string | number | null | undefined): 'cooking_range' | null {
    const text = String(value ?? '').trim();
    if (text === '') return null;
    if (!/^\d+$/.test(text)) return 'cooking_range';
    const minutes = Number(text);
    return minutes >= 0 && minutes <= MAX_COOKING_MINUTES ? null : 'cooking_range';
}

/** The wire value: null when blank. */
export function cookingPayload(value: string | number | null | undefined): number | null {
    const text = String(value ?? '').trim();
    return text === '' ? null : Number(text);
}

/**
 * The minutes the customer sees for a combo: its own value, else the longest
 * of its items (conservative: never promise less than the kitchen needs).
 */
export function comboCookingFigure(own: string | number | null | undefined, itemMinutes: (number | null | undefined)[]): number | null {
    const text = String(own ?? '').trim();
    if (text !== '' && /^\d+$/.test(text)) return Number(text);
    const known = itemMinutes.filter((m): m is number => typeof m === 'number' && Number.isFinite(m));
    return known.length === 0 ? null : Math.max(...known);
}

export interface MainSlotDraft {
    min_choices: number | string;
    max_choices: number | string;
    is_main?: boolean;
}

/** Only a slot where exactly one item is picked can be the main (tester call 15). */
export function canBeMain(slot: MainSlotDraft): boolean {
    return Math.trunc(Number(slot.min_choices)) === 1 && Math.trunc(Number(slot.max_choices)) === 1;
}

export type MainIssue = 'main_not_single' | 'two_mains';

/** Per slot index, what is wrong with its main flag (the server checks the same). */
export function mainIssues(slots: MainSlotDraft[]): { index: number; issue: MainIssue }[] {
    const issues: { index: number; issue: MainIssue }[] = [];
    let mains = 0;
    slots.forEach((slot, index) => {
        if (!slot.is_main) return;
        mains += 1;
        if (mains > 1) issues.push({ index, issue: 'two_mains' });
        if (!canBeMain(slot)) issues.push({ index, issue: 'main_not_single' });
    });
    return issues;
}

export interface LimitedSlotDraft {
    min_choices: number | string;
    options: { product_uuid: string }[];
}

/**
 * The required slots (least ≥ 1) whose every item has sale dates: once those
 * end, the combo can no longer be completed. A warning, never a block.
 */
export function limitedSlotIndexes(
    slots: LimitedSlotDraft[],
    itemOf: (uuid: string) => { on_sale_from?: string | null; on_sale_until?: string | null } | null | undefined,
): number[] {
    const out: number[] = [];
    slots.forEach((slot, index) => {
        const picked = slot.options.filter((o) => o.product_uuid !== '');
        if (Math.trunc(Number(slot.min_choices) || 0) < 1 || picked.length === 0) return;
        if (picked.every((o) => isLimited(itemOf(o.product_uuid)))) out.push(index);
    });
    return out;
}

export interface RemovableDraft {
    ticked: boolean;
    label: string;
    label_ar: string;
}

/** The server names each option "NO {label}" / "بدون {label_ar}" (printed as is on today's tickets). */
export const REMOVE_PREFIX = 'NO ';
export const REMOVE_PREFIX_AR = 'بدون ';

/** What the customer will see for a ticked line (the label, else the ingredient's name). */
export function removeOptionName(label: string, ingredientName: string): string {
    return REMOVE_PREFIX + (label.trim() === '' ? ingredientName.trim() : label.trim());
}

export function removeOptionNameAr(labelAr: string, ingredientNameAr: string | null, fallback: string): string {
    const text = labelAr.trim() !== '' ? labelAr.trim() : (ingredientNameAr ?? '').trim() !== '' ? (ingredientNameAr ?? '').trim() : fallback.trim();
    return REMOVE_PREFIX_AR + text;
}

/** The loaded state → the editable ticks, keyed by ingredient uuid. */
export function ticksFromState(lines: { ingredient_uuid: string; label: string | null; label_ar: string | null }[]): Record<string, RemovableDraft> {
    const out: Record<string, RemovableDraft> = {};
    for (const line of lines) {
        out[line.ingredient_uuid] = { ticked: true, label: line.label ?? '', label_ar: line.label_ar ?? '' };
    }
    return out;
}

export interface RemovableLinePayload {
    ingredient_uuid: string;
    label: string | null;
    label_ar: string | null;
}

/**
 * The "Can be removed" ticks to save: one row per ticked line of the recipe
 * as it stands (a deleted line is never sent), in recipe order, each once.
 */
export function removablePayload(
    recipeLines: { ingredient_uuid: string }[],
    ticks: Record<string, RemovableDraft | undefined>,
): RemovableLinePayload[] {
    const out: RemovableLinePayload[] = [];
    const seen = new Set<string>();
    for (const line of recipeLines) {
        const uuid = line.ingredient_uuid;
        const tick = uuid === '' ? undefined : ticks[uuid];
        if (!tick || !tick.ticked || seen.has(uuid)) continue;
        seen.add(uuid);
        out.push({
            ingredient_uuid: uuid,
            label: tick.label.trim() === '' ? null : tick.label.trim(),
            label_ar: tick.label_ar.trim() === '' ? null : tick.label_ar.trim(),
        });
    }
    return out;
}

/** How far loading a product's saved ticks got (a new product has none to load). */
export type RemovableLoadState = 'loading' | 'ok' | 'failed';

export interface RemovableSavedLine {
    ingredient_uuid: string;
    label: string | null;
    label_ar: string | null;
}

/**
 * Fix order C-1, M1 — whether an edit save sends the "Can be removed" ticks.
 * Never when they did not load (an empty or partial list would wipe the
 * saved Remove list); never when the merchant changed nothing (two managers
 * would overwrite each other). Otherwise the ticks, plus the ones the page
 * loaded with (`expected`), so the server refuses a stale page with 409.
 * A line deleted from the recipe in the same save is not a tick change: the
 * recipe save retires its option.
 */
export function removableSaveDecision(
    loadState: RemovableLoadState,
    recipeLines: { ingredient_uuid: string }[],
    ticks: Record<string, RemovableDraft | undefined>,
    baseline: RemovableSavedLine[],
): { send: false } | { send: true; lines: RemovableLinePayload[]; expected: RemovableSavedLine[] } {
    if (loadState !== 'ok') return { send: false };
    const lines = removablePayload(recipeLines, ticks);
    const before = removablePayload(recipeLines, ticksFromState(baseline));
    if (JSON.stringify(lines) === JSON.stringify(before)) return { send: false };
    return {
        send: true,
        lines,
        expected: baseline.map((l) => ({ ingredient_uuid: l.ingredient_uuid, label: l.label ?? null, label_ar: l.label_ar ?? null })),
    };
}

export interface ComboTimingDraft {
    /** 'HH:MM' from a time box ('' = no bound). */
    available_from: string;
    available_until: string;
    /** 'YYYY-MM-DD' from a date box ('' = no bound). */
    on_sale_from: string;
    on_sale_until: string;
    cooking_minutes: string | number;
}

/**
 * Fix order C-1, L7 — the combo editor's daily hours, dates and cooking time
 * as saved: the hours as set ('HH:MM:SS'; they used to be sent as null,
 * wiping them), blank = no bound / not set.
 */
export function comboMenuFields(form: ComboTimingDraft): {
    available_from: string | null;
    available_until: string | null;
    on_sale_from: string | null;
    on_sale_until: string | null;
    cooking_minutes: number | null;
} {
    const hours = (value: string): string | null => {
        const text = String(value ?? '').trim();
        return /^[0-2]\d:[0-5]\d/.test(text) ? `${text.slice(0, 5)}:00` : null;
    };
    return {
        available_from: hours(form.available_from),
        available_until: hours(form.available_until),
        on_sale_from: saleDay(form.on_sale_from),
        on_sale_until: saleDay(form.on_sale_until),
        cooking_minutes: cookingPayload(form.cooking_minutes),
    };
}

export type AddOnKind = 'extras' | 'remove' | 'instructions';

/** A group saved before kinds existed reads as Extras. */
export function groupKind(group: { kind?: string | null } | null | undefined): AddOnKind {
    const kind = group?.kind;
    return kind === 'remove' || kind === 'instructions' ? kind : 'extras';
}
