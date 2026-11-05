import {initialLocale, toLocale} from '@app/core/locale';

describe('toLocale', () => {
    it('takes the primary subtag of a tag', () => {
        const tags = ['cs', 'cs-CZ', 'cs_CZ', 'EN-gb', 'csb', 'de', '', null];
        expect(tags.map(toLocale)).toEqual(['cs', 'cs', 'cs', 'en', null, null, null, null]);
    });
});

describe('initialLocale', () => {
    const language = vi.spyOn(navigator, 'language', 'get');

    afterEach(() => language.mockReset());

    it('takes the browser language, English for one it does not know', () => {
        language.mockReturnValue('cs-CZ');
        expect(initialLocale(null)).toBe('cs');
        language.mockReturnValue('de-DE');
        expect(initialLocale(null)).toBe('en');
    });

    it('prefers the device choice to the browser language', () => {
        language.mockReturnValue('cs-CZ');
        expect(initialLocale('en')).toBe('en');
        expect(initialLocale(42)).toBe('cs');
    });
});
