import {isOutdoorRoom, Opening, Room, Scene, Wall, WallFinish} from '@app/models/scene';
import * as THREE from 'three';

import {FacadeRoom, facadeSpans} from './ceilings';
import {MaterialLibrary} from './materials';
import {mergeStatic} from './merge';
import {curvedPrismAt} from './meshes';
import {MM, pointOnWall, Vec2, WallFrame, wallFrame, wallPieces} from './plan-geometry';
import {FloorRoofs, HeightAt, wallBottomAt, wallTopAt} from './under-roof';

/** How far a façade band stands off the wall face, mm: render or cladding over it. */
export const FacadeLining = 20;
/** A band's faces are stood up through points at most this far apart (mm), following a sloping top or the ground. */
const FacadeStep = 250;
/** The default façade's bottom and top, mm: as low and high as the wall reaches (its bottom and top cut them). */
const Whole = 100000;

/**
 * The façade bands (FD-88) on the wall faces outside every indoor room (`facadeSpans()`): a face's own `leftFinishes`
 * / `rightFinishes` along each stretch, or on an exterior wall without a material or bands the project's façade
 * material (`exteriorMaterialId`) over its whole height. A band stands `FacadeLining` off the face, cut by the
 * openings of the face (a niche only on its own), between the wall's bottom (a base, the ground; a band may start
 * below the floor's level, a plinth) and its top (sloping, under the roofs). A group per wall face, tagged with the
 * wall (a click on the façade picks it) and merged per material, in one group for the floor.
 */
export function buildFacades(
    scene: Pick<Scene, 'walls' | 'rooms' | 'roofs' | 'plots' | 'exteriorMaterialId'>,
    materials: MaterialLibrary,
): THREE.Group {
    const group = new THREE.Group();
    group.name = 'facades';
    const roofs: FloorRoofs = scene.roofs ?? [];
    const plots = scene.plots ?? [];
    const exterior = scene.exteriorMaterialId ?? null;
    const rooms = scene.rooms.map((r: Room): FacadeRoom => ({polygon: r.polygon, outdoor: isOutdoorRoom(r)}));
    for (const face of facadeSpans(scene.walls, rooms)) {
        const bands = facadeBands(face.wall, face.side, exterior);
        if (bands.length === 0) {
            continue;
        }
        const frame = wallFrame(face.wall);
        // A wall on the floor lets a plinth reach below it (over the slab's edge); one on a base or the ground stops there.
        const caps = {
            top: wallTopAt(face.wall, roofs, frame) ?? ((): number => face.wall.height),
            bottom: wallBottomAt(face.wall, frame, plots) ?? ((): number => -Infinity),
        };
        const openings = face.wall.openings.filter((o) => o.type !== 'Niche' || (o.side ?? 'Left') === face.side);
        const facade = new THREE.Group();
        facade.name = `facade:${face.wall.name}`;
        facade.userData = {wallId: face.wall.id};
        for (const [from, to] of face.spans) {
            for (const band of bands) {
                const material = materials.get(band.materialId);
                const gaps = openings.flatMap((o) => gapIn(o, from, to, band));
                for (const piece of wallPieces(to - from, band.top - band.bottom, gaps)) {
                    const [low, high] = [band.bottom + piece.bottom, band.bottom + piece.top];
                    const mesh = bandPiece(material, frame, face.wall, face.side, [from + piece.from, from + piece.to], [low, high], caps);
                    if (mesh !== null) {
                        facade.add(mesh);
                    }
                }
            }
        }
        if (facade.children.length > 0) {
            mergeStatic(facade);
            group.add(facade);
        }
    }
    return group;
}

/**
 * A façade face's bands: its own, else on an exterior wall without a material of its own the project's façade
 * (`exterior`) over the whole face; none otherwise (the wall's material shows, or with `[]` the bare wall).
 */
export function facadeBands(wall: Wall, side: 'Left' | 'Right', exterior: number | null): WallFinish[] {
    const own = side === 'Left' ? wall.leftFinishes : wall.rightFinishes;
    if (own !== null && own.length > 0) {
        return own;
    }
    if (own === null && wall.type === 'Exterior' && wall.materialId === null && exterior !== null) {
        const grounded = wall.onGround === true || (wall.baseStart ?? 0) !== 0 || (wall.baseEnd ?? 0) !== 0;
        return [{materialId: exterior, bottom: grounded ? -Whole : 0, top: Whole}];
    }
    return [];
}

/** An opening's gap in a band on the stretch `from`–`to` along the wall, band-local as `wallPieces()` takes it. */
function gapIn(opening: Opening, from: number, to: number, band: WallFinish): Opening[] {
    const start = Math.max(opening.offset, from);
    const end = Math.min(opening.offset + opening.width, to);
    const head = opening.sillHeight + opening.height;
    if (end <= start || head <= band.bottom || opening.sillHeight >= band.top) {
        return [];
    }
    const sill = Math.max(opening.sillHeight - band.bottom, 0);
    return [{...opening, offset: start - from, width: end - start, sillHeight: sill, height: Math.min(head, band.top) - band.bottom - sill}];
}

/**
 * A piece of a band along the wall (`stretch`, mm) and above the floor's level (`range`), on the face on `side`, stood
 * `FacadeLining` off it, between the wall's `bottom` (a base, the ground) and its `top` (sloping, under the roofs).
 * Null where nothing is left of it.
 */
function bandPiece(
    material: THREE.Material,
    frame: WallFrame,
    wall: Wall,
    side: 'Left' | 'Right',
    [from, to]: [number, number],
    [low, high]: [number, number],
    caps: {top: HeightAt; bottom: HeightAt},
): THREE.Mesh | null {
    const sign = side === 'Left' ? 1 : -1;
    const face = (wall.thickness / 2) * sign;
    const outer = (wall.thickness / 2 + FacadeLining) * sign;
    const count = Math.max(1, Math.ceil((to - from) / FacadeStep));
    const alongs = Array.from({length: count + 1}, (_, i) => from + ((to - from) * i) / count);
    const bottoms = alongs.map((s) => Math.max(low, caps.bottom(pointOnWall(frame, s, face))));
    const tops = alongs.map((s, i) => Math.max(bottoms[i], Math.min(high, caps.top(pointOnWall(frame, s, face)))));
    if (tops.every((t, i) => t - bottoms[i] <= 0.5)) {
        return null;
    }
    const local = (p: Vec2): {x: number; z: number} => ({x: p.x * MM, z: -p.y * MM});
    const metres = (values: number[]): {right: number[]; left: number[]} => ({right: values.map((v) => v * MM), left: values.map((v) => v * MM)});
    const mesh = curvedPrismAt(
        material,
        alongs.map((s) => local(pointOnWall(frame, s, face))),
        alongs.map((s) => local(pointOnWall(frame, s, outer))),
        metres(bottoms),
        metres(tops),
        from * MM,
    );
    mesh.receiveShadow = true;
    return mesh;
}
