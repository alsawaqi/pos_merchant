/**
 * LAUNCH item kind, G3 (owner addendum 2026-10-03) — the "Show in: Auto /
 * kg·l / g·ml" switch of the branch stock and warehouse stock lists, one
 * choice for the whole portal, remembered per browser. Storage may be
 * blocked (private window, previews): every read / write is wrapped, and
 * the lists simply fall back to Auto.
 */
import { ref, watch, type Ref } from 'vue';
import { AMOUNT_DISPLAYS, type AmountDisplay } from '@/lib/itemKind';

export const AMOUNT_DISPLAY_KEY = 'pos.inventory.amount_display';

function readStored(): AmountDisplay {
    try {
        const value = window.localStorage.getItem(AMOUNT_DISPLAY_KEY);
        return AMOUNT_DISPLAYS.includes(value as AmountDisplay) ? (value as AmountDisplay) : 'auto';
    } catch {
        return 'auto';
    }
}

const mode = ref<AmountDisplay>(readStored());

watch(mode, (value) => {
    try {
        window.localStorage.setItem(AMOUNT_DISPLAY_KEY, value);
    } catch {
        // Storage unavailable: the choice lasts until the page reloads.
    }
});

export function useAmountDisplay(): { mode: Ref<AmountDisplay> } {
    return { mode };
}
