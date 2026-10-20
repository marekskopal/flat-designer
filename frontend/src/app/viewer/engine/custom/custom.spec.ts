import {readdirSync, readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {FurnitureDefinitionBody} from '@app/models/furniture';
import {Item, Material, SceneDefinition} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildItem} from '../items';
import {MaterialLibrary} from '../materials';
import {PictureLibrary} from '../pictures';
import {buildCustomItemBody, changedDefinitionIds, definitionLibrary, partMaterial} from './custom';
import {evaluateDefinition} from './definition';

/** The global definitions seeded by the backend (backend/migrations/furniture); vitest runs in frontend/. */
const SeedDirectory = resolve(process.cwd(), '../backend/migrations/furniture');
const seeds = readdirSync(SeedDirectory)
    .filter((f) => f.endsWith('.json'))
    .map((f) => ({file: f, ...(JSON.parse(readFileSync(resolve(SeedDirectory, f), 'utf8')) as {name: string; definition: FurnitureDefinitionBody})}));

const material = (id: number, code: string, color: string): Material => ({
    id,
    code,
    name: code,
    pattern: 'Plain',
    color,
    roughness: 0.5,
    metalness: 0,
    texture: null,
    tileWidth: null,
    tileHeight: null,
});

const materials = (): MaterialLibrary =>
    new MaterialLibrary(
        [
            material(1, 'kitchen-oak', '#b08850'),
            material(2, 'furniture-white-matt', '#f4f4f0'),
            material(3, 'solid-ash-honey', '#c59048'),
            material(4, 'aluminium-brushed', '#c0c0c0'),
            material(5, 'kitchen-green-matt', '#556b4a'),
            material(6, 'mirror', '#d8dde0'),
            material(7, 'appliance-black-glass', '#101012'),
        ],
        1,
    );

const sceneDefinition = (id: number, definition: FurnitureDefinitionBody, updatedAt = '2026-10-18T10:00:00+00:00'): SceneDefinition => ({
    id,
    name: `Definition ${id}`,
    definition,
    updatedAt,
});

type Size = {width: number; depth: number; height: number};

const customItem = (definitionId: number | null, size: Size, params: Record<string, unknown> = {}): Item => ({
    id: 7,
    roomId: null,
    groupId: null,
    kind: 'Custom',
    definitionId,
    name: 'Custom',
    x: 0,
    y: 0,
    z: 0,
    rotation: 0,
    materialId: null,
    params,
    ...size,
});

function meshes(object: THREE.Object3D): THREE.Mesh[] {
    const found: THREE.Mesh[] = [];
    object.traverse((o) => {
        if (o instanceof THREE.Mesh) {
            found.push(o);
        }
    });
    return found;
}

const shelf = seeds.find((s) => s.file === 'open-shelf.json')?.definition as FurnitureDefinitionBody;

describe('seeded definitions', () => {
    it.each(seeds.map((s) => [s.file, s] as const))('%s builds at its default size', (_, seed) => {
        const size = seed.definition.size ?? {width: 1000, depth: 500, height: 1000};
        const library = definitionLibrary([sceneDefinition(1, seed.definition)]);
        const parts = evaluateDefinition(seed.definition, size);

        const body = buildCustomItemBody(customItem(1, size), materials(), library);

        expect(body?.userData['definitionError']).toBeUndefined();
        expect(parts.length).toBeGreaterThan(0);
        // One mesh per part, plus the handles of fronts.
        expect(meshes(body as THREE.Group).length).toBeGreaterThanOrEqual(parts.length);
    });
});

