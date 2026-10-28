import {Item, Plot, Room, Scene, SceneModel} from '@app/models/scene';
import * as THREE from 'three';

import {buildCustomItemBody, DefinitionLibrary} from './custom/custom';
import {buildKitchenItemBody} from './kitchen';
import {MaterialLibrary} from './materials';
import {mergeStatic} from './merge';
import {buildModelItemBody, ModelLibrary} from './models';
import {buildOutdoorItemBody, fenceFollows, GroundAlong} from './outdoor';
import {PictureLibrary} from './pictures';
import {MM, tileGrid} from './plan-geometry';
import {buildSanitaryItemBody} from './sanitary';
import {buildStructureItemBody} from './structure';
import {groundAt} from './terrain';
import {alignTiles} from './tiling';
import {FloorRoofs} from './under-roof';

/**
 * What items load on demand: the scene's 3D models by id with the library loading them (null: `Model` items show
 * placeholders), and the images `picture` parts show (null: plain pictures).
 */
export interface ItemAssets {
    models: ReadonlyMap<number, SceneModel>;
    modelLibrary: ModelLibrary | null;
    pictures: PictureLibrary | null;
    /** The floor's roofs, under which a pendant's ceiling slopes. */
    roofs?: FloorRoofs;
    /** The floor's plots, whose ground an item `onGround` stands on. */
    plots?: readonly Plot[];
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

/** Whether an item stands on the plots' ground: `onGround`, or a fence following it (FD-86, FD-87). */
export function standsOnGround(item: Pick<Item, 'kind' | 'params' | 'onGround'>): boolean {
    return item.onGround === true || fenceFollows(item);
}

/** The scene's items with their z against the floor's level: an item on the ground gets the ground under it added. */
export function itemsOverFloor(scene: Pick<Scene, 'items' | 'plots'>): Item[] {
    const plots = scene.plots ?? [];
    return scene.items.map((item) => (standsOnGround(item) ? {...item, z: item.z + Math.round(groundAt(plots, item.x, item.y))} : item));
}

/** The ground along a fence's width (item-local x, mm) against the ground under its centre; undefined for other kinds. */
function groundAlong(item: Item, plots: readonly Plot[]): GroundAlong | undefined {
    if (item.kind !== 'Fence' || plots.length === 0) {
        return undefined;
    }
    const r = THREE.MathUtils.degToRad(item.rotation);
    const centre = groundAt(plots, item.x, item.y);
    return (x) => groundAt(plots, item.x + x * Math.cos(r), item.y + x * Math.sin(r)) - centre;
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
        buildCustomItemBody(item, materials, definitions, assets.pictures, room, assets.roofs) ??
        buildModelItemBody(item, assets.models, assets.modelLibrary) ??
        buildKitchenItemBody(item, materials) ??
        buildSanitaryItemBody(item, materials) ??
        buildOutdoorItemBody(item, materials, groundAlong(item, assets.plots ?? [])) ??
        buildStructureItemBody(item, materials) ??
        new THREE.Group();
    object.name = `item:${item.name}`;
    const ground = standsOnGround(item) ? groundAt(assets.plots ?? [], item.x, item.y) : 0;
    object.position.set(item.x * MM, (item.z + ground) * MM, -item.y * MM);
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
