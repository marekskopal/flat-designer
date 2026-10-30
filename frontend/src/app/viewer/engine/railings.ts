import {HandrailProfile, Railing, RailingStyle} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {mergeStatic} from './merge';
import {MM, Vec2, WallCollider, wallFrame} from './plan-geometry';

/** mm, a railing's posts (a stair's are thinner, `PostSize` in `stairs.ts`). */
export const RailingPost = 40;
/** mm, a round handrail's radius; a square one is twice as wide, a flat cap `FlatCap` wide and `FlatCapThickness` deep. */
export const HandrailRadius = 20;
export const FlatCap = 80;
export const FlatCapThickness = 25;
/** mm, a baluster's side. */
export const BalusterSize = 25;
/** mm, the rail the balusters stand on, over the railing's foot; and how far a pane or panel stops above it. */
export const BottomRail = 60;
export const InfillGap = 50;
/** mm, a rod's and a cable's radius. */
export const RodRadius = 6;
export const CableRadius = 2.5;
/** mm, a glass pane's and a panel's thickness. */
export const GlassThickness = 12;
export const PanelThickness = 20;
/** mm, how far a wall handrail stands off the wall (to its centre), and its brackets' farthest spacing. */
export const WallRailOffset = 60;
export const BracketSpacing = 1000;
/** Half the thickness of a railing's collider, mm. */
const ColliderHalf = 30;

const SteelColor = '#8d9092';

/** The clear gap between balusters, or the pitch of rods and cables, mm (`RailingStyleEnum::defaultSpacing()`). */
export function railingSpacing(style: RailingStyle, spacing: number | null): number {
    return spacing ?? (style === 'Rods' ? 120 : 100);
}

/** A point of a railing's top line, the top of its handrail: plan mm and height, mm above the group it is built in. */
export interface RailPoint {
    point: Vec2;
    top: number;
}

/** How a run of railing looks: its infill (null: posts and a handrail only), spacing, posts and handrail. */
export interface RailLook {
    style: RailingStyle | null;
    /** mm from the top line down to the foot line (a stair's: the nosings). */
    height: number;
    spacing: number;
    postSpacing: number;
    postSize: number;
    handrail: HandrailProfile;
}

/**
 * A railing (FD-90) in its group: the posts, handrail and infill along its points at `baseZ`, tagged `railingId` for
 * picking, merged. The group stands at the plan origin, so a drag moves it by its position.
 */
export function buildRailing(railing: Railing, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    group.name = `railing:${railing.name}`;
    group.userData = {railingId: railing.id};
    group.position.y = railing.baseZ * MM;
    const material = railing.materialId === null ? materials.plain(SteelColor, 0.35, 0.8) : materials.get(railing.materialId);
    const infill =
        railing.infillMaterialId !== null
            ? materials.get(railing.infillMaterialId)
            : railing.style === 'Glass'
              ? materials.byCode('glass')
              : material;
    const points = railing.points.map(([x, y]): RailPoint => ({point: {x, y}, top: railing.height}));
    group.add(
        buildRailRun(
            points,
            {
                style: railing.style,
                height: railing.height,
                spacing: railingSpacing(railing.style, railing.spacing),
                postSpacing: railing.postSpacing,
                postSize: RailingPost,
                handrail: railing.handrail,
            },
            material,
            infill,
        ),
    );
    mergeStatic(group);
    return group;
}

/**
 * A run of railing along its top line: posts at its corners and at most `postSpacing` apart (a glass one's only at its
 * ends and corners), the handrail along the top, and between the posts the infill from the foot line (`height` under the
 * top) up: balusters on a bottom rail, rods or cables parallel to the top, a glass pane or a panel. A stair's run slopes
 * with its top line.
 */
