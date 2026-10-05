/**
 * LAUNCH review add-on (E2) — "Is this right?" before saving an amount that
 * looks unrealistic. It WARNS and never blocks: confirm() resolves true when
 * there is nothing to say or the person answers "Yes, save", false when they
 * go back. Render <AmountConfirmDialog> bound to `warnings` and `answer`.
 */
import { ref, type Ref } from 'vue';
import type { AmountWarning } from '@/lib/amountSafety';

export function useAmountConfirm(): {
    warnings: Ref<AmountWarning[]>;
    confirm: (list: (AmountWarning | null | undefined)[]) => Promise<boolean>;
    answer: (ok: boolean) => void;
} {
    const warnings = ref<AmountWarning[]>([]);
    let resolver: ((ok: boolean) => void) | null = null;

    function confirm(list: (AmountWarning | null | undefined)[]): Promise<boolean> {
        const real = list.filter((w): w is AmountWarning => !!w);
        if (real.length === 0) return Promise.resolve(true);
        warnings.value = real;
        return new Promise<boolean>((resolve) => {
            resolver = resolve;
        });
    }

    function answer(ok: boolean): void {
        warnings.value = [];
        const done = resolver;
        resolver = null;
        done?.(ok);
    }

    return { warnings, confirm, answer };
}
