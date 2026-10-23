import {Material, Opening, Room, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildFloor, buildWall, buildWallFinishes} from './building';
import {MaterialLibrary} from './materials';
import {alongWall, MM, WallEnds, wallFrame} from './plan-geometry';
import {detectRoom} from './room-detect';

const niche: Opening = {
    id: 7,
    name: 'Niche',
    type: 'Niche',
    offset: 0,
    width: 800,
    height: 2600,
    sillHeight: 0,
    leafWidth: null,
    hinge: null,
    side: 'Left',
    entrance: false,
    depth: 100,
};

const wall: Wall = {
    id: 1,
    name: 'Wall',
    type: 'Interior',
    startX: 0,
    startY: 0,
    endX: 3000,
    endY: 0,
    thickness: 300,
    height: 2600,
    materialId: null,
    leftFinishes: null,
    rightFinishes: null,
    openings: [niche],
};

describe('buildWall', () => {
    const materials = new MaterialLibrary([], 1);
    const mm = (v: number): number => Math.round(v * 1000) + 0;

    it('mitres the piece behind a niche at a corner, following the mitre at its depth, and tags it with the niche', () => {
        // The left face runs 150 on to the corner, the right face is cut 150 short.
        const ends: WallEnds = {startLeft: 150, startRight: -150, endLeft: 0, endRight: 0};
        const group = buildWall(wall, materials, materials.sectionFill(), ends);
        const back = group.children.find((o) => o.userData['openingId'] === 7) as THREE.Mesh;
        const box = new THREE.Box3().setFromObject(back);

        // 100 of the 300 mm wall is cut away on the left: the piece spans z −50…150 and reaches −50 at its recessed face.
        expect([mm(box.min.z), mm(box.max.z)]).toEqual([-50, 150]);
        expect(mm(box.min.x)).toBe(-50);
        expect(mm(box.max.x)).toBe(800);
        expect([mm(box.min.y), mm(box.max.y)]).toEqual([0, 2600]);
    });

    it('keeps a square end square', () => {
        const group = buildWall(wall, materials, materials.sectionFill());
        const back = group.children.find((o) => o.userData['openingId'] === 7) as THREE.Mesh;
        const box = new THREE.Box3().setFromObject(back);
        expect([mm(box.min.x), mm(box.max.x), mm(box.min.z), mm(box.max.z)]).toEqual([0, 800, -50, 150]);
    });
});

describe('buildWall of a curved wall', () => {
    const materials = new MaterialLibrary([], 1);
    const mm = (v: number): number => Math.round(v * 1000) + 0;
    const window: Opening = {...niche, id: 8, name: 'Window', type: 'Window', offset: 3200, width: 700, height: 1200, sillHeight: 900, side: 'Left'};
    // Chord 4000 along x, the middle 1000 mm to the left (north: local −z).
    const curved: Wall = {...wall, endX: 4000, sagitta: 1000, thickness: 200, openings: [window]};

    it('stands its pieces along the arc', () => {
        const group = buildWall({...curved, openings: []}, materials, materials.sectionFill());
        const box = new THREE.Box3().setFromObject(group);
        // Out to the crown, less the few mm its straight pieces stand inside the arc.
        expect(mm(box.min.z)).toBeGreaterThanOrEqual(-1100);
        expect(mm(box.min.z)).toBeLessThanOrEqual(-1097);
        expect([mm(box.min.y), mm(box.max.y)]).toEqual([0, 2600]);
    });

    it('builds a window straight on the tangent at its middle', () => {
        const group = buildWall(curved, materials, materials.sectionFill());
        const part = group.children.find((o) => o.userData['openingId'] === 8)!;
        // 3550 mm along an arc of radius 2500 from (0, 0) about (2000, −1500), clockwise: past the crown, heading down.
        const angle = Math.atan2(0.6, -0.8) - 3550 / 2500;
        expect(part.rotation.y).toBeCloseTo(angle - Math.PI / 2, 6);
        const middle = {x: 2000 + 2500 * Math.cos(angle), y: -1500 + 2500 * Math.sin(angle)};
        // The window's middle at the wall's centre line (the frame is built from the wall start, along x).
        const local = new THREE.Vector3(3.55, 0, 0).applyEuler(part.rotation).add(part.position);
        expect([mm(local.x), mm(-local.z)]).toEqual([Math.round(middle.x), Math.round(middle.y)]);
    });
});

