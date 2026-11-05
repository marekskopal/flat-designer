import {effect, inject, Injectable, LOCALE_ID, signal} from '@angular/core';
import {DefaultLocale, Locale, Locales, LocaleStorageKey, toLocale} from '@app/core/locale';
import {CurrentUserService} from '@app/services/current-user.service';
import {StorageService} from '@app/services/storage.service';
import {TranslateService} from '@ngx-translate/core';
import {firstValueFrom} from 'rxjs';

/** Set before a reload into the account's language, so a device that cannot keep the choice reloads only once. */
const ReloadGuardKey = 'izbik.languageReload';

/**
 * The language the texts are shown in. A page load starts in `LOCALE_ID` (`initialLocale()`), which numbers and dates
 * follow for the whole load. A signed-in user's own `locale` wins: when it differs from the page's, the page remembers
 * it on the device and reloads (`reloadInto()`), so the texts, numbers and dates all change together.
 */
@Injectable({providedIn: 'root'})
export class LanguageService {
    private readonly translate = inject(TranslateService);
    private readonly storage = inject(StorageService);
    private readonly currentUser = inject(CurrentUserService);
    private readonly pageLocale = toLocale(inject(LOCALE_ID)) ?? DefaultLocale;

    private readonly locale = signal<Locale>(this.pageLocale);
    public readonly current = this.locale.asReadonly();
    private reloading = false;

    public constructor() {
        effect(() => {
            const user = this.currentUser.currentUser();
            if (user === null) {
                return;
            }
            if (user.locale !== this.pageLocale) {
                this.reloadInto(user.locale);
            } else if (this.locale() !== user.locale) {
                void this.use(user.locale);
            }
        });
    }

    /** Loads the page's texts; the app initializer waits for it, so the first render is translated. */
    public async init(): Promise<void> {
        this.translate.addLangs([...Locales]);
        this.translate.setFallbackLang(DefaultLocale);
        if (sessionStorage.getItem(ReloadGuardKey) === this.pageLocale) {
            sessionStorage.removeItem(ReloadGuardKey);
        }
        await this.use(this.pageLocale).catch(() => undefined);
    }

    /** Shows the texts in `locale` from now on, on this device (a page without an account). */
    public async use(locale: Locale): Promise<void> {
        this.locale.set(locale);
        this.storage.set(LocaleStorageKey, locale);
        document.documentElement.lang = locale;
        await firstValueFrom(this.translate.use(locale));
    }

    /**
     * Remembers `locale` on the device and reloads the page in it (after one's own language changed). Should the reload
     * come back in the old language anyway (storage refused), the texts alone switch.
     */
    public reloadInto(locale: Locale): void {
        if (this.reloading) {
            return;
        }
        this.storage.set(LocaleStorageKey, locale);
        if (sessionStorage.getItem(ReloadGuardKey) === locale) {
            void this.use(locale).catch(() => undefined);
            return;
        }
        this.reloading = true;
        sessionStorage.setItem(ReloadGuardKey, locale);
        this.reload();
    }

    protected reload(): void {
        window.location.reload();
    }
}
