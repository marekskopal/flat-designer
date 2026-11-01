import {FurnitureDefinitionBody} from '@app/models/furniture';
import {Item, Material} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from '../materials';
import {mergeStatic} from '../merge';
import {buildCustomItemBody, definitionLibrary} from './custom';
import {ResolvedPart} from './definition';
import {
    advance,
    foldPanels,
    frontObjects,
    FrontSpec,
    frontSpec,
    frontSpecOf,
    FrontState,
    frontState,
    FrontStates,
    pivotPoint,
    pivotPose,
    setFrontPose,
} from './fronts';

const library = (): MaterialLibrary =>
    new MaterialLibrary(
        [
            {
                id: 1,
                code: 'kitchen-oak',
                name: 'oak',
                pattern: 'Plain',
                color: '#b08850',
                roughness: 0.5,
                metalness: 0,
                texture: null,
                tileWidth: null,
                tileHeight: null,
            } as Material,
        ],
        1,
    );

const part = (props: Record<string, unknown>, box: Partial<ResolvedPart> = {}): ResolvedPart => ({
    name: 'Front',
    type: 'front',
    index: null,
    x: 0,
    y: 100,
    z: 540,
    w: 600,
    h: 700,
    d: 20,
    material: null,
    props,
    ...box,
});

const item = (definition: FurnitureDefinitionBody): {body: THREE.Group; item: Item} => {
    const size = {width: 600, depth: 560, height: 800};
    const cupboard: Item = {
        id: 7,
        roomId: null,
        groupId: null,
        kind: 'Custom',
        definitionId: 1,
        name: 'Cabinet',
        x: 0,
        y: 0,
        z: 0,
        rotation: 0,
        materialId: null,
        params: {},
        ...size,
    };
    const body = buildCustomItemBody(cupboard, library(), definitionLibrary([{id: 1, name: 'Cabinet', definition, updatedAt: 'x'}])) as THREE.Group;
    return {body, item: cupboard};
};

const cabinet = (front: Record<string, unknown>): FurnitureDefinitionBody =>
    ({
        format: 1,
        size: {width: 600, depth: 560, height: 800},
        parts: [
            {type: 'board', name: 'Side', repeat: {count: 2}, x: 'i * (W - 18)', y: 0, z: 0, w: 18, h: 'H', d: 'D - 20'},
            {type: 'front', name: 'Front', x: 0, y: 100, z: 'D - 20', w: 'W', h: 700, d: 20, ...front},
            {
                type: 'assembly',
                name: 'Drawer',
                x: 18,
                y: 0,
                z: 0,
                w: 'W - 36',
                h: 100,
                d: 'D',
                opens: 'slide',
                travel: 300,
                parts: [
                    {type: 'front', name: 'Drawer front', x: -18, y: 0, z: 'AD - 20', w: 'AW + 36', h: 98, d: 20, kind: 'drawer'},
                    {type: 'board', name: 'Drawer side', x: 0, y: 10, z: 20, w: 12, h: 80, d: 'AD - 60'},
                ],
            },
        ],
    }) as unknown as FurnitureDefinitionBody;

const box = (object: THREE.Object3D): number[] => {
    object.updateMatrixWorld(true);
    return [...new THREE.Box3().setFromObject(object).min.toArray(), ...new THREE.Box3().setFromObject(object).max.toArray()].map((v) =>
        Math.round(v * 1000),
    );
};

/** A point given in the parts frame (mm) of the pivot's content, in the parts frame after the pose. */
const posed = (spec: FrontSpec, pivot: 'main' | 'upper' | 'lower', t: number, point: THREE.Vector3): THREE.Vector3 => {
    const pose = pivotPose(spec, pivot, t);
    const at = pivotPoint(spec, pivot);
    return point.clone().sub(at).applyEuler(pose.rotation).add(at).add(pose.offset);
};

