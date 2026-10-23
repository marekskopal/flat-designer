import {Item, Room, SceneModel} from '@app/models/scene';
import * as THREE from 'three';

import {buildCustomItemBody, DefinitionLibrary} from './custom/custom';
import {buildKitchenItemBody} from './kitchen';
import {MaterialLibrary} from './materials';
import {mergeStatic} from './merge';
import {buildModelItemBody, ModelLibrary} from './models';
import {buildOutdoorItemBody} from './outdoor';
import {PictureLibrary} from './pictures';
import {MM, tileGrid} from './plan-geometry';
import {buildSanitaryItemBody} from './sanitary';
import {buildStructureItemBody} from './structure';
import {alignTiles} from './tiling';

/**
 * What items load on demand: the scene's 3D models by id with the library loading them (null: `Model` items show
 * placeholders), and the images `picture` parts show (null: plain pictures).
 */
export interface ItemAssets {
    models: ReadonlyMap<number, SceneModel>;
    modelLibrary: ModelLibrary | null;
    pictures: PictureLibrary | null;
}

const NoAssets: ItemAssets = {models: new Map(), modelLibrary: null, pictures: null};

/**
 * Builds a parametric item and places it in the scene: the body is built in the item-local frame
 * (bottom centre origin, x along the width, +z out of the front), then moved to its plan position and
 * rotated counter-clockwise by `rotation` degrees. Tiled surfaces (a bath panel, a tiled box) follow the tile
 * grid of the item's `room`. `Custom` items are drawn from their entry in `definitions`, `Model` items and pictures
 * from the `assets` loaded on demand. Its plain meshes are merged per material (`mergeStatic()`).
 */
export function buildItem(
    item: Item,
    materials: MaterialLibrary,
    room?: Room,
    definitions: DefinitionLibrary = new Map(),
    assets: ItemAssets = NoAssets,
): THREE.Object3D {
    const object = buildItemParts(item, materials, room, definitions, assets);
    mergeStatic(object);
    return object;
}

/** The item as `buildItem()` builds it, before merging: a mesh per part (`userData.partName` on a `Custom` item's). */
export function buildItemParts(
    item: Item,
    materials: MaterialLibrary,
    room?: Room,
    definitions: DefinitionLibrary = new Map(),
    assets: ItemAssets = NoAssets,
): THREE.Object3D {
    const object =
        buildCustomItemBody(item, materials, definitions, assets.pictures, room) ??
        buildModelItemBody(item, assets.models, assets.modelLibrary) ??
        buildKitchenItemBody(item, materials) ??
        buildSanitaryItemBody(item, materials) ??
        buildOutdoorItemBody(item, materials) ??
        buildStructureItemBody(item, materials) ??
        new THREE.Group();
    object.name = `item:${item.name}`;
    object.position.set(item.x * MM, item.z * MM, -item.y * MM);
    object.rotation.y = THREE.MathUtils.degToRad(item.rotation);
    object.userData = {...object.userData, itemId: item.id};
    alignTiles(object, tileGrid(room), materials);
    object.traverse((o) => {
        if (o instanceof THREE.Mesh) {
            // A glowing bulb would block its own light.
            o.castShadow = o.userData['castsNoShadow'] !== true;
            o.receiveShadow = true;
        }
    });
    return object;
}
