/** `YYYY-MM-DD` of a date in local time, for date inputs and printed dates. */
export const localDate = (date: Date): string =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