export function buildRailRun(points: readonly RailPoint[], look: RailLook, material: THREE.Material, infill: THREE.Material): THREE.Group {
    const group = new THREE.Group();
    group.name = 'railing-run';
    const at = (p: Vec2, height: number): THREE.Vector3 => new THREE.Vector3(p.x * MM, height * MM, -p.y * MM);
    const posts: {point: Vec2; top: number}[] = [];
    // The handrail's underside under the top line, where the posts and the infill stop.
    const handrailDrop = look.handrail === 'None' ? 0 : look.handrail === 'Flat' ? FlatCapThickness : 2 * HandrailRadius;

    points.forEach((b, i) => {
        if (i === 0) {
            posts.push(b);
            return;
        }
        const a = points[i - 1];
        const length = Math.hypot(b.point.x - a.point.x, b.point.y - a.point.y);
        if (length < 1) {
            return;
        }
        const along = (t: number): RailPoint => ({
            point: {x: a.point.x + (b.point.x - a.point.x) * t, y: a.point.y + (b.point.y - a.point.y) * t},
            top: a.top + (b.top - a.top) * t,
        });
        if (look.handrail !== 'None') {
            group.add(handrail(at(a.point, a.top), at(b.point, b.top), look.handrail, material));
        }
        if (look.style !== 'Glass') {
            const count = Math.ceil(length / look.postSpacing);
            for (let j = 1; j < count; j++) {
                posts.push(along(j / count));
            }
        }
        // A closed run (its last point back at the first) has its post there already.
        const first = points[0].point;
        if (i < points.length - 1 || b.point.x !== first.x || b.point.y !== first.y) {
            posts.push(b);
        }

        const foot = (p: RailPoint): number => p.top - look.height;
        switch (look.style) {
            case 'Balusters': {
                group.add(bar(at(a.point, foot(a) + BottomRail), at(b.point, foot(b) + BottomRail), BalusterSize, material));
                const pitch = look.spacing + BalusterSize;
                const count = Math.max(1, Math.round(length / pitch));
                for (let k = 0; k < count; k++) {
                    const p = along((k + 0.5) / count);
                    group.add(upright(p.point, foot(p) + BottomRail + BalusterSize / 2, p.top - handrailDrop, BalusterSize, material));
                }
                break;
            }
            case 'Rods':
            case 'Cable': {
                const radius = look.style === 'Rods' ? RodRadius : CableRadius;
                for (let drop = look.spacing; drop <= look.height - InfillGap; drop += look.spacing) {
                    group.add(tube(at(a.point, a.top - drop), at(b.point, b.top - drop), radius, material, 6));
                }
                break;
            }
            case 'Glass':
            case 'Panel': {
                const thickness = look.style === 'Glass' ? GlassThickness : PanelThickness;
                const pane = sheet(
                    [
                        at(a.point, foot(a) + InfillGap),
                        at(b.point, foot(b) + InfillGap),
                        at(b.point, b.top - handrailDrop),
                        at(a.point, a.top - handrailDrop),
                    ],
                    thickness,
                    infill,
                );
                pane.castShadow = look.style === 'Panel';
                group.add(pane);
                break;
            }
            case null:
                break;
        }
    });
    for (const post of posts) {
        group.add(upright(post.point, post.top - look.height, post.top - handrailDrop, look.postSize, material));
    }
    return group;
}

/**
 * A handrail on brackets on the wall beside a stair's flight: the rail along `path` (its top line) moved `WallRailOffset`
 * off the side towards the stair (`inward`: +1 to the right of the walking direction, −1 to the left), with brackets back
 * to the wall at most `BracketSpacing` apart.
 */
export function buildWallHandrail(path: readonly RailPoint[], inward: 1 | -1, material: THREE.Material): THREE.Group {
    const group = new THREE.Group();
    group.name = 'wall-handrail';
    const rail = offsetLine(path, inward * WallRailOffset);
    const at = (p: Vec2, height: number): THREE.Vector3 => new THREE.Vector3(p.x * MM, height * MM, -p.y * MM);
    rail.forEach((b, i) => {
        if (i === 0) {
            return;
        }
        const a = rail[i - 1];
        const length = Math.hypot(b.point.x - a.point.x, b.point.y - a.point.y);
        if (length < 1) {
            return;
        }
        group.add(tube(at(a.point, a.top), at(b.point, b.top), HandrailRadius, material, 10));
        const wallA = path[i - 1].point;
        const wallB = path[i].point;
        const count = Math.max(1, Math.ceil(length / BracketSpacing));
        for (let k = 0; k < count; k++) {
            const t = (k + 0.5) / count;
            const height = a.top + (b.top - a.top) * t - HandrailRadius;
            const from = {x: a.point.x + (b.point.x - a.point.x) * t, y: a.point.y + (b.point.y - a.point.y) * t};
            const to = {x: wallA.x + (wallB.x - wallA.x) * t, y: wallA.y + (wallB.y - wallA.y) * t};
            group.add(tube(at(from, height), at(to, height), 6, material, 6));
        }
    });
    return group;
}

/**
 * The line moved `distance` mm to the right of its direction (negative: to the left), mitred at its corners; the
 * heights stay.
 */
