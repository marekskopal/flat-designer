import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {describe, expect, it} from 'vitest';

import {ExpressionError, parseExpression, Value, Variables} from './expression';

/** The fixture shared with the PHP twin (backend/tests/Service/Furniture/ExpressionTest.php); vitest runs in frontend/. */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/furniture-expressions.json'), 'utf8')) as {
    variables: Variables;
    cases: {expression: string; result?: Value; error?: string}[];
};

/** The value with every number matched to 9 decimals (lists and records compared item by item). */
function approximately(value: Value): unknown {
    if (typeof value === 'number') {
        return expect.closeTo(value, 9);
    }
    if (Array.isArray(value)) {
        return value.map(approximately);
    }
    if (typeof value === 'object' && value !== null) {
        return Object.fromEntries(Object.entries(value).map(([key, field]) => [key, approximately(field)]));
    }
    return value;
}

function errorCode(fn: () => unknown): string | null {
    try {
        fn();
        return null;
    } catch (e) {
        if (e instanceof ExpressionError) {
            return e.code;
        }
        throw e;
    }
}

describe('expression fixture', () => {
    it.each(fixture.cases.map((c, i) => [`#${i} ${c.expression.slice(0, 40)}`, c] as const))('%s', (_, c) => {
        if (c.error !== undefined) {
            expect(errorCode(() => parseExpression(c.expression).evaluate(fixture.variables))).toBe(c.error);
            return;
        }
        const value = parseExpression(c.expression).evaluate(fixture.variables);
        if (typeof c.result === 'number') {
            expect(typeof value).toBe('number');
            expect(value).toBeCloseTo(c.result, 9);
        } else {
            expect(value).toEqual(approximately(c.result ?? null));
        }
    });
});

describe('parseExpression', () => {
    it('can be evaluated again with other variables', () => {
        const expression = parseExpression('W / 2 - t');
        expect(expression.evaluate({W: 800, t: 18})).toBe(382);
        expect(expression.evaluate({W: 600, t: 18})).toBe(282);
    });

    it('names the position of a syntax error', () => {
        expect(() => parseExpression('W +* 2')).toThrow('Unexpected "*" at 3.');
    });

    it('treats a non-breaking space as an error, like the PHP twin', () => {
        expect(() => parseExpression('W -\u00a02')).toThrow('Unexpected "\u00a0" at 3.');
    });

    it('does not read inherited properties as variables', () => {
        expect(errorCode(() => parseExpression('constructor').evaluate({}))).toBe('unknownVariable');
        expect(errorCode(() => parseExpression('toString(1)'))).toBe('unknownFunction');
    });
});