describe('buildCustomItemBody', () => {
    it('places the parts around the bottom centre of the item', () => {
        const library = definitionLibrary([sceneDefinition(1, shelf)]);
        const body = buildCustomItemBody(customItem(1, {width: 800, depth: 300, height: 1800}), materials(), library) as THREE.Group;

        const sides = body.children.filter((c) => c.userData['partName'] === 'Side');
        expect(sides.map((s) => s.position.x)).toEqual([expect.closeTo((-400 + 9) / 1000), expect.closeTo((400 - 9) / 1000)]);
        expect(sides[0].position.y).toBeCloseTo(0.9);
        expect(sides[0].position.z).toBeCloseTo(0);
        expect(body.children.filter((c) => c.userData['partName'] === 'Shelf')).toHaveLength(5);
    });

    it('draws a red placeholder when the definition is missing or does not fit the item', () => {
        const library = definitionLibrary([sceneDefinition(1, shelf)]);

        const missing = buildItem(customItem(2, {width: 800, depth: 300, height: 1800}), materials(), undefined, library);
        expect(missing.userData).toEqual({definitionError: 'The furniture definition is not in the scene.', itemId: 7});

        const tooNarrow = buildCustomItemBody(customItem(1, {width: 30, depth: 300, height: 1800}), materials(), library);
        expect(tooNarrow?.userData['definitionError']).toBe('parts[1].w: "Shelf" gets a width of -6 mm.');
        expect(meshes(tooNarrow as THREE.Group)).toHaveLength(1);
    });

    it('tolerates material slots decoded as an empty object', () => {
        const parts = shelf.parts.map(({material: _material, ...part}) => part);
        const odd = {...shelf, materialSlots: {} as unknown as [], parts};
        const library = definitionLibrary([sceneDefinition(1, odd)]);
        const body = buildCustomItemBody(customItem(1, {width: 800, depth: 300, height: 1800}), materials(), library);
        expect(body?.userData['definitionError']).toBeUndefined();
        expect(meshes(body as THREE.Group).length).toBeGreaterThan(1);
    });

    it('is null for other kinds', () => {
        expect(buildCustomItemBody({...customItem(1, {width: 1, depth: 1, height: 1}), kind: 'Box'}, materials(), new Map())).toBeNull();
    });
});

