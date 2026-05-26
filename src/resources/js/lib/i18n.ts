import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';

/**
 * vue-i18n bootstrap. Mirrors pos_admin's setup — Composition API
 * mode, EN as default + fallback, AR available via the language
 * toggle. The active locale persists in localStorage so a refresh
 * remembers the user's choice.
 *
 * applyDocumentDirection() flips <html dir> on locale change so
 * Tailwind's logical-property classes (ms-, me-, etc.) render
 * correctly in Arabic mode.
 */

const STORAGE_KEY = 'pos_merchant.locale';

export type SupportedLocale = 'en' | 'ar';

function loadPreferredLocale(): SupportedLocale {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'en' || stored === 'ar') {
            return stored;
        }
    } catch {
        // localStorage blocked (privacy mode) — fall through.
    }

    const browserLocale = navigator.language?.toLowerCase() ?? '';
    return browserLocale.startsWith('ar') ? 'ar' : 'en';
}

export const i18n = createI18n({
    legacy: false,
    locale: loadPreferredLocale(),
    fallbackLocale: 'en',
    messages: { en, ar },
});

export function applyDocumentDirection(): void {
    const locale = i18n.global.locale.value as SupportedLocale;
    const dir = locale === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.setAttribute('dir', dir);
    document.documentElement.setAttribute('lang', locale);
}

export function setLocale(locale: SupportedLocale): void {
    i18n.global.locale.value = locale;
    try {
        localStorage.setItem(STORAGE_KEY, locale);
    } catch {
        // ignore
    }
    applyDocumentDirection();
}
