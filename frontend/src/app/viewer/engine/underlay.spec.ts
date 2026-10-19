import {UnderlayPlacement} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {calibrateUnderlay, pixelToPlan, placementFromPoints, planToPixel} from './underlay';

describe('placementFromPoints', () => {
    it('puts a horizontal scale bar on the plan axis (as UnderlayPlacementTest does)', () => {
        const placement = placementFromPoints({px: 100, py: 500, x: 0, y: 0}, {px: 200, py: 500, x: 1000, y: 0});
        expect(placement).toEqual({x: -1000, y: 5000, scale: 10, rotation: 0});
    });

    it('maps both points of a rotated plan back onto their plan positions', () => {
        const a = {px: 300, py: 120, x: 2500, y: 4000};
        const b = {px: 420, py: 410, x: 6100, y: 3100};
        const placement = placementFromPoints(a, b)!;
        for (const point of [a, b]) {
            const plan = pixelToPlan(placement, point.px, point.py);
            expect(plan.x).toBeCloseTo(point.x, -0.5);
            expect(plan.y).toBeCloseTo(point.y, -0.5);
        }
        expect(placement.rotation).toBeGreaterThanOrEqual(0);
        expect(placement.rotation).toBeLessThan(360);
    });

    it('refuses points that coincide', () => {
        expect(placementFromPoints({px: 10, py: 10, x: 0, y: 0}, {px: 10, py: 10, x: 1000, y: 0})).toBeNull();
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
