import {Scene} from '@app/models/scene';
import * as THREE from 'three';
import {CSS2DObject} from 'three/examples/jsm/renderers/CSS2DRenderer.js';
import {describe, expect, it} from 'vitest';

import {exportTree, toGlb} from './gltf-export';
import {createFixtureLight, kelvinToColor, LumenScale, markFixtureLight} from './lights';

const box = (): THREE.BufferGeometry => new THREE.BoxGeometry(1, 1, 1);

/**
 * A floor as the viewer builds it: a wall with its section-fill twin, a hidden ceiling, a floor with its selection
 * highlight, two items (one a hidden variant), a label, a fixture (off), an LED strip and a stray light.
 */
function content(section: THREE.Material): THREE.Group {
    const material = new THREE.MeshStandardMaterial({color: '#cccccc'});
    const root = new THREE.Group();

    const wall = new THREE.Group();
    wall.userData = {wallId: 1};
    wall.add(new THREE.Mesh(box(), material), new THREE.Mesh(box(), section));
    const ceilings = new THREE.Group();
    ceilings.name = 'ceilings';
    ceilings.visible = false;
    ceilings.add(new THREE.Mesh(box(), material));
    const floor = new THREE.Mesh(box(), material);
    floor.userData = {roomId: 3};
    const highlight = new THREE.Mesh(box(), material);
    highlight.userData = {highlight: true};
    root.add(wall, ceilings, floor, highlight);

    const table = new THREE.Group();
    table.userData = {itemId: 10, definitionError: 'none'};
    const top = new THREE.Mesh(box(), material);
    top.name = 'part:top';
    top.userData = {partName: 'top'};
    table.add(top);
    const beam = new THREE.Mesh(box(), material);
    beam.userData = {ceilingPart: true};
    beam.visible = false;
    table.add(beam);
    const island = new THREE.Group();
    island.userData = {itemId: 11};
    island.visible = false;
    island.add(new THREE.Mesh(box(), material));
    root.add(table, island);

    root.add(new CSS2DObject(document.createElement('div')));
    // A 1000 lm, 2700 K pendant, switched off (as by day); an LED strip; a light that is no fixture and is off.
    const pendant = createFixtureLight({kind: 'point', lumens: 1000, kelvin: 2700, angle: 0, shadow: false});
    pendant.position.set(2, 2.4, -3);
    const strip = new THREE.RectAreaLight('#ffffff', 1, 1, 0.01);
    markFixtureLight(strip, 1);
    root.add(pendant, strip, new THREE.PointLight('#ffffff', 0));
    return root;
}

const scene = {
    rooms: [{id: 3, name: 'Kitchen'}],
    walls: [{id: 1, name: 'North wall', openings: []}],
    items: [
        {id: 10, name: 'Table'},
        {id: 11, name: 'Island'},
    ],
} as unknown as Scene;

describe('exportTree', () => {
    it('leaves out labels, section fills, hidden variants and lights that are off, and shows the ceilings', () => {
        const section = new THREE.MeshBasicMaterial({side: THREE.BackSide});
        const original = content(section);
        const tree = exportTree(original, scene, section);

        const names: string[] = [];
        let meshes = 0;
        const lights: THREE.Light[] = [];
        tree.traverse((o) => {
            names.push(o.name);
            expect(o).not.toBeInstanceOf(CSS2DObject);
            expect(o).not.toBeInstanceOf(THREE.RectAreaLight);
            expect(o.visible).toBe(true);
            expect(o.userData).toEqual({});
            if (o instanceof THREE.Mesh) {
                meshes++;
                expect(o.material).not.toBe(section);
            }
            if (o instanceof THREE.Light) {
                lights.push(o);
            }
        });
        // The wall's face, the ceiling, the floor, the table's top and its beam.
        expect(meshes).toBe(5);
        expect(names).toEqual(expect.arrayContaining(['North wall', 'Table', 'top', 'ceilings', 'Kitchen floor']));
        expect(names).not.toContain('Island');
        expect(names).not.toContain('part:top');

        // The pendant, on, in candela (1000 lm over the sphere) and in its real colour.
        expect(lights).toHaveLength(1);
        expect(lights[0].intensity).toBeCloseTo(1000 / (4 * Math.PI));
        expect(lights[0].color.getHex()).toBe(kelvinToColor(2700).getHex());

        // The viewer's own tree is untouched.
        expect(original.children).toHaveLength(10);
        expect(original.children[1].visible).toBe(false);
        expect(original.children[4].userData).toEqual({itemId: 10, definitionError: 'none'});
        expect(original.getObjectByProperty('type', 'PointLight')?.intensity).toBe(0);
        expect(LumenScale).toBeLessThan(1);
    });

    it('turns a spot to shine along −z, where glTF has it', () => {
        const section = new THREE.MeshBasicMaterial();
        const root = new THREE.Group();
        const downlight = createFixtureLight({kind: 'spot', lumens: 500, kelvin: 4000, angle: 60, shadow: false});
        downlight.position.set(1, 2.5, -1);
        root.add(downlight);

        const tree = exportTree(root, scene, section);
        const spot = tree.getObjectByProperty('type', 'SpotLight') as THREE.SpotLight;
        tree.updateMatrixWorld(true);
        const direction = new THREE.Vector3(0, 0, -1).applyQuaternion(spot.getWorldQuaternion(new THREE.Quaternion()));
        expect(direction.x).toBeCloseTo(0);
        expect(direction.y).toBeCloseTo(-1);
        expect(direction.z).toBeCloseTo(0);
        expect(spot.target.parent).toBe(spot);
        expect(spot.target.position.toArray()).toEqual([0, 0, -1]);
    });

    it('writes a binary glTF', async () => {
        const section = new THREE.MeshBasicMaterial();
        const glb = await toGlb(exportTree(content(section), scene, section));

        // The glTF magic, "glTF", and version 2.
        const header = new DataView(glb);
        expect(header.getUint32(0, true)).toBe(0x46546c67);
        expect(header.getUint32(4, true)).toBe(2);
        const json = new TextDecoder().decode(new Uint8Array(glb, 20, header.getUint32(12, true)));
        expect(json).toContain('"name":"Table"');
        expect(json).toContain('KHR_lights_punctual');
    });
});
