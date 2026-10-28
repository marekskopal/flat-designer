import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {describe, expect, it} from 'vitest';

import {polygonSignedArea, Vec2} from './plan-geometry';
import {
    roofPlaneAt,
    roofPlanes,
    roofRidgeHeight,
    RoofShapeFields,
    roofsUndersideAt,
    roofTopAt,
    roofUndersideAt,
    roofWindowCorners,
    roofWindowFits,
    RoofWindowPlace,
} from './roof-geometry';
import {roofPlaneParts, roomUnderRoofs} from './under-roof';

interface Probe {
    x: number;
    y: number;
    underside: number | null;
    top: number | null;
    plane: number | null;
}

interface Window extends RoofWindowPlace {
    plane: number | null;
    corners?: Vec2[];
    fits: boolean;
}

/** Shared with the backend twin (`RoofGeometryTest`). */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/roof-geometry.json'), 'utf8')) as {
    cases: {
        name: string;
        roof: RoofShapeFields;
        expected: {ridgeHeight: number; planes: {polygon: Vec2[]; gradient: Vec2; height: number}[]; probes: Probe[]; windows?: Window[]};
    }[];
    junctions: {name: string; roofs: RoofShapeFields[]; areas: number[][]}[];
    rooms: {
        name: string;
        roofs: RoofShapeFields[];
        room: {polygon: [number, number][]; ceilingHeight: number; floorElevation: number};
        expected: {minHeight: number; maxHeight: number; volume: number; headroomArea: number; ceilingArea: number};
    }[];
};

const Tolerance = 0.01;

function expectClose(actual: number | null, expected: number | null, tolerance = Tolerance): void {
    if (expected === null) {
        expect(actual).toBeNull();
        return;
    }
    expect(actual).not.toBeNull();
    expect(Math.abs(actual! - expected)).toBeLessThanOrEqual(tolerance);
}

describe('roof geometry (shared fixture)', () => {
    it.each(fixture.cases)('$name', ({roof, expected}) => {
        expectClose(roofRidgeHeight(roof), expected.ridgeHeight);

        const planes = roofPlanes(roof);
        expect(planes.length).toBe(expected.planes.length);
        expected.planes.forEach((plane, i) => {
            expect(planes[i].polygon.length).toBe(plane.polygon.length);
            plane.polygon.forEach((point, j) => {
                expectClose(planes[i].polygon[j].x, point.x);
                expectClose(planes[i].polygon[j].y, point.y);
            });
            expectClose(planes[i].gradient.x, plane.gradient.x, 1e-5);
            expectClose(planes[i].gradient.y, plane.gradient.y, 1e-5);
            expectClose(planes[i].height, plane.height);
        });

        for (const probe of expected.probes) {
            expectClose(roofUndersideAt(roof, probe.x, probe.y), probe.underside);
            expectClose(roofTopAt(roof, probe.x, probe.y), probe.top);
            expect(roofPlaneAt(roof, probe.x, probe.y)).toBe(probe.plane);
        }

        for (const window of expected.windows ?? []) {
            const placed = roofWindowCorners(roof, window);
            expect(placed?.plane ?? null).toBe(window.plane);
            (window.corners ?? []).forEach((corner, i) => {
                expectClose(placed?.corners[i].x ?? null, corner.x);
                expectClose(placed?.corners[i].y ?? null, corner.y);
            });
            expect(roofWindowFits(roof, window)).toBe(window.fits);
        }
    });
});

describe('roof junctions (shared fixture)', () => {
    it.each(fixture.junctions)('$name', ({roofs, areas}) => {
        roofs.forEach((roof, r) => {
            const parts = roofPlaneParts(roof, roofs);
            areas[r].forEach((area, k) => {
                const mm2 = parts[k].reduce(
                    (sum, polygon) => sum + polygon.reduce((ring, points) => ring + polygonSignedArea(points.slice(0, -1) as [number, number][]), 0),
                    0,
                );
                expectClose(mm2 / 1e6, area, 1e-3);
            });
        });
    });
});

describe('rooms under roofs (shared fixture)', () => {
    it.each(fixture.rooms)('$name', ({roofs, room, expected}) => {
        const measured = roomUnderRoofs({...room, vaultSpacing: null}, roofs)!;
        expectClose(measured.minHeight, expected.minHeight);
        expectClose(measured.maxHeight, expected.maxHeight);
        expectClose(measured.volume / 1e9, expected.volume, 1e-3);
        expectClose(measured.headroomArea / 1e6, expected.headroomArea, 1e-3);
        expectClose(measured.ceilingArea / 1e6, expected.ceilingArea, 1e-3);
        expect(roomUnderRoofs({...room, vaultSpacing: 1000}, roofs)).toBeNull();
        expect(roomUnderRoofs({...room, ceilingHeight: null, vaultSpacing: null}, roofs)).toBeNull();
        expect(roomUnderRoofs({...room, vaultSpacing: null}, [])).toBeNull();
    });
});

describe('roofsUndersideAt', () => {
    const gable = (fields: Partial<RoofShapeFields>): RoofShapeFields => ({
        shape: 'Gable',
        x: 0,
        y: 0,
        rotation: 0,
        width: 10000,
        depth: 8000,
        eaveHeight: 2600,
        pitch: 45,
        overhang: 0,
        vergeOverhang: 0,
        thickness: 250,
        ...fields,
    });

    it('takes the highest roof where roofs meet', () => {
        const main = gable({});
        const wing = gable({y: -6000, rotation: 90, width: 6000, depth: 4000});
        // At (0, −3500) the main roof's front plane is 500 mm up its slope; the wing's ridge passes there.
        expectClose(roofsUndersideAt([main, wing], 0, -3500), 4600);
        expectClose(roofsUndersideAt([main], 0, -3500), 3100);
        expect(roofsUndersideAt([main, wing], 9000, 9000)).toBeNull();
    });
});
