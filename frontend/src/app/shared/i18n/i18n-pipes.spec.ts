import {registerLocaleData} from '@angular/common';
import localeCs from '@angular/common/locales/cs';
import {LOCALE_ID} from '@angular/core';
import {TestBed} from '@angular/core/testing';
import {DateTextPipe} from '@app/shared/i18n/date-text.pipe';
import {pluralText} from '@app/shared/i18n/plural.pipe';
import {TranslateService} from '@ngx-translate/core';
import {firstValueFrom} from 'rxjs';

import {provideTestTranslations} from '../../../test-translations';

describe('pluralText', () => {
    it('picks the form by the language\'s plural rules', async () => {
        TestBed.configureTestingModule({providers: [provideTestTranslations('cs')]});
        const translate = TestBed.inject(TranslateService);
        await firstValueFrom(translate.use('cs'));

        expect([1, 2, 4, 5, 21].map((n) => pluralText(translate, 'cs', 'files.processed', n))).toEqual([
            'Zpracován 1 soubor.',
            'Zpracovány 2 soubory.',
            'Zpracovány 4 soubory.',
            'Zpracováno 5 souborů.',
            'Zpracováno 21 souborů.',
        ]);
        await firstValueFrom(translate.use('en'));
        expect([1, 2].map((n) => pluralText(translate, 'en', 'files.processed', n))).toEqual(['1 file processed.', '2 files processed.']);
    });
});

describe('DateTextPipe', () => {
    const at = new Date(2026, 10, 5, 14, 5);

    function pipe(locale: string): DateTextPipe {
        TestBed.configureTestingModule({providers: [{provide: LOCALE_ID, useValue: locale}]});
        return TestBed.runInInjectionContext(() => new DateTextPipe());
    }

    it('writes dates the English way', () => {
        const date = pipe('en');
        expect([date.transform(at), date.transform(at, 'dateTime'), date.transform(null)]).toEqual(['5 Nov 2026', '5 Nov 2026, 14:05', '']);
    });

    it('writes dates the Czech way', () => {
        registerLocaleData(localeCs);
        const date = pipe('cs');
        expect([date.transform(at), date.transform(at, 'dateTime'), date.transform(at, 'time')])
            .toEqual(['5. 11. 2026', '5. 11. 2026 14:05', '14:05']);
    });
});
