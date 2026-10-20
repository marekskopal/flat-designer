import {Item, Room, SceneModel} from '@app/models/scene';
import * as THREE from 'three';

import {buildCustomItemBody, DefinitionLibrary} from './custom/custom';
import {buildFurnitureItemBody} from './furniture';
import {buildKitchenItemBody} from './kitchen';
import {MaterialLibrary} from './materials';
import {buildModelItemBody, ModelLibrary} from './models';
import {buildOutdoorItemBody} from './outdoor';
import {MM, tileOrigin} from './plan-geometry';
import {buildSanitaryItemBody} from './sanitary';
import {alignTiles} from './tiling';

/** The scene's 3D models by id, and the library loading them (null: `Model` items show placeholders). */
export interface ItemModels {
    byId: ReadonlyMap<number, SceneModel>;
    library: ModelLibrary | null;
}

const NoModels: ItemModels = {byId: new Map(), library: null};

/**
 * Builds a parametric item and places it in the scene: the body is built in the item-local frame
 * (bottom centre origin, x along the width, +z out of the front), then moved to its plan position and
 * rotated counter-clockwise by `rotation` degrees. Tiled surfaces (a bath panel, a tiled box) follow the tile
 * grid of the item's `room`. `Custom` items are drawn from their entry in `definitions`, `Model` items from the
 * scene's `models` through the `library` that loads them.
 */
export function buildItem(
    item: Item,
    materials: MaterialLibrary,
    room?: Room,
    definitions: DefinitionLibrary = new Map(),
    models: ItemModels = NoModels,
): THREE.Object3D {
    const object =
        buildCustomItemBody(item, materials, definitions) ??
        buildModelItemBody(item, models.byId, models.library) ??
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
