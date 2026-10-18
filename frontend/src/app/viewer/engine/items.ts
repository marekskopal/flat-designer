import {Item, Room} from '@app/models/scene';
import * as THREE from 'three';

import {buildCustomItemBody, DefinitionLibrary} from './custom/custom';
import {buildFurnitureItemBody} from './furniture';
import {buildKitchenItemBody} from './kitchen';
import {MaterialLibrary} from './materials';
import {buildOutdoorItemBody} from './outdoor';
import {MM, tileOrigin} from './plan-geometry';
import {buildSanitaryItemBody} from './sanitary';
import {alignTiles} from './tiling';

/**
 * Builds a parametric item and places it in the scene: the body is built in the item-local frame
 * (bottom centre origin, x along the width, +z out of the front), then moved to its plan position and
 * rotated counter-clockwise by `rotation` degrees. Tiled surfaces (a bath panel, a tiled box) follow the tile
 * grid of the item's `room`. `Custom` items are drawn from their entry in `definitions`.
 */
export function buildItem(item: Item, materials: MaterialLibrary, room?: Room, definitions: DefinitionLibrary = new Map()): THREE.Object3D {
    const object =
        buildCustomItemBody(item, materials, definitions) ??
        buildKitchenItemBody(item, materials) ??
        buildSanitaryItemBody(item, materials) ??
        buildOutdoorItemBody(item, materials) ??
        buildFurnitureItemBody(item, materials) ??
        new THREE.Group();
    object.name = `item:${item.name}`;
    object.position.set(item.x * MM, item.z * MM, -item.y * MM);
    object.rotation.y = THREE.MathUtils.degToRad(item.rotation);
    object.userData = {...object.userData, itemId: item.id};
    alignTiles(object, tileOrigin(room), materials);
    object.traverse((o) => {
        if (o instanceof THREE.Mesh) {
            o.castShadow = true;
            o.receiveShadow = true;
        }
    });
    return object;
}