describe('new part types and options', () => {
    const body = (parts: unknown[], slots: {key: string; default?: string}[] = []): FurnitureDefinitionBody =>
        ({format: 1, size: {width: 600, depth: 400, height: 800}, materialSlots: slots, parts}) as FurnitureDefinitionBody;
    const build = (definition: FurnitureDefinitionBody, materialId: number | null = null): THREE.Group => {
        const library = definitionLibrary([sceneDefinition(1, definition)]);
        const group = buildCustomItemBody({...customItem(1, {width: 600, depth: 400, height: 800}), materialId}, materials(), library);
        expect(group?.userData['definitionError']).toBeUndefined();
        return group as THREE.Group;
    };
    const color = (mesh: THREE.Mesh): string => `#${(mesh.material as THREE.MeshStandardMaterial).color.getHexString()}`;

    it('draws an oven and a microwave front in black glass', () => {
        const part = {type: 'appliance', x: 0, y: 0, z: 380, w: 600, h: 600, d: 20};
        const oven = meshes(build(body([part])));
        const microwave = meshes(build(body([{...part, kind: 'microwave'}])));
        expect(color(oven[0])).toBe('#101012');
        // The oven has its control knobs, the microwave does not.
        expect(oven.length).toBeGreaterThan(microwave.length);
    });

    it('frames a sliding door in the profile slot and faces it with mirror', () => {
        const slots = [{key: 'carcass', default: 'kitchen-oak'}, {key: 'profile', default: 'aluminium-brushed'}];
        const door = {type: 'slidingDoor', x: 0, y: 0, z: 0, w: 600, h: 800, d: 20, material: 'carcass', profile: 'profile'};
        const plain = meshes(build(body([door], slots)));
        expect(plain).toHaveLength(5);
        expect(plain.slice(0, 4).map(color)).toEqual(['#c0c0c0', '#c0c0c0', '#c0c0c0', '#c0c0c0']);
        expect(color(plain[4])).toBe('#b08850');
        expect(color(meshes(build(body([{...door, mirror: true}], slots)))[4])).toBe('#d8dde0');
    });

    it('rounds a cushion by its radius, at most half its smallest side, keeping the box', () => {
        const bounds = (mesh: THREE.Mesh): THREE.Vector3 => {
            mesh.geometry.computeBoundingBox();
            return mesh.geometry.boundingBox!.getSize(new THREE.Vector3());
        };
        const seat = {type: 'cushion', x: 0, y: 400, z: 0, w: 600, h: 150, d: 400};
        const [rounded] = meshes(build(body([seat])));
        // The box stays the part's size; the mesh sits at the part's centre, shifted to the item centre.
        expect(bounds(rounded).toArray().map((v) => Math.round(v * 1000))).toEqual([600, 150, 400]);
        expect(rounded.position.toArray().map((v) => Math.round(v * 1000))).toEqual([0, 475, 0]);
        // A corner vertex of a square-edged cushion is at the box corner; a rounded one is pulled in by the radius.
        const corner = (mesh: THREE.Mesh): number => {
            const position = mesh.geometry.getAttribute('position');
            let nearest = Infinity;
            for (let i = 0; i < position.count; i++) {
                nearest = Math.min(nearest, Math.hypot(position.getX(i) - 0.3, position.getY(i) - 0.075, position.getZ(i) - 0.2));
            }
            return nearest;
        };
        const [square] = meshes(build(body([{...seat, radius: 0}])));
        expect(corner(square)).toBeLessThan(0.001);
        // The default 40 mm pulls the corner in; a radius above half the height (75) is clamped to it.
        expect(corner(rounded)).toBeGreaterThan(0.02);
        const [clamped] = meshes(build(body([{...seat, radius: 500}])));
        expect(bounds(clamped).toArray().map((v) => Math.round(v * 1000))).toEqual([600, 150, 400]);
    });

    it('darkens a shaded board and narrows a board to its back width', () => {
        const slots = [{key: 'carcass', default: 'kitchen-oak'}];
        const plinth = {type: 'board', x: 0, y: 0, z: 0, w: 600, h: 100, d: 400, material: 'carcass', shade: 0.5};
        const [shadedBoard] = meshes(build(body([plinth], slots)));
        // Half as bright, the texture kept (the map of a textured material would stay too).
        const shadedMaterial = shadedBoard.material as THREE.MeshStandardMaterial;
        expect(shadedMaterial.color.r).toBeCloseTo(new THREE.Color('#b08850').r / 2, 2);
        expect(shadedMaterial.map).toBe((materials().byCode('kitchen-oak') as THREE.MeshStandardMaterial).map);

        const seat = {type: 'board', x: 0, y: 0, z: 0, w: 600, h: 20, d: 400, backWidth: 400};
        // The x extent of the vertices on the back face: the geometry is in the parts frame (z = 0 at the back), the
        // shift to the item centre sits on the mesh position.
        const backSpan = (mesh: THREE.Mesh): [number, number] => {
            const position = mesh.geometry.getAttribute('position');
            const xs = Array.from({length: position.count}, (_, i) => i)
                .filter((i) => Math.abs(position.getZ(i)) < 1e-6)
                .map((i) => position.getX(i));
            return [Math.min(...xs), Math.max(...xs)];
        };
        const [both] = meshes(build(body([seat])));
        expect(backSpan(both)).toEqual([expect.closeTo(0.1), expect.closeTo(0.5)]);
        const [left] = meshes(build(body([{...seat, taper: 'left'}])));
        expect(backSpan(left)).toEqual([expect.closeTo(0.2), expect.closeTo(0.6)]);
        const [right] = meshes(build(body([{...seat, taper: 'right'}])));
        expect(backSpan(right)).toEqual([expect.closeTo(0), expect.closeTo(0.4)]);
    });

    it('leans a part back around its bottom-back edge or a given line', () => {
        const post = {type: 'board', x: 0, y: 400, z: 0, w: 30, h: 400, d: 30, tilt: 5};
        const [leaning] = build(body([post])).children;
        expect(leaning.rotation.x).toBeCloseTo(-THREE.MathUtils.degToRad(5));
        expect(leaning.position.y).toBeCloseTo(0.4);
        const [around] = build(body([{...post, tiltY: 450, tiltZ: 10}])).children;
        expect(around.position.y).toBeCloseTo(0.45);
        expect(around.position.z).toBeCloseTo(0.01 - 0.2);
    });
});

