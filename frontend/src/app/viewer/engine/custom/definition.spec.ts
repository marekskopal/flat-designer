import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {describe, expect, it} from 'vitest';

import {DefinitionError, evaluateDefinition, ItemSize, ResolvedPart} from './definition';

/** The fixture shared with the PHP twin (backend/tests/Service/Furniture/DefinitionEvaluatorTest.php); vitest runs in frontend/. */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/furniture-definitions.json'), 'utf8')) as {
    cases: {
        name: string;
        definition: unknown;
        size: ItemSize;
        values?: Record<string, unknown>;
        parts?: ResolvedPart[];
        error?: {code: string; path: string};
    }[];
};

describe('definition fixture', () => {
    it.each(fixture.cases.map((c) => [c.name, c] as const))('%s', (_, c) => {
        if (c.error !== undefined) {
            let error: unknown = null;
            try {
                evaluateDefinition(c.definition, c.size, c.values);
            } catch (e) {
                error = e;
            }
            expect(error).toBeInstanceOf(DefinitionError);
            const {code, path} = error as DefinitionError;
            expect({code, path}).toEqual(c.error);
            return;
        }
        expect(evaluateDefinition(c.definition, c.size, c.values)).toEqual(c.parts);
    });
});

describe('evaluateDefinition', () => {
    it('puts the path in the message', () => {
        const definition = {format: 1, parts: [{type: 'board', name: 'Top', x: 0, y: 0, z: 0, w: 'W', h: '0.4', d: 'D'}]};
        expect(() => evaluateDefinition(definition, {width: 800, depth: 300, height: 900})).toThrow('parts[0].h: "Top" gets a height of 0 mm.');
    });
});