describe('frontSpec', () => {
    it('swings a door on the side away from its handle and slides a drawer out', () => {
        expect(frontSpec(part({kind: 'door'}), 0)).toMatchObject({key: 'front:0', motion: 'swing', hinge: 'left', angle: 105, softClose: false});
        expect(frontSpec(part({kind: 'door', handleSide: 'left'}), 1)?.hinge).toBe('right');
        expect(frontSpec(part({kind: 'door', handleSide: 'left', hinge: 'left'}), 1)?.hinge).toBe('left');
        expect(frontSpec(part({kind: 'drawer', softClose: true}), 0)).toMatchObject({motion: 'slide', travel: 420, softClose: true});
        expect(frontSpec(part({opens: 'none'}), 0)).toBeNull();
        expect(frontSpec(part({}, {type: 'board'}), 0)).toBeNull();
    });

    it('moves a part of an opening assembly with it', () => {
        const leaf = {key: 'parts[2]#1', box: [18, 0, 0, 564, 100, 560], opens: 'slide', travel: 300, softClose: true};
        expect(frontSpec(part({leaf}, {type: 'board'}), 4)).toMatchObject({key: 'parts[2]#1', motion: 'slide', travel: 300, box: {x: 18, d: 560}});
    });

    it('folds a flap at most half way, as the cut list cuts it', () => {
        expect(foldPanels(700, null)).toEqual([349, 349]);
        expect(foldPanels(700, 250)).toEqual([249, 449]);
        expect(foldPanels(700, 600)).toEqual([349, 349]);
        // Too low to cut in two (as the cut list keeps it whole): it lifts whole.
        expect(foldPanels(5, null)).toBeNull();
        expect(frontSpec(part({opens: 'fold'}, {h: 5}), 0)).toMatchObject({motion: 'lift', fold: null});
        expect(frontSpec(part({opens: 'fold', foldAt: 250}), 0)?.fold).toEqual([249, 449]);
    });
});

describe('poses', () => {
    it('swings the free edge out', () => {
        const left = frontSpec(part({kind: 'door'}), 0) as FrontSpec;
        const edge = posed(left, 'main', 1, new THREE.Vector3(600, 400, 540));
        expect(edge.z).toBeGreaterThan(540 + 500);
        const right = frontSpec(part({kind: 'door', hinge: 'right'}), 0) as FrontSpec;
        expect(posed(right, 'main', 0.5, new THREE.Vector3(0, 400, 540)).z).toBeGreaterThan(900);
    });

    it('lifts a flap up and out about its top edge', () => {
        const spec = frontSpec(part({opens: 'lift', angle: 90}), 0) as FrontSpec;
        const bottom = posed(spec, 'main', 1, new THREE.Vector3(0, 100, 540));
        expect(bottom.y).toBeCloseTo(800);
        expect(bottom.z).toBeCloseTo(540 + 700);
    });

    it('raises a parallel flap upright by its travel, out on the way', () => {
        const spec = frontSpec(part({opens: 'parallel'}), 0) as FrontSpec;
        expect(spec.travel).toBe(710);
        expect(pivotPose(spec, 'main', 1).offset.y).toBeCloseTo(710);
        expect(pivotPose(spec, 'main', 1).offset.z).toBeCloseTo(0);
        expect(pivotPose(spec, 'main', 0.5).offset.z).toBeGreaterThan(100);
        expect(pivotPose(spec, 'main', 0.5).rotation.x).toBe(0);
    });

    it('runs a bi-fold flap\'s lower edge up its back face and folds the panels back to back', () => {
        const spec = frontSpec(part({opens: 'fold'}), 0) as FrontSpec;
        for (const t of [0, 0.25, 0.5, 0.75, 1]) {
            const knuckle = posed(spec, 'upper', t, new THREE.Vector3(0, 100 + 700 - 349, 540));
            const lowerTop = posed(spec, 'lower', t, new THREE.Vector3(0, 100 + 700 - 349, 540));
            const bottom = posed(spec, 'lower', t, new THREE.Vector3(0, 100, 540));
            expect(lowerTop.distanceTo(knuckle)).toBeCloseTo(0, 6);
            expect(bottom.z).toBeCloseTo(540, 6);
        }
        const open = posed(spec, 'lower', 1, new THREE.Vector3(0, 100, 540));
        // Folded up to the top, the lower panel (the joint longer) hanging a little below it.
        expect(open.y).toBeCloseTo(800 - Math.sqrt(351 ** 2 - 349 ** 2), 6);
        expect(posed(spec, 'upper', 1, new THREE.Vector3(0, 451, 540)).z).toBeCloseTo(540 + 349);
    });
});

