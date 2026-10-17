import {Item} from '@app/models/scene';
import * as THREE from 'three';

import {buildFurnitureItemBody} from './furniture';
import {buildKitchenItemBody} from './kitchen';
import {MaterialLibrary} from './materials';
import {buildOutdoorItemBody} from './outdoor';
import {MM} from './plan-geometry';
import {buildSanitaryItemBody} from './sanitary';

/**
 * Builds a parametric item and places it in the scene: the body is built in the item-local frame
 * (bottom centre origin, x along the width, +z out of the front), then moved to its plan position and
 * rotated counter-clockwise by `rotation` degrees.
 */
export function buildItem(item: Item, materials: MaterialLibrary): THREE.Object3D {
    const object =
        buildKitchenItemBody(item, materials) ??
        buildSanitaryItemBody(item, materials) ??
        buildOutdoorItemBody(item, materials) ??
        buildFurnitureItemBody(item, materials) ??
        new THREE.Group();
    object.name = `item:${item.name}`;
    object.position.set(item.x * MM, item.z * MM, -item.y * MM);
    object.rotation.y = THREE.MathUtils.degToRad(item.rotation);
    object.userData = {itemId: item.id};
    object.traverse((o) => {
        if (o instanceof THREE.Mesh) {
            o.castShadow = true;
            o.receiveShadow = true;
        }
    });
    return object;
}
