import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {PlanPoint} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {WallLine} from './plan-geometry';
import {detectRoom, RoomDetectError} from './room-detect';

/** Shared with the backend twin (`RoomDetectorTest`). */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/room-detection.json'), 'utf8')) as {
    layouts: Record<string, WallLine[]>;
    cases: {name: string; layout: string; point: PlanPoint; polygon?: PlanPoint[]; error?: RoomDetectError}[];
};

describe('detectRoom (shared fixture)', () => {
    it.each(fixture.cases)('$name', (testCase) => {
        const [x, y] = testCase.point;
        const expected = testCase.error === undefined ? {polygon: testCase.polygon} : {error: testCase.error};
        expect(detectRoom(fixture.layouts[testCase.layout], {x, y})).toEqual(expected);
    });
});
