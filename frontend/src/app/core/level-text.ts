/**
 * A level in metres as plans write them: ±0.000, +3.000, −0.150; with `decimal` ',' as Czech plans do (±0,000).
 * Framework-free (the drawings use it): pages pass `decimalSeparator()` of their `LOCALE_ID`.
 */
export const levelText = (mm: number, decimal = '.'): string =>
    `${mm === 0 ? '±' : mm > 0 ? '+' : '−'}${(Math.abs(mm) / 1000).toFixed(3).replace('.', decimal)}`;