describe('partMaterial', () => {
    const slots = [
        {key: 'carcass', default: 'kitchen-oak'},
        {key: 'back', default: 'furniture-white-matt'},
    ];
    const part = (slot: string | null): Parameters<typeof partMaterial>[0] => ({
        name: 'P',
        type: 'board',
        index: null,
        x: 0,
        y: 0,
        z: 0,
        w: 1,
        h: 1,
        d: 1,
        material: slot,
        props: {},
    });
    const color = (m: THREE.Material): string => `#${(m as THREE.MeshStandardMaterial).color.getHexString()}`;

    it('takes the item choice, then the item material for the first slot, then the slot default', () => {
        const library = materials();
        const item = {...customItem(1, {width: 1, depth: 1, height: 1}), materialId: 5};
        expect(color(partMaterial(part('back'), {...item, params: {materials: {back: 3}}}, slots, library, 'vertical'))).toBe('#c59048');
        expect(color(partMaterial(part('carcass'), item, slots, library, 'vertical'))).toBe('#556b4a');
        expect(color(partMaterial(part('back'), item, slots, library, 'vertical'))).toBe('#f4f4f0');
        expect(color(partMaterial(part('carcass'), {...item, materialId: null}, slots, library, 'vertical'))).toBe('#b08850');
        expect(color(partMaterial(part(null), item, slots, library, 'vertical'))).toBe('#556b4a');
        // A second slot without a default takes the item material too.
        const plainSlots = [...slots, {key: 'fronts'}];
        expect(color(partMaterial(part('fronts'), item, plainSlots, library, 'vertical'))).toBe('#556b4a');
    });
});

describe('changedDefinitionIds', () => {
    it('lists new, removed and edited definitions', () => {
        const before = definitionLibrary([sceneDefinition(1, shelf), sceneDefinition(2, shelf), sceneDefinition(3, shelf)]);
        const edited = sceneDefinition(2, shelf, '2026-10-18T11:00:00+00:00');
        const after = definitionLibrary([sceneDefinition(1, shelf), edited, sceneDefinition(4, shelf)]);
        expect([...changedDefinitionIds(before, after)].sort()).toEqual([2, 3, 4]);
    });
});

describe('picture parts', () => {
    const poster: FurnitureDefinitionBody = {
        format: 1,
        size: {width: 700, depth: 30, height: 1000},
        parameters: [{key: 'poster', label: 'Picture', type: 'image'}],
        parts: [{type: 'picture', name: 'Print', x: 0, y: 0, z: 0, w: 'W', h: 'H', d: 'D', image: {expr: 'poster'}}],
    };
    const definitions = definitionLibrary([sceneDefinition(3, poster)]);
    const size = {width: 700, depth: 30, height: 1000};
    /** A library that has loaded a 2:1 landscape image as file 12. */
    const library = {
        texture: (fileId: number): THREE.Texture | null =>
            fileId === 12 ? Object.assign(new THREE.Texture(), {image: {width: 200, height: 100}}) : null,
    } as unknown as PictureLibrary;
    const face = (group: THREE.Object3D): THREE.Mesh => {
        let found: THREE.Mesh | null = null;
        group.traverse((o) => {
            if (o instanceof THREE.Mesh && o.userData['pictureId'] !== undefined) {
                found = o;
            }
        });
        return found as unknown as THREE.Mesh;
    };

    it('shows the image on the front face, cropped to fill it', () => {
        const body = buildCustomItemBody(customItem(3, size, {values: {poster: 12}}), materials(), definitions, library) as THREE.Group;
        const front = face(body);
        expect(front.userData['pictureId']).toBe(12);
        expect((front.material as THREE.MeshStandardMaterial).map).not.toBeNull();
        // A 2:1 image on a 0.7:1 face keeps its middle 35 %.
        const u = front.geometry.getAttribute('uv');
        expect(u.getX(0)).toBeCloseTo(0.325, 6);
        expect(u.getX(1)).toBeCloseTo(0.675, 6);
        // Half a millimetre in front of its box (in the part's frame).
        expect(front.position.z).toBeCloseTo(0.0305, 9);
    });

    it('is plain without an image, or while it loads', () => {
        for (const params of [{}, {values: {poster: 99}}]) {
            const front = face(buildCustomItemBody(customItem(3, size, params), materials(), definitions, library) as THREE.Group);
            expect((front.material as THREE.MeshStandardMaterial).map).toBeNull();
        }
    });
});
