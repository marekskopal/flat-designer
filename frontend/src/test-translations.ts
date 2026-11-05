import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {Provider} from '@angular/core';
import {provideTranslateLoader, provideTranslateService, TranslateLoader, TranslationObject} from '@ngx-translate/core';
import {Observable, of} from 'rxjs';

/** The texts of a language as the app loads them, `src/i18n/<locale>.json`. */
export function translations(locale: string): TranslationObject {
    return JSON.parse(readFileSync(resolve(process.cwd(), `src/i18n/${locale}.json`), 'utf8')) as TranslationObject;
}

class FileLoader implements TranslateLoader {
    public getTranslation(lang: string): Observable<TranslationObject> {
        return of(translations(lang));
    }
}

/** The real translator over the real texts, in English unless told otherwise, so specs assert what users read. */
export function provideTestTranslations(lang: 'en' | 'cs' = 'en'): Provider[] {
    return provideTranslateService({loader: provideTranslateLoader(FileLoader), lang, fallbackLang: 'en'});
}
