import {formatDate} from '@angular/common';
import {inject, LOCALE_ID, Pipe, PipeTransform} from '@angular/core';
import {DefaultLocale, Locale, toLocale} from '@app/core/locale';

/** How the pages write a moment: a date, a date with the time, a day of this year with the time, the time alone. */
export type DateStyle = 'date' | 'dateTime' | 'dayTime' | 'time';

/** Each language's own way (Czech `5. 11. 2026 14:05`), as Angular's named formats do not cover these. */
export const DateFormats: Readonly<Record<Locale, Readonly<Record<DateStyle, string>>>> = {
    en: {date: 'd MMM y', dateTime: 'd MMM y, HH:mm', dayTime: 'd MMM, HH:mm', time: 'HH:mm'},
    cs: {date: 'd. M. y', dateTime: 'd. M. y H:mm', dayTime: 'd. M. H:mm', time: 'H:mm'},
};

/** `{{ value | dateText: 'dateTime' }}`: a date in the page's language (`LOCALE_ID`); empty for none. */
@Pipe({name: 'dateText'})
export class DateTextPipe implements PipeTransform {
    private readonly localeId = inject(LOCALE_ID);
    private readonly locale = toLocale(this.localeId) ?? DefaultLocale;

    public transform(value: string | number | Date | null | undefined, style: DateStyle = 'date'): string {
        return value === null || value === undefined || value === '' ? '' : formatDate(value, DateFormats[this.locale][style], this.localeId);
    }
}
