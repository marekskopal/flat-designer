import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {ElectricalCategory, ElectricalSymbols} from '@app/models/electrical-schedule';
import {FurnitureDefinitionBody} from '@app/models/furniture';
import {Item, Scene} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {
    buildElectrical,
    electricalPoints,
    ElectricalSymbolSets,
    elevationText,
    hasElectricalPoints,
    linkArcs,
    scheduleUnits,
    switchLinks,
    symbolPolylines,
    SymbolSize,
} from './electrical';

const definition = (category: ElectricalCategory | undefined, extra: Partial<FurnitureDefinitionBody> = {}): FurnitureDefinitionBody => ({
    format: 1,
    ...(category === undefined ? {} : {category}),
    parts: [],
    ...extra,
});

const socket = definition('socket', {schedule: {count: 'gangs'}, parameters: [{key: 'gangs', type: 'int', default: 1}]});

const item = (id: number, definitionId: number, fields: Partial<Item> = {}): Item =>
    ({
        id,
        groupId: null,
        roomId: null,
        kind: 'Custom',
        definitionId,
        name: '',
        x: 1000,
        y: 2000,
        z: 300,
        width: 80,
        depth: 10,
        height: 80,
        rotation: 0,
        materialId: null,
        params: {},
        ...fields,
    }) as Item;

const scene = (items: Item[]): Scene =>
    ({
        items,
        definitions: [
            {id: 1, name: 'Socket', definition: socket, updatedAt: ''},
            {id: 2, name: 'Downlight', definition: definition('light'), updatedAt: ''},
            {id: 3, name: 'Shelf', definition: definition(undefined), updatedAt: ''},
            {id: 4, name: 'Switch', definition: definition('switch'), updatedAt: ''},
        ],
        groups: [{id: 10, name: 'Hidden', variantSetId: 5}],
        variantSets: [{id: 5, name: 'Layout', activeGroupId: null}],
    }) as unknown as Scene;

describe('electrical points', () => {
    it('puts a wall point on its back edge (the wall face) facing out, a light at its centre', () => {
        const [wall, light] = electricalPoints(scene([item(1, 1, {rotation: 90}), item(2, 2, {z: 2500})]));
        // Turned 90° the front faces east, so the back (on the wall) is 5 mm west of the centre.
        expect(wall.at.x).toBeCloseTo(995);
        expect(wall.at.y).toBeCloseTo(2000);
        expect(wall.out.x).toBeCloseTo(1);
        expect(wall.out.y).toBeCloseTo(0);
        expect(light.category).toBe('light');
        expect(light.at).toEqual({x: 1000, y: 2000});
        expect(elevationText(wall)).toBe('+0.300');
    });

    it('counts by the schedule settings, the default filled in', () => {
        const points = electricalPoints(scene([item(1, 1), item(2, 1, {params: {values: {gangs: 3}}}), item(3, 2)]));
        expect(points.map((p) => p.units)).toEqual([1, 3, 1]);
    });

    it('counts an older socket without settings by its gangs', () => {
        const older = {...scene([item(1, 1, {params: {values: {gangs: 2}}})])};
        older.definitions = [{id: 1, name: 'Socket', definition: {...socket, schedule: undefined}, updatedAt: ''}];
        expect(electricalPoints(older)[0].units).toBe(2);
        older.definitions[0].definition = {...socket, schedule: {}};
        expect(electricalPoints(older)[0].units).toBe(1);
    });

    it('leaves out untagged definitions, other kinds and hidden variants', () => {
        const items = [item(1, 3), item(2, 1, {kind: 'Model' as Item['kind']}), item(3, 1, {groupId: 10}), item(4, 1)];
        expect(electricalPoints(scene(items)).map((p) => p.itemId)).toEqual([4]);
        expect(electricalPoints({items: [item(1, 1)]} as unknown as Scene)).toEqual([]);
    });

    it('counts an LED strip as a light a switch may control, at its centre', () => {
        const strip = item(5, 0, {kind: 'LedStrip' as Item['kind'], definitionId: null, z: 2300, rotation: 90});
        const hidden = item(6, 0, {kind: 'LedStrip' as Item['kind'], definitionId: null, groupId: 10});
        const switched = item(4, 4, {controls: [5, 6]});
        const points = electricalPoints(scene([strip, hidden, switched]));
        expect(points.map((p) => [p.itemId, p.category, p.units])).toEqual([[5, 'light', 1], [4, 'switch', 1]]);
        expect(points[0].at).toEqual({x: 1000, y: 2000});
        expect(switchLinks(points)).toEqual([{switchId: 4, lightIds: [5]}]);
        expect(hasElectricalPoints({items: [strip]} as unknown as Scene)).toBe(true);
    });

    it('tells whether the floor shows any point, as the points do', () => {
        const cases = [[], [item(1, 3)], [item(2, 1, {kind: 'Model' as Item['kind']})], [item(3, 1, {groupId: 10})], [item(1, 3), item(4, 2)]];
        for (const items of cases) {
            expect(hasElectricalPoints(scene(items))).toBe(electricalPoints(scene(items)).length > 0);
        }
        expect(hasElectricalPoints(scene([item(4, 2)]))).toBe(true);
        expect(hasElectricalPoints({items: [item(1, 1)]} as unknown as Scene)).toBe(false);
    });
});

