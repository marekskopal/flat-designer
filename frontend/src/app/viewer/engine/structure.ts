import {Item} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {boxAt} from './meshes';
import {MM} from './plan-geometry';

/** White paint of a column or beam without a material, as the beams of a vaulted ceiling. */
const PaintColor = '#f4f2ee';
const RoundSegments = 32;

/**
 * Structural items in the item-local frame (bottom centre origin, x along the width, +z out of the front): a
 * `Column`, square or round (`params.shape`), and a `Beam` along the width. Like wall pieces, every mesh has an
 * inside-out twin in the section fill, so the section plane shows a solid cut. Returns null for other kinds.
 */
export function buildStructureItemBody(item: Item, materials: MaterialLibrary): THREE.Group | null {
    if (item.kind !== 'Column' && item.kind !== 'Beam') {
        return null;
    }

    const group = new THREE.Group();
    const solid = new THREE.Group();
    group.add(solid);
    const material = item.materialId === null ? materials.plain(PaintColor, 0.8) : materials.get(item.materialId);
    const body = item.kind === 'Column' && item.params['shape'] === 'round' ? roundColumn(item, material) : box(item, material);
    solid.add(body);

    const cap = new THREE.Mesh(body.geometry, materials.sectionFill());
    cap.position.copy(body.position);
    cap.scale.copy(body.scale);
    cap.userData = {castsNoShadow: true};
    solid.add(cap);

    // A beam belongs to the ceiling: it hides with the ceilings, to look into the rooms from above. (Its own group, as
    // the item's root is hidden by variants.)
    if (item.kind === 'Beam') {
        solid.userData = {ceilingPart: true};
    }
    return group;
}

function box(item: Item, material: THREE.Material): THREE.Mesh {
    return boxAt(material, (-item.width / 2) * MM, 0, (-item.depth / 2) * MM, item.width * MM, item.height * MM, item.depth * MM);
}

/** An upright cylinder filling the item's width × depth (an ellipse unless they are equal). */
function roundColumn(item: Item, material: THREE.Material): THREE.Mesh {
    const mesh = new THREE.Mesh(new THREE.CylinderGeometry(0.5, 0.5, item.height * MM, RoundSegments), material);
    mesh.scale.set(item.width * MM, 1, item.depth * MM);
    mesh.position.y = (item.height / 2) * MM;
    return mesh;
}
