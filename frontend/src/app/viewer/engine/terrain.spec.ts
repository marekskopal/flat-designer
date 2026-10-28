import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {PlanPoint, Plot, SpotHeight} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {groundAt, plotTerrain, terrainAt, terrainContours, terrainOf, terrainRange, triangulate} from './terrain';

/** Shared with the backend twin (`TerrainTest`). */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/terrain.json'), 'utf8')) as {
    cases: {
        name: string;
        points: SpotHeight[];
        polygon: PlanPoint[];
        groundLevel: number;
        queries: PlanPoint[];
        expected: {vertices: number; triangles: number; heights: number[]};
    }[];
};

const plot = (polygon: PlanPoint[], terrain: SpotHeight[] = [], groundLevel = 0): Plot => ({
    id: 1,
    name: 'Plot',
    polygon,
    area: 0,
    groundMaterialId: null,
    groundLevel,
    terrain,
});

describe('terrain (shared fixture)', () => {
    for (const c of fixture.cases) {
        it(c.name, () => {
            const terrain = terrainOf(c.points, c.polygon, c.groundLevel);
            expect(terrain.vertices.length).toBe(c.expected.vertices);
            expect(terrain.triangles.length).toBe(c.expected.triangles);
            c.queries.forEach(([x, y], i) => expect(terrainAt(terrain, x, y)).toBeCloseTo(c.expected.heights[i], 2));
        });
    }
});

describe('triangulate', () => {
    it('gives counter-clockwise triangles covering the hull', () => {
        const points = [
            [0, 0],
            [4000, 0],
            [4000, 3000],
            [0, 3000],
            [1500, 1000],
        ];
        const triangles = triangulate(points);
        expect(triangles.length).toBe(4);
        for (const [i, j, k] of triangles) {
            const [a, b, c] = [points[i], points[j], points[k]];
            expect((b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0])).toBeGreaterThan(0);
        }
    });

    it('adds no triangles for collinear or repeated points', () => {
        expect(
            triangulate([
                [0, 0],
                [1000, 0],
                [2000, 0],
            ]),
        ).toEqual([]);
        expect(
            triangulate([
                [0, 0],
                [1000, 0],
                [0, 1000],
                [0, 0],
            ]).length,
        ).toBe(1);
    });
});

describe('plots', () => {
    const boundary: PlanPoint[] = [
        [0, 0],
        [10000, 0],
        [10000, 10000],
        [0, 10000],
    ];

    it('caches the terrain per plot object', () => {
        const p = plot(boundary, [[5000, 5000, 900]]);
        expect(plotTerrain(p)).toBe(plotTerrain(p));
        expect(plotTerrain({...p})).not.toBe(plotTerrain(p));
    });

    it('takes the ground from the plot under the point, else the floor level', () => {
        const slope = plot(boundary, [
            [0, 0, 0],
            [10000, 0, 1000],
            [0, 10000, 0],
        ]);
        expect(groundAt([slope], 5000, 2000)).toBeCloseTo(500);
        expect(groundAt([slope], 20000, 2000)).toBe(0);
        expect(groundAt([plot(boundary, [], -400)], 100, 100)).toBe(-400);
    });

    it('gives the range of the ground', () => {
        expect(terrainRange(plotTerrain(plot(boundary, [], -250)))).toEqual([-250, -250]);
        expect(
            terrainRange(
                plotTerrain(
                    plot(boundary, [
                        [2000, 2000, -300],
                        [8000, 8000, 1200],
                    ]),
                ),
            ),
        ).toEqual([-300, 1200]);
    });
});

describe('terrainContours', () => {
    it('cuts a plane into straight contour lines', () => {
        const terrain = terrainOf(
            [
                [0, 0, 0],
                [10000, 0, 1000],
                [10000, 10000, 1000],
                [0, 10000, 0],
            ],
            [],
            0,
        );
        const contours = terrainContours(terrain, 500);
        expect(contours.map((c) => c.level)).toEqual([0, 500]);
        const middle = contours.find((c) => c.level === 500)!;
        for (const segment of middle.segments) {
            for (const [x] of segment) {
                expect(x).toBeCloseTo(5000, 0);
            }
        }
    });
});