describe('electrical symbols', () => {
    const point = electricalPoints(scene([item(1, 1)]))[0];
    const categories: ElectricalCategory[] = ['light', 'socket', 'switch', 'data', 'plumbing'];

    it('draws every category in every set', () => {
        for (const symbols of Object.keys(ElectricalSymbolSets) as ElectricalSymbols[]) {
            for (const category of categories) {
                const paths = ElectricalSymbolSets[symbols][category]({...point, category});
                expect(paths.length, `${symbols} ${category}`).toBeGreaterThan(0);
                expect(paths.every((p) => p.points.length >= 2)).toBe(true);
            }
        }
    });

    it('marks one to four gangs', () => {
        for (const symbols of ['Iec', 'Csn'] as ElectricalSymbols[]) {
            const counts = [1, 2, 3, 4, 6].map((units) => ElectricalSymbolSets[symbols].socket({...point, units}).length);
            expect(counts[1] - counts[0]).toBe(1);
            expect(counts[3] - counts[0]).toBe(3);
            expect(counts[4]).toBe(counts[3]);
        }
    });

    it('draws a double switch with two strokes', () => {
        const single = ElectricalSymbolSets.Iec.switch({...point, category: 'switch', values: {rockers: 'single'}});
        const double = ElectricalSymbolSets.Iec.switch({...point, category: 'switch', values: {rockers: 'double'}});
        expect(double.length).toBe(single.length + 2);
    });

    it('turns the symbol out of the wall', () => {
        // Facing south: the socket reaches from the wall face southwards, half a unit for one gang.
        const ys = symbolPolylines(point, 'Iec').flatMap((p) => p.points.map((q) => q.y));
        expect(Math.max(...ys)).toBeCloseTo(point.at.y);
        expect(Math.min(...ys)).toBeCloseTo(point.at.y - SymbolSize / 2);
    });

    it('draws the switch stroke to the right of one facing the wall from the room', () => {
        // Facing south out of a north wall, one in the room looks north: their right is east.
        const xs = symbolPolylines({...point, category: 'switch', values: {rockers: 'single'}}, 'Iec').flatMap((p) => p.points.map((q) => q.x));
        expect(Math.max(...xs)).toBeGreaterThan(point.at.x + 0.3 * SymbolSize);
        expect(Math.min(...xs)).toBeGreaterThan(point.at.x - 0.2 * SymbolSize);
    });

    it('builds lines per category and an elevation label for wall points only', () => {
        const points = electricalPoints(scene([item(1, 1), item(2, 2)]));
        const group = buildElectrical(points, 'Csn');
        expect(group.children.filter((c) => c instanceof THREE.LineSegments)).toHaveLength(2);
        expect(group.children.filter((c) => c.type === 'Object3D' && 'element' in c)).toHaveLength(1);
    });
});

