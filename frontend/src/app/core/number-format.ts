import {getLocaleNumberSymbol, NumberSymbol} from '@angular/common';

/** The decimal separator of a locale (`.` in English, `,` in Czech), for texts the framework-free code writes. */
export function decimalSeparator(locale: string): string {
    return getLocaleNumberSymbol(locale, NumberSymbol.Decimal);
}

/** `value.toFixed(digits)` with the locale's decimal separator: for hints and labels built as plain strings. */
export function fixed(value: number, digits: number, decimal: string): string {
    return value.toFixed(digits).replace('.', decimal);
}