describe('buildWallFinishes', () => {
    const material = (id: number, code: string): Material => ({
        id,
        code,
        name: code,
        pattern: 'Plain',
        color: '#808080',
        roughness: 0.5,
        metalness: 0,
        texture: null,
        tileWidth: null,
        tileHeight: null,
    });
    const materials = new MaterialLibrary([material(1, 'herringbone'), material(2, 'terrazzo')], 1);
    const room: Room = {
        id: 1,
        code: 'B',
        name: 'Bathroom',
        type: 'Bathroom',
        polygon: [[0, 0], [2000, 0], [2000, 2000], [0, 2000]],
        area: 4,
        ceilingHeight: 2600,
        floorMaterialId: null,
        wallFinishes: [{materialId: 1, bottom: 0, top: 1200}],
        tileOriginX: null,
        tileOriginY: null,
    };
    // The south wall; its left face (start → end runs east) faces the room.
    const south = (leftFinishes: Wall['leftFinishes']): Wall => ({
        ...wall,
        id: 2,
        startX: -50,
        startY: -50,
        endX: 2050,
        endY: -50,
        thickness: 100,
        leftFinishes,
        openings: [],
    });
    const pieces = (group: THREE.Group, code: string): THREE.Mesh[] => {
        const meshes: THREE.Mesh[] = [];
        group.traverse((o) => {
            if (o instanceof THREE.Mesh && o.material === materials.byCode(code)) {
                meshes.push(o);
            }
        });
        return meshes;
    };

    it('lines a wall face with its own bands instead of the room\'s', () => {
        const group = buildWallFinishes(room, [south([{materialId: 2, bottom: 0, top: 2100}])], materials);
        const terrazzo = pieces(group, 'terrazzo');
        expect(terrazzo).toHaveLength(1);
        const box = new THREE.Box3().setFromObject(terrazzo[0]);
        expect([Math.round(box.min.x * 1000), Math.round(box.max.x * 1000), Math.round(box.max.y * 1000)]).toEqual([0, 2000, 2100]);
        // The other three edges keep the room's band.
        expect(pieces(group, 'herringbone')).toHaveLength(3);
    });

    it('leaves a face with an empty list bare, and gives the room\'s bands to a face without its own', () => {
        expect(pieces(buildWallFinishes(room, [south([])], materials), 'herringbone')).toHaveLength(3);
        expect(pieces(buildWallFinishes(room, [south(null)], materials), 'herringbone')).toHaveLength(4);
    });

    it('lays a curved face\'s bands along its arc, across the pieces of the outline', () => {
        // The south wall bows 300 mm out of the room; the room's outline follows its face in pieces.
        const curved: Wall = {...south([{materialId: 2, bottom: 0, top: 2100}]), sagitta: -300};
        const side = (id: number, start: [number, number], end: [number, number]): Wall => ({
            ...south(null),
            id,
            startX: start[0],
            startY: start[1],
            endX: end[0],
            endY: end[1],
        });
        const walls = [curved, side(3, [2050, -50], [2050, 2050]), side(4, [2050, 2050], [-50, 2050]), side(5, [-50, 2050], [-50, -50])];
        const detected = detectRoom(walls, {x: 1000, y: 1000});
        const polygon = 'polygon' in detected ? detected.polygon : [];
        expect(polygon.length).toBeGreaterThan(8);
        const group = buildWallFinishes({...room, polygon, wallFinishes: []}, walls, materials);
        group.updateMatrixWorld(true);
        const frame = wallFrame(curved);
        const scale = (frame.arc!.radius - 50) / frame.arc!.radius;
        const terrazzo = pieces(group, 'terrazzo');
        expect(terrazzo.length).toBeGreaterThan(1);
        // Every vertex's u is how far along the face (from the wall's start) it lies, in metres; v its height.
        for (const mesh of terrazzo) {
            const positions = mesh.geometry.getAttribute('position');
            const uv = mesh.geometry.getAttribute('uv');
            for (let i = 0; i < positions.count; i++) {
                const p = new THREE.Vector3().fromBufferAttribute(positions, i).applyMatrix4(mesh.matrixWorld);
                expect(uv.getX(i)).toBeCloseTo(alongWall(frame, {x: p.x / MM, y: -p.z / MM}) * scale * MM, 2);
                expect(uv.getY(i)).toBeCloseTo(p.y, 6);
            }
        }
    });
});

describe('buildFloor', () => {
    const materials = new MaterialLibrary(
        [
            {
                id: 1,
                code: 'oak',
                name: 'Oak',
                pattern: 'Plain',
                color: '#a07850',
                roughness: 0.5,
                metalness: 0,
                texture: null,
                tileWidth: null,
                tileHeight: null,
            },
        ],
        1,
    );
    const room = (floorAngle?: number): Room => ({
        id: 1,
        code: 'L',
        name: 'Living',
        type: 'Living',
        polygon: [[0, 0], [2000, 0], [2000, 1000], [0, 1000]],
        area: 2,
        ceilingHeight: 2600,
        floorMaterialId: 1,
        wallFinishes: [],
        tileOriginX: 1000,
        tileOriginY: 0,
        floorAngle,
    });
    // UV (mm) at the floor vertex over plan (x, y).
    const uvAt = (floor: THREE.Mesh, x: number, y: number): number[] => {
        const geometry = floor.geometry as THREE.BufferGeometry;
        const position = geometry.getAttribute('position');
        const uv = geometry.getAttribute('uv');
        for (let i = 0; i < position.count; i++) {
            if (Math.round(position.getX(i) * 1000) === x && Math.round(-position.getZ(i) * 1000) === y) {
                return [Math.round(uv.getX(i) * 1000) + 0, Math.round(uv.getY(i) * 1000) + 0];
            }
        }
        throw new Error(`No vertex at ${x}, ${y}`);
    };

    it('lays the pattern along the plan axes by default', () => {
        expect(uvAt(buildFloor(room(), materials), 2000, 1000)).toEqual([2000, 1000]);
    });

    it('turns the pattern about the tile origin by the floor angle', () => {
        const floor = buildFloor(room(90), materials);
        // Turned a quarter counter-clockwise about (1000, 0): plan +x runs along −v, plan +y along +u.
        expect(uvAt(floor, 0, 0)).toEqual([1000, 1000]);
        expect(uvAt(floor, 2000, 0)).toEqual([1000, -1000]);
        expect(uvAt(floor, 2000, 1000)).toEqual([2000, -1000]);
    });
});
