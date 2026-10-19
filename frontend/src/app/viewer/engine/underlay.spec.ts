import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {UnderlayPlacement} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {calibrateUnderlay, CalibrationPoint, pixelToPlan, placementFromPoints, planToPixel} from './underlay';

/** Shared with the backend twin (`UnderlayPlacementTest`). */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/underlay-placement.json'), 'utf8')) as {
    cases: {name: string; points: [CalibrationPoint, CalibrationPoint]; expected?: UnderlayPlacement | null; tolerance?: number}[];
};

describe('placementFromPoints (shared fixture)', () => {
    it.each(fixture.cases)('$name', (testCase) => {
        const [a, b] = testCase.points;
        const placement = placementFromPoints(a, b);
        // `expected: null` means the points are refused; no `expected` checks the mapping only.
        if ('expected' in testCase && testCase.expected === null) {
            expect(placement).toBeNull();
            return;
        }
        expect(placement).not.toBeNull();
        if (testCase.expected != null) {
            expect(placement).toEqual(testCase.expected);
        }
        expect(placement!.rotation).toBeGreaterThanOrEqual(0);
        expect(placement!.rotation).toBeLessThan(360);
        const tolerance = testCase.tolerance ?? 0.5;
        for (const point of testCase.points) {
            const plan = pixelToPlan(placement!, point.px, point.py);
            expect(Math.abs(plan.x - point.x)).toBeLessThanOrEqual(tolerance);
            expect(Math.abs(plan.y - point.y)).toBeLessThanOrEqual(tolerance);
        }
    });
});

describe('planToPixel', () => {
    it('inverts pixelToPlan', () => {
        const placement: UnderlayPlacement = {x: 120, y: 9000, scale: 7.43, rotation: 14.1};
        const plan = pixelToPlan(placement, 812, 433);
        const pixel = planToPixel(placement, plan);
        expect(pixel.px).toBeCloseTo(812, 6);
        expect(pixel.py).toBeCloseTo(433, 6);
    });
});

describe('calibrateUnderlay', () => {
    const placement: UnderlayPlacement = {x: 0, y: 10000, scale: 10, rotation: 0};

    it('rescales so the clicked points lie the given distance apart, keeping the first one', () => {
        // Clicked 1350 mm apart on the image; they are 1000 mm apart in reality.
        const calibrated = calibrateUnderlay(placement, {x: 2000, y: 5000}, {x: 3350, y: 5000}, 1000, null)!;
        expect(calibrated.scale).toBeCloseTo(10 / 1.35, 6);
        expect(pixelToPlan(calibrated, 200, 500).x).toBeCloseTo(2000, 0);
        expect(pixelToPlan(calibrated, 200, 500).y).toBeCloseTo(5000, 0);
        expect(pixelToPlan(calibrated, 335, 500).x).toBeCloseTo(3000, 0);
    });

    it('turns the image so the points run at the given angle', () => {
        // A wall drawn 14.1° off vertical on the scan should run straight north (90°).
        const angle = (14.1 * Math.PI) / 180;
        const b = {x: 1000 + 4000 * Math.sin(angle), y: 1000 + 4000 * Math.cos(angle)};
        const calibrated = calibrateUnderlay(placement, {x: 1000, y: 1000}, b, 4000, 90)!;
        expect(calibrated.rotation).toBeCloseTo(14.1, 4);
        expect(calibrated.scale).toBeCloseTo(10, 6);
    });
});