describe('switch links', () => {
    // A two-way pair at the hall's ends (on the south wall, facing north) and three lights along it.
    const hall = (controls: [number[], number[]]): Scene =>
        scene([
            item(1, 4, {x: 0, y: 0, rotation: 180, controls: controls[0]}),
            item(2, 4, {x: 6000, y: 0, rotation: 180, controls: controls[1]}),
            item(11, 2, {x: 1000, y: 1500}),
            item(12, 2, {x: 3000, y: 1500}),
            item(13, 2, {x: 5000, y: 1500}),
            item(14, 1, {x: 3000, y: 3000}),
            item(15, 2, {x: 9000, y: 9000, groupId: 10}),
        ]);

    it('keeps the shown lights of each switch', () => {
        // A socket, a hidden light, a deleted one (not in the scene) and the switch itself drop out.
        const points = electricalPoints(hall([[13, 11, 14, 15, 99, 1, 11], []]));
        expect(switchLinks(points)).toEqual([{switchId: 1, lightIds: [11, 13]}]);
        expect(points.find((p) => p.itemId === 11)?.controls).toEqual([]);
    });

    it('chains the lights once and gives each switch an arc to its nearest light', () => {
        const points = electricalPoints(
            hall([
                [11, 12, 13],
                [11, 12, 13],
            ]),
        );
        const arcs = linkArcs(points, switchLinks(points), 'Iec');
        expect(arcs.map((a) => [a.from, a.to])).toEqual([
            [11, 12],
            [12, 13],
            [1, 11],
            [2, 13],
        ]);
    });

    it('runs between the symbols\' circles, bulging left', () => {
        const points = electricalPoints(hall([[11], []]));
        const [arc] = linkArcs(points, switchLinks(points), 'Iec');
        const first = arc.points[0];
        const last = arc.points[arc.points.length - 1];
        // The switch's circle is 0.25 units out of the wall face (5 mm behind its centre, facing north), the light's 0.4
        // units round its centre.
        const head = {x: 0, y: -5 + 0.25 * SymbolSize};
        expect(Math.hypot(first.x - head.x, first.y - head.y)).toBeCloseTo(0.1 * SymbolSize);
        expect(Math.hypot(last.x - 1000, last.y - 1500)).toBeCloseTo(0.4 * SymbolSize);
        // Left of a north-east chord is north-west of its middle.
        const middle = arc.points[(arc.points.length - 1) / 2];
        const chordMiddle = {x: (first.x + last.x) / 2, y: (first.y + last.y) / 2};
        const cross = (last.x - first.x) * (middle.y - chordMiddle.y) - (last.y - first.y) * (middle.x - chordMiddle.x);
        expect(cross).toBeGreaterThan(0);
    });

    it('draws the arcs dashed, dimming those away from the highlighted items', () => {
        const points = electricalPoints(hall([[11, 12], [13]]));
        const arcs = linkArcs(points, switchLinks(points), 'Iec');
        const dashed = (group: THREE.Group): THREE.LineSegments[] =>
            group.children.filter((c): c is THREE.LineSegments => c instanceof THREE.LineSegments && c.material instanceof THREE.LineDashedMaterial);
        expect(dashed(buildElectrical(points, 'Iec', {arcs}))).toHaveLength(1);
        const lit = dashed(buildElectrical(points, 'Iec', {arcs, highlight: new Set([2])}));
        expect(lit.map((l) => (l.material as THREE.LineDashedMaterial).opacity)).toEqual([1, 0.3]);
    });
});

/** Shared with the backend twin (`DefinitionScheduleUnitsTest`): what an item counts as. */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/electrical-units.json'), 'utf8')) as {
    cases: {name: string; definition: Omit<FurnitureDefinitionBody, 'format' | 'parts'>; given: Record<string, unknown>; units: number}[];
};

describe('scheduleUnits (shared fixture)', () => {
    it.each(fixture.cases)('$name', (testCase) => {
        expect(scheduleUnits({format: 1, parts: [], ...testCase.definition}, testCase.given)).toBe(testCase.units);
    });
});
