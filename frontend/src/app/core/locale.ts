/** The languages of the application; English is the default and the fallback for a missing translation. */
export type Locale = 'en' | 'cs';

export const Locales: readonly Locale[] = ['en', 'cs'];
export const DefaultLocale: Locale = 'en';

/** Each language in itself, for the switches. */
export const LocaleNames: Readonly<Record<Locale, string>> = {en: 'English', cs: 'Čeština'};

/** The device's choice in localStorage (through `StorageService`). */
export const LocaleStorageKey = 'izbik.language';

/** By the primary subtag: `cs`, `cs-CZ` → `cs`; `csb`, `de` and nothing → null. */
export function toLocale(language: string | null | undefined): Locale | null {
    const primary = (language ?? '').trim().split(/[-_]/)[0].toLowerCase();
    return (Locales as readonly string[]).includes(primary) ? (primary as Locale) : null;
}

/**
 * The language of a page load (`LOCALE_ID`): the device's choice, else the browser's language, else English. A signed-in
 * user's own language replaces it once their profile has loaded (`LanguageService`).
 */
export function initialLocale(stored: unknown): Locale {
    return toLocale(typeof stored === 'string' ? stored : null) ?? toLocale(navigator.language) ?? DefaultLocale;
}
