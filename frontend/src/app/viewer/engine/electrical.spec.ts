import {ElectricalCategory, ElectricalSymbols} from '@app/models/electrical-schedule';
import {FurnitureDefinitionBody} from '@app/models/furniture';
import {Item, Scene} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildElectrical, electricalPoints, ElectricalSymbolSets, elevationText, symbolPolylines, SymbolSize} from './electrical';

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
        ],
        groups: [{id: 10, name: 'Hidden', variantSetId: 5}],
        variantSets: [{id: 5, name: 'Layout', activeGroupId: null}],
    }) as unknown as Scene;

describe('electrical points', () => {
    it('puts a wall point on its front edge facing out, a light at its centre', () => {
        const [wall, light] = electricalPoints(scene([item(1, 1, {rotation: 90}), item(2, 2, {z: 2500})]));
        // Turned 90° the front faces east.
        expect(wall.at.x).toBeCloseTo(1005);
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
