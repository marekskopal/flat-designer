import {LOCALE_ID, provideZonelessChangeDetection} from '@angular/core';
import {TestBed} from '@angular/core/testing';
import {Locale, LocaleStorageKey} from '@app/core/locale';
import {User} from '@app/models/user';
import {CurrentUserService} from '@app/services/current-user.service';
import {LanguageService} from '@app/services/language.service';
import {TranslateService} from '@ngx-translate/core';

import {provideTestTranslations} from '../../test-translations';

describe('LanguageService', () => {
    let reload: ReturnType<typeof vi.spyOn>;

    function setUp(pageLocale: Locale): {service: LanguageService; translate: TranslateService} {
        TestBed.configureTestingModule({
            providers: [provideZonelessChangeDetection(), provideTestTranslations(), {provide: LOCALE_ID, useValue: pageLocale}],
        });
        const service = TestBed.inject(LanguageService);
        reload = vi.spyOn(service as unknown as {reload: () => void}, 'reload').mockImplementation(() => undefined);
        return {service, translate: TestBed.inject(TranslateService)};
    }

    const stored = (): unknown => JSON.parse(localStorage.getItem(LocaleStorageKey) ?? 'null');
    const signIn = (locale: Locale): void => {
        TestBed.inject(CurrentUserService).currentUser.set({locale} as User);
        TestBed.tick();
    };

    beforeEach(() => {
        localStorage.clear();
        sessionStorage.clear();
    });

    afterEach(() => vi.restoreAllMocks());

    it('starts in the page language and shows its texts', async () => {
        const {service, translate} = setUp('cs');

        await service.init();

        expect(service.current()).toBe('cs');
        expect(translate.instant('common.language')).toBe('Jazyk');
        expect(document.documentElement.lang).toBe('cs');
    });

    it('remembers a language chosen on the device', async () => {
        const {service, translate} = setUp('en');

        await service.use('cs');

        expect(stored()).toBe('cs');
        expect(translate.instant('common.language')).toBe('Jazyk');
    });

    it('reloads into the signed-in user\'s language, so numbers and dates follow it too', async () => {
        const {service} = setUp('en');
        await service.init();

        signIn('cs');

        expect(stored()).toBe('cs');
        expect(reload).toHaveBeenCalledTimes(1);
        service.reloadInto('cs');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('does not reload when the page is in the user\'s language already', async () => {
        const {service} = setUp('cs');
        await service.init();

        signIn('cs');

        expect(reload).not.toHaveBeenCalled();
        expect(service.current()).toBe('cs');
    });

    it('reloads once only when the device does not keep the choice', async () => {
        sessionStorage.setItem('izbik.languageReload', 'cs');
        const {service, translate} = setUp('en');
        await service.init();

        signIn('cs');
        await vi.waitFor(() => expect(translate.instant('common.language')).toBe('Jazyk'));

        expect(reload).not.toHaveBeenCalled();
        expect(service.current()).toBe('cs');
    });
});
