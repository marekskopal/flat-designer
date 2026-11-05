import {inject, Pipe, PipeTransform} from '@angular/core';
import {LanguageService} from '@app/services/language.service';
import {TranslateService} from '@ngx-translate/core';

/**
 * The plural form of `key` for `count`, with `{{count}}` filled: `key.one`, `key.few` or `key.other` by the language's
 * plural rules (`Intl.PluralRules`; English has no `few`, Czech's `many` is for fractions and falls to `other`). Every
 * plural key has the three forms in each language, so the files keep the same keys.
 */
@Pipe({name: 'plural', pure: false})
export class PluralPipe implements PipeTransform {
    private readonly translate = inject(TranslateService);
    private readonly language = inject(LanguageService);

    public transform(key: string, count: number, params: Record<string, unknown> = {}): string {
        return pluralText(this.translate, this.language.current(), key, count, params);
    }
}

export function pluralText(translate: TranslateService, locale: string, key: string, count: number, params: Record<string, unknown> = {}): string {
    const category = new Intl.PluralRules(locale).select(count);
    const form = category === 'one' || category === 'few' ? category : 'other';
    return translate.instant(`${key}.${form}`, {count, ...params}) as string;
}