describe('the builder', () => {
    it('draws closed fronts where the parts are, each in its own tagged group', () => {
        const {body: fixed} = item(cabinet({opens: 'none'}));
        const {body} = item(cabinet({kind: 'door'}));
        expect(box(body)).toEqual(box(fixed));
        const fronts = frontObjects(body);
        expect(fronts.map((f) => frontSpecOf(f).key)).toEqual(['front:0', 'parts[2]']);
        // The drawer box moves with its front.
        const drawer = fronts[1];
        const names: string[] = [];
        drawer.traverse((o) => names.push(String(o.userData['partName'] ?? '')));
        expect(names).toEqual(expect.arrayContaining(['Drawer front', 'Drawer side']));

        mergeStatic(body);
        expect(frontObjects(body)).toHaveLength(2);
        setFrontPose(frontObjects(body)[1], 1);
        expect(box(frontObjects(body)[1])[5]).toBe(box(fixed)[5] + 300);
    });

    it('draws a bi-fold flap as two panels', () => {
        const {body} = item(cabinet({opens: 'fold'}));
        const flap = frontObjects(body)[0];
        expect(flap.children.map((c) => c.userData['frontPivot'])).toEqual(['upper', 'lower']);
        const [minX, minY, minZ, maxX, maxY] = box(flap);
        expect([minX, minY, minZ, maxX, maxY]).toEqual([-300, 100, 260, 300, 800]);
    });
});

describe('FrontStates', () => {
    const drawer = frontSpec(part({kind: 'drawer', travel: 400}), 0) as FrontSpec;
    const soft = {...drawer, softClose: true};

    const run = (state: FrontState, seconds: number): number[] => {
        const trace: number[] = [];
        for (let t = 0; t < seconds; t += 1 / 60) {
            advance(state, 1 / 60);
            trace.push(state.position);
        }
        return trace;
    };

    it('opens in about half a second, speeding up and slowing to a stop', () => {
        const trace = run(frontState(0, 1, drawer), 1);
        const done = trace.findIndex((p) => p === 1);
        expect(done / 60).toBeGreaterThan(0.4);
        expect(done / 60).toBeLessThan(0.65);
        expect(trace[done - 1] - trace[done - 2]).toBeLessThan(trace[Math.floor(done / 2)] - trace[Math.floor(done / 2) - 1]);
    });

    it('closes softly: slowing over the last 50 mm, never bouncing', () => {
        const trace = run(frontState(1, 0, soft), 2);
        const done = trace.findIndex((p) => p === 0);
        expect(trace.every((p, i) => i === 0 || p <= trace[i - 1])).toBe(true);
        // The last 10 mm take a good while.
        const lastTen = trace.findIndex((p) => p < 10 / 400);
        expect((done - lastTen) / 60).toBeGreaterThan(0.15);
        expect(trace.slice(done).every((p) => p === 0)).toBe(true);
    });

    it('bangs shut with a small rebound without it', () => {
        const trace = run(frontState(1, 0, drawer), 1.5);
        const first = trace.findIndex((p) => p === 0);
        const rebound = Math.max(...trace.slice(first));
        expect(rebound).toBeGreaterThan(0);
        expect(rebound * 400).toBeLessThan(10);
        expect(trace[trace.length - 1]).toBe(0);
    });

    it('turns back mid-way without a jump', () => {
        const state = frontState(0, 1, drawer);
        run(state, 0.2);
        const before = state.position;
        state.target = 0;
        advance(state, 1 / 60);
        expect(Math.abs(state.position - before)).toBeLessThan(0.08);
        expect(state.position).toBeGreaterThan(before - 1e-9);
    });

    it('poses only the fronts that moved during the animation', () => {
        const states = new FrontStates();
        const {body} = item(cabinet({kind: 'door'}));
        const items = new Map<number, THREE.Object3D>([[7, body]]);
        const [door, sliding] = frontObjects(body);
        states.toggle(7, sliding);
        states.update(0.1);
        door.children[0].rotation.y = 0.5;
        states.apply(items, true);
        expect(door.children[0].rotation.y).toBe(0.5);
        expect(sliding.children[0].position.z).toBeGreaterThan(pivotPoint(frontSpecOf(sliding), 'main').z * 0.001);
    });

    it('opens the fronts of items built later while every front is open', () => {
        const states = new FrontStates();
        const {body} = item(cabinet({kind: 'door'}));
        const items = new Map<number, THREE.Object3D>([[7, body]]);
        states.setAll(true, new Map(), false);
        states.apply(items);
        expect(states.isOpen(7, 'front:0')).toBe(true);
        expect(frontObjects(body)[0].children[0].rotation.y).toBeCloseTo(-THREE.MathUtils.degToRad(105));
        states.toggle(7, frontObjects(body)[0]);
        expect(states.isOpen(7, 'front:0')).toBe(false);
    });
});
