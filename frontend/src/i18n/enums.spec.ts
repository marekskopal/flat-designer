import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {OpeningTypes} from '@app/editor/element-fields';
import {ElementFields} from '@app/editor/element-fields';
import {CategoryLabels} from '@app/models/electrical-schedule';
import {FurnitureCatalogues} from '@app/models/furniture';
import {TranslationObject} from '@ngx-translate/core';

import {translations} from '../test-translations';

function text(texts: TranslationObject, key: string): unknown {
    let node: unknown = texts;
    for (const part of key.split('.')) {
        node = node !== null && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined;
    }
    return node;
}

describe('enum and material names', () => {
    const languages = {en: translations('en'), cs: translations('cs')};

    function missing(keys: string[]): string[] {
        return Object.entries(languages).flatMap(([language, texts]) =>
            keys.filter((key) => typeof text(texts, key) !== 'string').map((key) => `${language}: ${key}`),
        );
    }

    it('name every option of the inspector\'s selects', () => {
        const keys = Object.values(ElementFields).flat()
            .filter((def) => def.type === 'select')
            .flatMap((def) => {
                expect(def.enum, `${def.key} names its options`).toBeDefined();
                return (def.options ?? []).map((option) => `enums.${def.enum}.${option}`);
            });
        expect(missing(keys)).toEqual([]);
    });

    it('name the opening types, catalogues and electrical categories', () => {
        expect(missing([
            ...OpeningTypes.map((type) => `enums.openingType.${type}`),
            ...FurnitureCatalogues.map((catalogue) => `enums.catalogue.${catalogue}`),
            ...Object.keys(CategoryLabels).map((category) => `enums.electricalCategory.${category}`),
        ])).toEqual([]);
    });

    it('name every seeded material (the backend keeps their codes in a fixture)', () => {
        const codes = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/material-codes.json'), 'utf8')) as string[];
        expect(codes.length).toBeGreaterThan(0);
        expect(missing(codes.map((code) => `materials.${code}`))).toEqual([]);
    });
});
