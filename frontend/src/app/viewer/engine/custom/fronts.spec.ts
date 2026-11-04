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
import {concealedOffset, frontHingeHeights,FrontLinkTag} from './hinges';

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
        expect(frontSpec(part({kind: 'door'}), 0)).toMatchObject({
            key: 'front:0',
            motion: 'swing',
            hinge: 'left',
            hingeType: 'concealed',
            angle: 110,
            softClose: false,
        });
        expect(frontSpec(part({kind: 'door', handleSide: 'left'}), 1)?.hinge).toBe('right');
        expect(frontSpec(part({kind: 'door', handleSide: 'left', hinge: 'left'}), 1)?.hinge).toBe('left');
        expect(frontSpec(part({kind: 'drawer', softClose: true}), 0)).toMatchObject({motion: 'slide', travel: 420, softClose: true});
        expect(frontSpec(part({opens: 'none'}), 0)).toBeNull();
        expect(frontSpec(part({}, {type: 'board'}), 0)).toBeNull();
    });

    it('opens a concealed hinge 155° at most, a butt hinge 180°', () => {
        expect(frontSpec(part({kind: 'door', hingeType: 'butt'}), 0)).toMatchObject({hingeType: 'butt', angle: 105});
        expect(frontSpec(part({kind: 'door', angle: 170}), 0)?.angle).toBe(155);
        expect(frontSpec(part({kind: 'door', angle: 155}), 0)?.angle).toBe(155);
        expect(frontSpec(part({kind: 'door', hingeType: 'butt', angle: 170}), 0)?.angle).toBe(170);
        expect(frontSpec(part({opens: 'lift', angle: 170}), 0)?.angle).toBe(170);
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

describe('hinges', () => {
    it('keeps a door on concealed hinges in front of the carcass and inside its side while it swings', () => {
        // The cabinet's left side is 18 mm thick, its front flush with the door's back (z 540); the door is 20 mm thick.
        const spec = frontSpec(part({kind: 'door', angle: 155}), 0) as FrontSpec;
        expect(pivotPose(spec, 'main', 0).offset.length()).toBe(0);
        for (let degrees = 0; degrees <= 155; degrees += 1) {
            const t = degrees / 155;
            const backEdge = posed(spec, 'main', t, new THREE.Vector3(0, 400, 540));
            const frontEdge = posed(spec, 'main', t, new THREE.Vector3(0, 400, 560));
            // Never out past the side's outer face (x 0) by more than half a millimetre…
            expect(Math.min(backEdge.x, frontEdge.x), `${degrees}°`).toBeGreaterThan(-0.5);
            // … nor back into the carcass.
            for (const u of [0, 10, 20, 40, 80, 300, 600]) {
                const back = posed(spec, 'main', t, new THREE.Vector3(u, 400, 540));
                if (back.x > 0) {
                    expect(back.z, `${degrees}° at ${u}`).toBeGreaterThan(539.4);
                }
            }
        }
        // Open square, it has come forward and in over the side, a little into the opening.
        const square = posed(spec, 'main', 90 / 155, new THREE.Vector3(0, 400, 540));
        expect(square.z).toBeGreaterThan(545);
        expect(square.x).toBeGreaterThan(18);
        expect(square.x).toBeLessThan(32);
    });

    it('turns a door on a butt hinge about its back hinge edge', () => {
        const spec = frontSpec(part({kind: 'door', hingeType: 'butt'}), 0) as FrontSpec;
        expect(posed(spec, 'main', 1, new THREE.Vector3(0, 400, 540)).distanceTo(new THREE.Vector3(0, 400, 540))).toBeCloseTo(0, 6);
        expect(pivotPose(spec, 'main', 1).offset.length()).toBe(0);
    });

    it('moves the linkage continuously', () => {
        let last = concealedOffset(0);
        expect(last).toEqual([0, 0]);
        for (let degrees = 0.25; degrees <= 155; degrees += 0.25) {
            const next = concealedOffset(degrees);
            expect(Math.hypot(next[0] - last[0], next[1] - last[1]), `${degrees}°`).toBeLessThan(0.5);
            last = next;
        }
    });

    it('spreads more hinges along a taller door', () => {
        expect(frontHingeHeights(700)).toEqual([100, 600]);
        expect(frontHingeHeights(1200)).toEqual([100, 600, 1100]);
        expect(frontHingeHeights(1800)).toHaveLength(4);
        expect(frontHingeHeights(2100)).toHaveLength(5);
        expect(frontHingeHeights(200)).toEqual([50, 150]);
    });
});

describe('the builder', () => {
    it('hangs a door on concealed hinges: cups on the door, plates on the side, links joining them', () => {
        const {body} = item(cabinet({kind: 'door'}));
        const door = frontObjects(body)[0];
        const links = door.children.filter((c) => c.userData[FrontLinkTag] !== undefined);
        expect(links).toHaveLength(4);
        // The plates stand on the left side's inner face (18 mm in from the door's hinge edge at x −300).
        const plates = body.children.filter((c) => c instanceof THREE.Mesh && c.userData['partName'] === 'Hinge');
        expect(plates.length).toBeGreaterThan(0);
        expect(Math.min(...plates.map((p) => box(p)[0]))).toBe(-282);
        // Every link keeps its length, from the arm's nose to the cup, as the door opens.
        const ends = (link: THREE.Object3D): number => {
            const geometry = (link as THREE.Mesh).geometry as THREE.BoxGeometry;
            return geometry.parameters.width;
        };
        const lengths = links.map(ends);
        setFrontPose(door, 1);
        expect(links.map(ends)).toEqual(lengths);
        mergeStatic(body);
        expect(frontObjects(body)[0].children.filter((c) => c.userData[FrontLinkTag] !== undefined)).toHaveLength(4);
    });

    it('turns the links with the door: their arm ends stay, their cup ends follow it', () => {
        const {body} = item(cabinet({kind: 'door'}));
        const door = frontObjects(body)[0];
        // A link runs from its arm pivot (−x) to its cup pivot (+x).
        const ends = (side: number): THREE.Vector3[] => {
            body.updateMatrixWorld(true);
            return door.children
                .filter((c) => c.userData[FrontLinkTag] !== undefined)
                .map((link) => {
                    const length = ((link as THREE.Mesh).geometry as THREE.BoxGeometry).parameters.width;
                    return new THREE.Vector3((side * length) / 2, 0, 0).applyMatrix4(link.matrixWorld);
                });
        };
        const [arms, cups] = [ends(-1), ends(1)];
        setFrontPose(door, 1);
        ends(-1).forEach((p, i) => expect(p.distanceTo(arms[i])).toBeLessThan(1e-6));
        ends(1).forEach((p, i) => expect(p.distanceTo(cups[i])).toBeGreaterThan(0.003));
    });

    it('hides the links while the door is closed', () => {
        const {body} = item(cabinet({kind: 'door'}));
        const door = frontObjects(body)[0];
        const links = door.children.filter((c) => c.userData[FrontLinkTag] !== undefined);
        expect(links.every((l) => !l.visible)).toBe(true);
        setFrontPose(door, 0.5);
        expect(links.every((l) => l.visible)).toBe(true);
        setFrontPose(door, 0);
        expect(links.every((l) => !l.visible)).toBe(true);
    });

    it('draws only the cups of a door hinged where no side stands', () => {
        const definition = cabinet({kind: 'door', hinge: 'right', x: 0, w: 300});
        const {body} = item(definition);
        const door = frontObjects(body)[0];
        expect(door.children.filter((c) => c.userData[FrontLinkTag] !== undefined)).toHaveLength(0);
        const hinges = (root: THREE.Object3D): number => root.children.filter((c) => c.userData['partName'] === 'Hinge').length;
        expect(hinges(body)).toBe(0);
        expect(hinges(door.children[0])).toBe(2);
    });

    it('takes no turned board for the side', () => {
        const definition = cabinet({kind: 'door'});
        (definition.parts[0] as unknown as Record<string, unknown>)['turn'] = 10;
        const {body} = item(definition);
        expect(frontObjects(body)[0].children.filter((c) => c.userData[FrontLinkTag] !== undefined)).toHaveLength(0);
    });

    it('hangs a swinging assembly by its front, of the hinge type the front gives', () => {
        const definition = {
            format: 1,
            size: {width: 600, depth: 560, height: 800},
            parts: [
                {type: 'board', name: 'Side', repeat: {count: 2}, x: 'i * (W - 18)', y: 0, z: 0, w: 18, h: 'H', d: 'D - 20'},
                {
                    type: 'assembly',
                    name: 'Larder door',
                    x: 0,
                    y: 0,
                    z: 0,
                    w: 'W',
                    h: 'H',
                    d: 'D',
                    opens: 'swing',
                    parts: [
                        {type: 'board', name: 'Rack', x: 40, y: 100, z: 'AD - 120', w: 'AW - 80', h: 16, d: 100},
                        {type: 'front', name: 'Door', x: 0, y: 0, z: 'AD - 20', w: 'AW', h: 'AH', d: 20, hingeType: 'butt'},
                    ],
                },
            ],
        } as unknown as FurnitureDefinitionBody;
        const {body} = item(definition);
        const spec = frontSpecOf(frontObjects(body)[0]);
        expect(spec).toMatchObject({hingeType: 'butt', door: {z: 540, d: 20}});
        // Without a front, the part reaching furthest out.
        const rack = {...definition, parts: [definition.parts[0], {...definition.parts[1], parts: [definition.parts[1].parts?.[0]]}]};
        expect(frontSpecOf(frontObjects(item(rack as FurnitureDefinitionBody).body)[0]).door).toMatchObject({z: 440, d: 100});
    });

    it('hangs a door on butt hinges: a barrel half on the door, half on the carcass', () => {
        const {body} = item(cabinet({kind: 'door', hingeType: 'butt'}));
        const door = frontObjects(body)[0];
        expect(door.children.filter((c) => c.userData[FrontLinkTag] !== undefined)).toHaveLength(0);
        const barrels = (root: THREE.Object3D): number =>
            root.children.filter((c) => c instanceof THREE.Mesh && c.geometry instanceof THREE.CylinderGeometry).length;
        expect(barrels(body)).toBe(2);
        expect(barrels(door.children[0])).toBe(2);
    });

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
        expect(frontObjects(body)[0].children[0].rotation.y).toBeCloseTo(-THREE.MathUtils.degToRad(110));
        states.toggle(7, frontObjects(body)[0]);
        expect(states.isOpen(7, 'front:0')).toBe(false);
    });
});
