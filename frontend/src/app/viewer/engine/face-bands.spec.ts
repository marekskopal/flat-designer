import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {WallFinish} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {faceBandsAt, faceBandStretches} from './face-bands';

const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/face-bands.json'), 'utf8')) as {
    cases: {name: string; bands: WallFinish[] | null; from: number; to: number; stretches: {from: number; to: number; bands: number[] | null}[]}[];
};

describe('faceBandStretches (shared fixture with FaceBands)', () => {
    for (const c of fixture.cases) {
        it(c.name, () => {
            const stretches = faceBandStretches(c.bands, c.from, c.to).map((s) => ({
                from: s.from,
                to: s.to,
                bands: s.bands === null ? null : s.bands.map((band) => (c.bands ?? []).indexOf(band)),
            }));
            expect(stretches).toEqual(c.stretches);
        });
    }
});

describe('faceBandsAt', () => {
    const tiles = {materialId: 1, bottom: 900, top: 1500, from: 1000, to: 2000};

    it('leaves the face to the room outside its stretched bands', () => {
        expect(faceBandsAt([tiles], 1500)).toEqual([tiles]);
        expect(faceBandsAt([tiles], 2500)).toBeNull();
        expect(faceBandsAt([], 2500)).toEqual([]);
        expect(faceBandsAt(null, 2500)).toBeNull();
    });
});
