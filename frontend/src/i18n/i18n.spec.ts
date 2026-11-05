import {TranslationObject} from '@ngx-translate/core';

import {translations} from '../test-translations';

/** Every leaf of the texts by its dotted key. */
function flatten(texts: TranslationObject, prefix = ''): Map<string, string> {
    const leaves = new Map<string, string>();
    for (const [key, value] of Object.entries(texts)) {
        const path = prefix === '' ? key : `${prefix}.${key}`;
        if (typeof value === 'string') {
            leaves.set(path, value);
        } else if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
            for (const [leafKey, leaf] of flatten(value as TranslationObject, path)) {
                leaves.set(leafKey, leaf);
            }
        } else {
            leaves.set(path, `<not a text: ${JSON.stringify(value)}>`);
        }
    }
    return leaves;
}

const placeholders = (text: string): string[] => [...text.matchAll(/\{\{\s*(\w+)\s*\}\}/g)].map((m) => m[1]).sort();

describe('translations', () => {
    const en = flatten(translations('en'));
    const cs = flatten(translations('cs'));

    it('are texts only', () => {
        expect([...en.values(), ...cs.values()].filter((text) => text.startsWith('<not a text'))).toEqual([]);
    });

    it('have the same keys in English and Czech', () => {
        expect({missingInCs: [...en.keys()].filter((key) => !cs.has(key)), onlyInCs: [...cs.keys()].filter((key) => !en.has(key))})
            .toEqual({missingInCs: [], onlyInCs: []});
    });

    it('use the same placeholders in both', () => {
        const differing = [...en].filter(([key, text]) => cs.has(key) && placeholders(text).join() !== placeholders(cs.get(key) ?? '').join());
        expect(differing.map(([key]) => key)).toEqual([]);
    });

    it('leave no Czech text empty', () => {
        expect([...cs].filter(([, text]) => text.trim() === '').map(([key]) => key)).toEqual([]);
    });
});