export function offsetLine(points: readonly RailPoint[], distance: number): RailPoint[] {
    const normals = points.slice(1).map((b, i) => {
        const a = points[i];
        const length = Math.hypot(b.point.x - a.point.x, b.point.y - a.point.y) || 1;
        return {x: (b.point.y - a.point.y) / length, y: -(b.point.x - a.point.x) / length};
    });
    return points.map((p, i) => {
        const before = normals[i - 1] ?? normals[i];
        const after = normals[i] ?? normals[i - 1];
        if (before === undefined || after === undefined) {
            return p;
        }
        const mx = before.x + after.x;
        const my = before.y + after.y;
        const m = Math.hypot(mx, my);
        // A turn back on itself has no mitre: the corner moves square to the way in.
        const scale = m < 1e-6 ? distance : distance / ((mx / m) * before.x + (my / m) * before.y);
        const n = m < 1e-6 ? before : {x: mx / m, y: my / m};
        return {point: {x: p.point.x + n.x * scale, y: p.point.y + n.y * scale}, top: p.top};
    });
}

/** What the walker bumps into at the railings: each stretch as a thin wall. */
export function railingColliders(railings: readonly Railing[]): WallCollider[] {
    return railings.flatMap((railing) =>
        railing.points.slice(1).flatMap(([x, y], i): WallCollider[] => {
            const [x0, y0] = railing.points[i];
            const frame = wallFrame({startX: x0, startY: y0, endX: x, endY: y});
            return frame.length > 0 ? [{frame, from: 0, to: frame.length, halfThickness: ColliderHalf}] : [];
        }),
    );
}

/** The handrail along the top line from `a` to `b` (scene metres) in its profile, hanging from it. */
function handrail(a: THREE.Vector3, b: THREE.Vector3, profile: HandrailProfile, material: THREE.Material): THREE.Mesh {
    const [width, depth] = profile === 'Flat' ? [FlatCap, FlatCapThickness] : [2 * HandrailRadius, 2 * HandrailRadius];
    const drop = new THREE.Vector3(0, (depth / 2) * MM, 0);
    const [from, to] = [a.clone().sub(drop), b.clone().sub(drop)];
    return profile === 'Round' ? tube(from, to, HandrailRadius, material, 10) : beam(from, to, width, depth, material);
}

/** A square bar `size` mm from `a` to `b`. */
function bar(a: THREE.Vector3, b: THREE.Vector3, size: number, material: THREE.Material): THREE.Mesh {
    return beam(a, b, size, size, material);
}

/** A box `width` × `depth` mm in section from `a` to `b`, its width level. */
function beam(a: THREE.Vector3, b: THREE.Vector3, width: number, depth: number, material: THREE.Material): THREE.Mesh {
    const length = a.distanceTo(b);
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(width * MM, depth * MM, length), material);
    mesh.position.copy(a).lerp(b, 0.5);
    // Local z along the bar, y as near up as the slope allows.
    mesh.quaternion.setFromRotationMatrix(new THREE.Matrix4().lookAt(a, b, new THREE.Vector3(0, 1, 0)));
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/** A round bar from `a` to `b`. */
function tube(a: THREE.Vector3, b: THREE.Vector3, radius: number, material: THREE.Material, segments: number): THREE.Mesh {
    const mesh = new THREE.Mesh(new THREE.CylinderGeometry(radius * MM, radius * MM, a.distanceTo(b), segments), material);
    mesh.position.copy(a).lerp(b, 0.5);
    mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), b.clone().sub(a).normalize());
    mesh.castShadow = true;
    return mesh;
}

/** A square upright `size` mm from `bottom` to `top` (mm) at a plan point. */
function upright(point: Vec2, bottom: number, top: number, size: number, material: THREE.Material): THREE.Mesh {
    const height = Math.max(top - bottom, 1);
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(size * MM, height * MM, size * MM), material);
    mesh.position.set(point.x * MM, ((bottom + top) / 2) * MM, -point.y * MM);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/**
 * A pane or panel `thickness` mm thick through four corners (scene metres, counter-clockwise seen from one side: two
 * along the foot, two along the top), so a stair's slopes with it.
 */
function sheet(corners: readonly THREE.Vector3[], thickness: number, material: THREE.Material): THREE.Mesh {
    const along = corners[1].clone().sub(corners[0]);
    const normal = new THREE.Vector3(-along.z, 0, along.x).normalize().multiplyScalar((thickness / 2) * MM);
    const front = corners.map((c) => c.clone().add(normal));
    const back = corners.map((c) => c.clone().sub(normal));
    const vertices = [...front, ...back];
    // Two faces and four edges, each two triangles.
    const quads = [
        [0, 1, 2, 3],
        [5, 4, 7, 6],
        [4, 5, 1, 0],
        [1, 5, 6, 2],
        [2, 6, 7, 3],
        [3, 7, 4, 0],
    ];
    const positions: number[] = [];
    for (const [a, b, c, d] of quads) {
        for (const index of [a, b, c, a, c, d]) {
            positions.push(vertices[index].x, vertices[index].y, vertices[index].z);
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.computeVertexNormals();
    const mesh = new THREE.Mesh(geometry, material);
    mesh.receiveShadow = true;
    return mesh;
}
