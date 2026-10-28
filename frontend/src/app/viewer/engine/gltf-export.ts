import {Scene} from '@app/models/scene';
import * as THREE from 'three';
import {CSS2DObject} from 'three/examples/jsm/renderers/CSS2DRenderer.js';

import {FixtureLight, LumenScale, WhiteBalance} from './lights';

/** Largest texture side written to the file, px. */
const MaxTextureSize = 2048;

/**
 * A copy of the shown floor (`content`) for a glTF file: the ceilings (with the roofs) and the items' ceiling parts
 * shown; the room labels, the selection highlight, the roofs' top-view outlines (`planOnly`), the section-fill twins
 * (`sectionMaterial`) and hidden items (other variants) left out. Items, walls, openings, rooms and roofs are named after
 * their scene elements (furniture parts after the part), and
 * `userData` is cleared.
 *
 * Lights: every fixture goes in switched on, in candela (the viewer's `LumenScale` undone) and in its real colour
 * (the viewer's white balance undone), so a renderer lights the flat as the real lamps would. A spot is turned to
 * shine along its node's −z, where glTF has it (the viewer aims it with a target instead). LED strips are area lights,
 * which glTF has no light for, so they are left out.
 *
 * Geometries and materials are shared with the viewer, so the copy must not change them.
 */
export function exportTree(content: THREE.Object3D, scene: Scene, sectionMaterial: THREE.Material): THREE.Object3D {
    content.updateMatrixWorld(true);
    const copy = content.clone(true);
    copy.updateMatrixWorld(true);
    const names = new Map<string, string>([
        ...scene.items.map((i): [string, string] => [`itemId:${i.id}`, i.name]),
        ...scene.walls.map((w): [string, string] => [`wallId:${w.id}`, w.name]),
        ...scene.walls.flatMap((w) => w.openings).map((o): [string, string] => [`openingId:${o.id}`, o.name]),
        ...scene.rooms.map((r): [string, string] => [`roomId:${r.id}`, r.name]),
        ...(scene.roofs ?? []).map((r): [string, string] => [`roofId:${r.id}`, r.name]),
    ]);
    // The viewer's object for each copied one (a clone keeps the order of the children).
    const originals = new Map<THREE.Object3D, THREE.Object3D>();
    const pair = (original: THREE.Object3D, copied: THREE.Object3D): void => {
        originals.set(copied, original);
        original.children.forEach((child, i) => pair(child, copied.children[i]));
    };
    pair(content, copy);

    const removed: THREE.Object3D[] = [];
    copy.traverse((o) => {
        if (o.name === 'ceilings' || o.userData['ceilingPart'] === true) {
            o.visible = true;
        }
        const material = o instanceof THREE.Mesh ? (o.material as THREE.Material | THREE.Material[]) : null;
        const sectionFill = material === sectionMaterial || (Array.isArray(material) && material.includes(sectionMaterial));
        const fixture = o.userData['fixtureLight'] as FixtureLight | undefined;
        if (fixture !== undefined) {
            // Every fixture goes in on; switched off, the viewer hides it.
            o.visible = true;
        }
        const unlit = o instanceof THREE.Light && fixture === undefined && o.intensity === 0;
        if (
            o instanceof CSS2DObject ||
            sectionFill ||
            !o.visible ||
            o.userData['highlight'] === true ||
            o.userData['planOnly'] === true ||
            o instanceof THREE.RectAreaLight ||
            unlit
        ) {
            removed.push(o);
            return;
        }
        if (o instanceof THREE.Light && fixture !== undefined) {
            o.intensity = fixture.intensity / LumenScale;
            o.color.lerp(new THREE.Color('#ffffff'), WhiteBalance / (WhiteBalance - 1));
        }
        if (o instanceof THREE.SpotLight) {
            aimAlongZ(o, originals.get(o) as THREE.SpotLight);
        }
        const partName: unknown = o.userData['partName'];
        if (typeof partName === 'string') {
            o.name = partName;
        }
        for (const key of ['itemId', 'wallId', 'openingId', 'roomId', 'roofId']) {
            const name = names.get(`${key}:${String(o.userData[key])}`);
            if (name !== undefined && name !== '') {
                // A room's floor and its wall finishes both carry the room.
                o.name = key === 'roomId' && o instanceof THREE.Mesh ? `${name} floor` : name;
                break;
            }
        }
        o.userData = {};
    });
    removed.forEach((o) => o.removeFromParent());
    return copy;
}

/**
 * Turns the copied spot so that its −z axis points at the viewer's target (its copy is an orphan, so the original's
 * world position is used), then puts its target where the exporter expects it, so the direction is kept.
 */
function aimAlongZ(spot: THREE.SpotLight, original: THREE.SpotLight): void {
    const target = new THREE.Vector3();
    original.target.getWorldPosition(target);
    // Lights look along −z (Object3D.lookAt treats them like cameras).
    spot.lookAt(target);
    spot.target.removeFromParent();
    spot.target.position.set(0, 0, -1);
    spot.add(spot.target);
}

/** The tree as a binary glTF (.glb): metres, y up, the plan's north along −z. Lights go in as KHR_lights_punctual. */
export async function toGlb(root: THREE.Object3D): Promise<ArrayBuffer> {
    const {GLTFExporter} = await import('three/examples/jsm/exporters/GLTFExporter.js');
    const result = await new GLTFExporter().parseAsync(root, {binary: true, onlyVisible: true, maxTextureSize: MaxTextureSize});
    if (!(result instanceof ArrayBuffer)) {
        throw new Error('The 3D model could not be written.');
    }
    return result;
}
