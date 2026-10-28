import {Item, Material, Opening, Room, Scene, Wall} from '@app/models/scene';

import {clipGap, FinishSegment, finishSegments} from './building';
import {
    ceilingHeightAt,
    EdgeRun,
    edgeRuns,
    joinedAlong,
    RoomEdge,
    subtractSpans,
    vaultProfile,
    wallFacesAlongEdge,
    wallSpansAlongEdge,
} from './ceilings';
import {herringboneLength, herringboneTiles} from './materials';
import {
    acrossWall,
    alongWall,
    DoorFrameWidth,
    itemFootprint,
    pointInPolygon,
    pointOnWall,
    polygonBounds,
    tangentAt,
    tileOrigin,
    Vec2,
    WallFrame,
    wallFrame,
    WallGap,
    wallGapsAlongEdge,
    wallPieces,
    WindowFrameBar,
} from './plan-geometry';
import {
    frameSvg,
    Ink,
    line,
    Margin,
    Muted,
    northArrowSvg,
    Padding,
    PaperLong,
    PaperShort,
    path,
    rect,
    Sheet,
    SheetInfo,
    sheetSvg,
    Style,
    text,
    textWidth,
    TitleBand,
    titleBlockSvg,
    truncate,
} from './sheet-svg';
import {CeilingUnderRoof, FloorRoofs, roofBreaks, roofsReach} from './under-roof';
import {hiddenItemIds} from './variants';

// Wall elevations for tiling and joinery: per room, every wall face along its outline seen from inside the room, on
// A4 pages. A face shows its finish bands with their joints as the viewer lays them: tiles, terrazzo and herringbone
// on the room's tile grid (`tileUv()`), bricks from the edge's start (`worldUvBox()`); the skirting, the doors and
// windows, the items standing against it and its dimensions. A key plan letters the faces round the room, and a
// legend lists the finishes.

/**
 * Scales tried for a room's elevations, the largest drawing first (1:n); a room that needs more runs on to more pages
 * at the last, and a face too wide for the paper at the last halves the drawing until it fits.
 */
export const ElevationScales = [20, 25, 50];
/** Room edges shorter than this (mm) get no elevation. */
const MinFace = 200;
/** How far (mm) an item may stand from the wall face and still count as against it. */
const AgainstWall = 60;
/** Tile joints closer than this on paper (mm) are left out: the band reads as a fill. */
const MinJointPitch = 1.2;
/** At most this many tiles of a herringbone are drawn in one piece of a band. */
const MaxPatternTiles = 4000;

/** Paper mm: the column with the key plan and legend, gaps between elevations, their title and dimension margins. */
const SideColumn = 52;
const KeyPlanSize = 44;
const LegendGap = 8;
const FaceGap = 5;
const FaceTitle = 5;
const FaceBelow = 11;
const FaceRight = 14;

const WallFill = '#ffffff';
const OpenFill = '#f7f5f1';
const OpenHatch = '#c9c3b8';
const NicheFill = '#e7e3dc';
const ItemFill = '#f3f0ea';
const JointColor = '#7d786f';
const DimensionColor = '#4a4640';
const SkirtingColor = '#f4f2ee';

/**
 * One wall face of a room as an elevation: the room edge a → b seen from inside (x runs from b to a), `height` its
 * tallest point, with what lies along the edge (mm from a): the walls' stretches, their openings and the finish bands.
 */
export interface ElevationFace {
    letter: string;
    a: Vec2;
    b: Vec2;
    length: number;
    /** Unit vector a → b, and the normal into the room. */
    dir: Vec2;
    normal: Vec2;
    /** The ceiling along the face, as elevation points (x, z) from left to right. */
    top: Vec2[];
    height: number;
    spans: [number, number][];
    gaps: WallGap[];
    openings: EdgeOpening[];
    segments: FinishSegment[];
    /**
     * A curved wall's face, unrolled: the room edges along it are one face, `length` the sum of theirs. `from` / `to`
     * are where a and b lie along the wall's centre line, `across` the face's distance from it (+ on its left).
     */
    curve?: {frame: WallFrame; across: number; from: number; to: number};
}

/** A, B, … Z, AA, AB, … */
export function faceLetter(index: number): string {
    const letter = String.fromCharCode(65 + (index % 26));
    return index < 26 ? letter : `${faceLetter(Math.floor(index / 26) - 1)}${letter}`;
}

/**
 * The faces of a room to draw: its outline's edges (counter-clockwise) with a wall along them, lettered A, B, … The
 * edges along one curved wall face are one face, unrolled. Under the floor's `roofs` a face's top follows the ceiling
 * where it slopes with them.
 */
export function roomFaces(room: Room, walls: readonly Wall[], roofs: FloorRoofs = []): ElevationFace[] {
    const faces: ElevationFace[] = [];
    const reaching = roofsReach(polygonBounds(room.polygon), roofs) ? roofs : [];
    for (const run of edgeRuns(room.polygon, walls)) {
        const face =
            run.curve !== null && run.edges.length > 1
                ? curvedFace(room, walls, run, run.curve, reaching)
                : straightFace(room, walls, run.edges[0], reaching);
        if (face !== null) {
            faces.push({...face, letter: faceLetter(faces.length)});
        }
    }
    return faces;
}

function straightFace(room: Room, walls: readonly Wall[], {a, b, length}: RoomEdge, roofs: FloorRoofs): ElevationFace | null {
    const spans = length < MinFace ? [] : wallSpansAlongEdge(a, b, walls);
    if (spans.length === 0) {
        return null;
    }
    const dir = {x: (b.x - a.x) / length, y: (b.y - a.y) / length};
    const edgeFaces = wallFacesAlongEdge(a, b, walls);
    const top = faceTop(room, (t) => ({x: a.x + dir.x * t, y: a.y + dir.y * t}), length, edgeFaces.map((f) => f.wall), roofs, true);
    return {
        letter: '',
        a,
        b,
        length,
        dir,
        normal: {x: -dir.y, y: dir.x},
        top,
        height: Math.max(...top.map((p) => p.y)),
        spans,
        gaps: wallGapsAlongEdge(a, b, walls),
        openings: edgeOpenings(a, dir, length, edgeFaces),
        segments: finishSegments(room, a, b, length, walls),
    };
}

/**
 * The run of room edges along a curved wall face as one face, unrolled: what lies along each edge, one after the other,
 * joined where it runs on; the openings placed by where they lie along the wall.
 */
function curvedFace(
    room: Room,
    walls: readonly Wall[],
    {edges: run, offsets, length}: EdgeRun,
    curve: NonNullable<EdgeRun['curve']>,
    roofs: FloorRoofs,
): ElevationFace | null {
    const frame = wallFrame(curve.wall);
    const shifted = <T extends {from: number; to: number}>(lists: T[][]): T[] =>
        lists.flatMap((list, i) => list.map((entry) => ({...entry, from: entry.from + offsets[i], to: entry.to + offsets[i]})));

    const spans = joinedAlong(
        shifted(run.map((edge) => wallSpansAlongEdge(edge.a, edge.b, walls).map(([from, to]) => ({from, to})))),
        () => true,
    ).map((span): [number, number] => [span.from, span.to]);
    if (length < MinFace || spans.length === 0) {
        return null;
    }
    const gaps = joinedAlong(
        shifted(run.map((edge) => wallGapsAlongEdge(edge.a, edge.b, walls))),
        (p, q) => p.bottom === q.bottom && p.top === q.top && p.niche === q.niche,
    );
    const segments = joinedAlong(
        shifted(run.map((edge) => finishSegments(room, edge.a, edge.b, edge.length, walls))),
        (p, q) => JSON.stringify(p.bands) === JSON.stringify(q.bands),
    );

    const a = run[0].a;
    const b = run[run.length - 1].b;
    const across = curve.side === 'Left' ? curve.wall.thickness / 2 : -curve.wall.thickness / 2;
    const from = alongWall(frame, a);
    const to = alongWall(frame, b);
    const position = (along: number): number => ((along - from) / (to - from)) * length;
    const openings = curve.wall.openings.flatMap((opening): EdgeOpening[] => {
        if (opening.type === 'Niche' && (opening.side ?? 'Left') !== curve.side) {
            return [];
        }
        const t0 = position(opening.offset);
        const t1 = position(opening.offset + opening.width);
        const start = Math.max(Math.min(t0, t1), 0);
        const end = Math.min(Math.max(t0, t1), length);
        const hinge = opening.hinge === null ? null : opening.hinge === 'End' ? t1 : t0;
        return end > start ? [{opening, wall: curve.wall, from: start, to: end, hinge}] : [];
    });

    const chord = Math.hypot(b.x - a.x, b.y - a.y);
    const dir = {x: (b.x - a.x) / chord, y: (b.y - a.y) / chord};
    const top = faceTop(room, (t) => pointOnWall(frame, from + ((to - from) * t) / length, across), length, [curve.wall], roofs, false);
    return {
        letter: '',
        a,
        b,
        length,
        dir,
        normal: {x: -dir.y, y: dir.x},
        top,
        height: Math.max(...top.map((p) => p.y)),
        spans,
        gaps,
        openings,
        segments,
        curve: {frame, across, from, to},
    };
}

/**
 * The ceiling along a face: flat at the room's height, the vaults and beams sampled along it, or for an open-air room
 * the tallest of the walls on the edge. Under `roofs` a flat ceiling slopes where they come lower (`ceilingHeightAt()`):
 * a `straight` face takes its points where the roofs' heights bend (`roofBreaks()`), a curved one samples them.
 */
function faceTop(room: Room, pointAt: (t: number) => Vec2, length: number, along: readonly Wall[], roofs: FloorRoofs, straight: boolean): Vec2[] {
    if (room.ceilingHeight === null) {
        const height = Math.max(0, ...along.map((w) => w.height)) || 2600;
        return [{x: 0, y: height}, {x: length, y: height}];
    }
    const vaulted = vaultProfile(room) !== null;
    if (!vaulted && roofs.length === 0) {
        return [{x: 0, y: room.ceilingHeight}, {x: length, y: room.ceilingHeight}];
    }
    // x runs from the face's end (b, t = length) to its start.
    const at = (x: number): Vec2 => {
        const point = pointAt(length - x);
        return {x, y: ceilingHeightAt(room, point.x, point.y, roofs) ?? room.ceilingHeight ?? 0};
    };
    if (!vaulted && straight) {
        // The ceiling bends where the roof's underside is `CeilingUnderRoof` over its height.
        const level = room.ceilingHeight + (room.floorElevation ?? 0) + CeilingUnderRoof;
        return roofBreaks(pointAt(length), pointAt(0), roofs, [level]).map((u) => at(u * length));
    }
    const steps = Math.max(2, Math.ceil(length / 20));
    return Array.from({length: steps + 1}, (_, i) => at((length * i) / steps));
}

/** The room's wall elevations on A4 landscape pages (none for a room without walls). */
export function roomElevationSheets(scene: Scene, room: Room, info: SheetInfo): Sheet[] {
    const faces = roomFaces(room, scene.walls, scene.roofs ?? []);
    if (faces.length === 0) {
        return [];
    }
    const width = PaperLong;
    const height = PaperShort;
    const materials = new Map(scene.materials.map((m) => [m.id, m]));
    const finishes = roomFinishes(room, faces, materials);
    const area: FaceArea = {
        left: Margin + Padding,
        top: Margin + Padding,
        width: width - 2 * (Margin + Padding),
        height: height - 2 * (Margin + Padding) - TitleBand,
        side: {width: SideColumn, height: KeyPlanSize + (finishes.length === 0 ? 0 : LegendGap + legendHeight(finishes.length))},
    };
    const {scale, pages} = layoutFaces(faces, area);
    const hidden = hiddenItemIds(scene);
    const items = scene.items.filter((i) => !hidden.has(i.id) && (i.roomId === room.id || pointInPolygon(i, room.polygon)));
    let clipIds = 0;
    const context: FaceContext = {room, walls: scene.walls, items, materials, scale, clipId: () => `clip-${clipIds++}`};

    return pages.map((placed, page) => {
        const body = placed.map(({face, at}) => faceSvg(face, at, context));
        body.push(keyPlanSvg(room, faces, {x: area.left, y: area.top}, scene.location?.northAngle ?? 0));
        body.push(legendSvg(finishes, {x: area.left, y: area.top + KeyPlanSize + LegendGap}));
        body.push(frameSvg(width, height));
        const pageNote = pages.length > 1 ? ` · ${page + 1}/${pages.length}` : '';
        body.push(titleBlockSvg(width, height, info, `Wall elevations · ${room.code} ${room.name} · 1:${scale} (A4)${pageNote}`));
        return {width, height, svg: sheetSvg(width, height, body)};
    });
}

/** The wall elevations of every room with walls, a page (or more) per room. */
export function elevationSheets(scene: Scene, info: SheetInfo): Sheet[] {
    return [...scene.rooms]
        .sort((x, y) => x.code.localeCompare(y.code, undefined, {numeric: true}))
        .flatMap((room) => roomElevationSheets(scene, room, info));
}

interface PlacedFace {
    face: ElevationFace;
    /** Paper position of the face's box (its title's top left). */
    at: Vec2;
}

/** The paper size of a face's box at a scale: the drawing with its title and dimensions. */
function faceBox(face: ElevationFace, scale: number): {width: number; height: number} {
    return {width: face.length / scale + FaceRight, height: FaceTitle + face.height / scale + FaceBelow};
}

/** The drawing area of a page, paper mm, with the column kept free at its top left (the key plan and legend). */
export interface FaceArea {
    left: number;
    top: number;
    width: number;
    height: number;
    side: {width: number; height: number};
}

/**
 * The faces flowed in rows over the area at the largest of `ElevationScales` that fits them on one page, every face
 * within the paper's width; at the last scale they run on to more pages (a face still too wide halves the scale).
 * Rows beside the side column start right of it, rows below it at the area's left; a face too wide for the room
 * beside the column starts a row below it.
 */
export function layoutFaces(faces: readonly ElevationFace[], area: FaceArea): {scale: number; pages: PlacedFace[][]} {
    const right = area.left + area.width;
    const belowSide = area.top + area.side.height + FaceGap;
    const flow = (scale: number): PlacedFace[][] => {
        const pages: PlacedFace[][] = [[]];
        const rowLeft = (y: number): number => (y < belowSide ? area.left + area.side.width : area.left);
        let y = area.top;
        let x = rowLeft(y);
        let rowHeight = 0;
        for (const face of faces) {
            const box = faceBox(face, scale);
            if (x > rowLeft(y) && x + box.width > right) {
                y += rowHeight + FaceGap;
                x = rowLeft(y);
                rowHeight = 0;
            }
            if (y < belowSide && x + box.width > right) {
                y = belowSide;
                x = area.left;
                rowHeight = 0;
            }
            if (y > area.top && y + box.height > area.top + area.height) {
                pages.push([]);
                y = area.top;
                x = rowLeft(y);
                rowHeight = 0;
            }
            pages[pages.length - 1].push({face, at: {x, y}});
            x += box.width + FaceGap;
            rowHeight = Math.max(rowHeight, box.height);
        }
        return pages;
    };
    const fits = (scale: number): boolean => faces.every((face) => faceBox(face, scale).width <= area.width);
    for (const scale of ElevationScales) {
        if (fits(scale)) {
            const pages = flow(scale);
            if (pages.length === 1) {
                return {scale, pages};
            }
        }
    }
    let scale = ElevationScales[ElevationScales.length - 1];
    while (!fits(scale)) {
        scale *= 2;
    }
    return {scale, pages: flow(scale)};
}

/** The finishes on a room's faces, for the legend: the bands' materials in the order met, then the skirting's. */
function roomFinishes(room: Room, faces: readonly ElevationFace[], materials: ReadonlyMap<number, Material>): Material[] {
    const ids = faces.flatMap((face) => face.segments.flatMap((segment) => segment.bands.map((band) => band.materialId)));
    if (room.skirtingHeight != null && room.skirtingHeight > 0 && room.skirtingMaterialId != null) {
        ids.push(room.skirtingMaterialId);
    }
    return [...new Set(ids)].flatMap((id) => materials.get(id) ?? []);
}

interface FaceContext {
    room: Room;
    walls: readonly Wall[];
    items: readonly Item[];
    materials: ReadonlyMap<number, Material>;
    scale: number;
    clipId: () => string;
}

/**
 * One elevation: the face's outline, and within it the finish bands, skirting, openings and items against it; then
 * its title and dimensions.
 */
function faceSvg(face: ElevationFace, at: Vec2, context: FaceContext): string {
    const {room, scale} = context;
    const s = scale;
    const left = at.x;
    const base = at.y + FaceTitle + face.height / s;
    const toPaper = (x: number, z: number): Vec2 => ({x: left + x / s, y: base - z / s});
    // Along the edge (t from a) to the elevation's x (from the left as seen from inside).
    const xOf = (t: number): number => face.length - t;
    const parts: string[] = [];

    const outline = [{x: 0, y: 0}, ...face.top, {x: face.length, y: 0}].map((p) => toPaper(p.x, p.y));
    const inside: string[] = [path(outline, true, {fill: WallFill})];
    // Where no wall stands along the edge (the room opens into another), hatched up to the lowest ceiling.
    const lowest = Math.min(...face.top.map((p) => p.y));
    for (const [from, to] of subtractSpans([[0, face.length]], face.spans)) {
        const [x0, x1] = [xOf(to), xOf(from)];
        const corner = toPaper(x0, lowest);
        const width = (x1 - x0) / s;
        const height = lowest / s;
        const hatch: string[] = [rect(corner, width, height, {fill: OpenFill})];
        for (let d = 0; d < width + height; d += 1.5) {
            hatch.push(line({x: corner.x + d, y: corner.y}, {x: corner.x + d - height, y: corner.y + height}, {stroke: OpenHatch, width: 0.1}));
        }
        inside.push(clippedToRect(context, toPaper, x0, x1, 0, lowest, hatch));
    }

    // Finish bands, round the openings. Tiles go on the room's grid: u (mm, as `tileUv()`) at x = 0 is the origin's
    // projection, growing to the right as seen from the front; bricks keep their surface's UVs, u from the edge's start a.
    const origin = tileOrigin(room);
    const gridPhase = face.curve === undefined ? -((face.b.x - origin.x) * face.dir.x + (face.b.y - origin.y) * face.dir.y) : curvedPhase(face.curve);
    for (const segment of face.segments) {
        for (const band of segment.bands) {
            const material = context.materials.get(band.materialId);
            if (material === undefined) {
                continue;
            }
            const phase = material.pattern === 'Brick' && face.curve === undefined ? -face.length : gridPhase;
            const bandGaps = face.gaps.flatMap((gap) => clipGap(gap, band.bottom, band.top, segment));
            for (const piece of wallPieces(segment.to - segment.from, band.top - band.bottom, bandGaps)) {
                const x0 = xOf(segment.from + piece.to);
                const x1 = xOf(segment.from + piece.from);
                const z0 = band.bottom + piece.bottom;
                const z1 = Math.min(band.bottom + piece.top, face.height);
                if (z1 > z0 && x1 > x0) {
                    inside.push(bandPieceSvg(material, x0, x1, z0, z1, phase, toPaper, context));
                }
            }
        }
    }

    // Skirting along the walls, except the doorways.
    if (room.skirtingHeight != null && room.skirtingHeight > 0) {
        const skirting = room.skirtingMaterialId == null ? undefined : context.materials.get(room.skirtingMaterialId);
        const doorways = face.gaps.filter((gap) => gap.bottom <= 0).map((gap): [number, number] => [gap.from, gap.to]);
        for (const [from, to] of subtractSpans(face.spans, doorways)) {
            const p = toPaper(xOf(to), room.skirtingHeight);
            inside.push(rect(p, (to - from) / s, room.skirtingHeight / s, {fill: skirting?.color ?? SkirtingColor, stroke: Ink, width: 0.13}));
        }
    }

    for (const opening of face.openings) {
        inside.push(openingSvg(opening, xOf, toPaper, s));
    }

    for (const item of itemsAgainst(face, context.items)) {
        const x0 = Math.max(0, xOf(item.to));
        const x1 = Math.min(face.length, xOf(item.from));
        const z0 = Math.max(0, item.item.z);
        const z1 = Math.min(face.height, item.item.z + item.item.height);
        if (x1 - x0 < 1 || z1 - z0 < 1) {
            continue;
        }
        inside.push(rect(toPaper(x0, z1), (x1 - x0) / s, (z1 - z0) / s, {fill: ItemFill, stroke: Muted, width: 0.18}));
        const size = Math.min(1.8, ((z1 - z0) / s) * 0.6);
        if (size >= 1.2 && textWidth(item.item.name, size) <= ((x1 - x0) / s) * 0.9) {
            const centre = toPaper((x0 + x1) / 2, (z0 + z1) / 2);
            inside.push(text({x: centre.x, y: centre.y + size * 0.35}, item.item.name, size, {color: Muted, anchor: 'middle'}));
        }
    }

    // Everything within the outline: under a vaulted ceiling the bands and items end at the vault, not its crown.
    parts.push(clipped(context, path(outline, true, {fill: '#000000'}), inside));
    parts.push(path(outline, true, {stroke: Ink, width: 0.35}));

    // Title: the face's letter and width.
    parts.push(text({x: left, y: at.y + 3.2}, face.letter, 3.6, {bold: true}));
    parts.push(text({x: left + 5, y: at.y + 3.2}, `${Math.round(face.length)} mm`, 2.4, {color: Muted}));

    parts.push(...widthDimensions(face, xOf, toPaper));
    parts.push(...heightDimensions(face, context, toPaper));
    return parts.join('');
}

/**
 * The phase of a curved face's joints: the viewer lays its patterns along the arc from the wall's start (`arcFaceUvs()`),
 * the distance along the face being u; seen from the room, x runs from b, one way or the other along the wall.
 */
function curvedPhase(curve: NonNullable<ElevationFace['curve']>): number {
    const arc = curve.frame.arc!;
    const scale = (arc.radius - Math.sign(arc.sweep) * curve.across) / arc.radius;
    const atB = curve.to * scale;
    return curve.from > curve.to ? atB : -atB;
}

/** A piece of a finish band: filled in the material's colour, with its joints (a herringbone clipped to the piece). */
function bandPieceSvg(
    material: Material,
    x0: number,
    x1: number,
    z0: number,
    z1: number,
    phase: number,
    toPaper: (x: number, z: number) => Vec2,
    context: FaceContext,
): string {
    const s = context.scale;
    const box = rect(toPaper(x0, z1), (x1 - x0) / s, (z1 - z0) / s, {fill: material.color});
    const w = material.tileWidth;
    const h = material.tileHeight;
    if (w === null || h === null || w <= 0 || h <= 0) {
        return box + bandEdges(x0, x1, z0, z1, toPaper);
    }
    const joint: Style = {stroke: JointColor, width: 0.08};
    const joints: string[] = [];
    if (material.pattern === 'Tiles' || material.pattern === 'Terrazzo') {
        if (w / s >= MinJointPitch && h / s >= MinJointPitch) {
            for (const x of gridLines(x0, x1, w, phase)) {
                joints.push(line(toPaper(x, z0), toPaper(x, z1), joint));
            }
            for (const z of gridLines(z0, z1, h, 0)) {
                joints.push(line(toPaper(x0, z), toPaper(x1, z), joint));
            }
        }
    } else if (material.pattern === 'Brick') {
        if (w / s >= MinJointPitch && h / s >= MinJointPitch) {
            for (const z of gridLines(z0, z1, h, 0)) {
                joints.push(line(toPaper(x0, z), toPaper(x1, z), joint));
            }
            // Stretcher bond: the courses counted from the floor, every other one shifted by half a brick.
            for (let course = Math.floor(z0 / h); course * h < z1; course++) {
                const bottom = Math.max(z0, course * h);
                const top = Math.min(z1, (course + 1) * h);
                const shift = course % 2 === 0 ? w / 2 : 0;
                for (const x of gridLines(x0, x1, w, phase - shift)) {
                    joints.push(line(toPaper(x, bottom), toPaper(x, top), joint));
                }
            }
        }
    } else if (material.pattern === 'Herringbone') {
        const tiles = herringboneShapes(x0, x1, z0, z1, phase, herringboneLength(w, h), h, s);
        if (tiles.length > 0) {
            const shapes = tiles.map((tile) => path(tile.map((p) => toPaper(p.x, p.y)), true, joint));
            return clippedToRect(context, toPaper, x0, x1, z0, z1, [box, ...shapes]);
        }
    }
    return box + joints.join('') + bandEdges(x0, x1, z0, z1, toPaper);
}

/** The top and bottom edges of a band piece, a little heavier than its joints. */
function bandEdges(x0: number, x1: number, z0: number, z1: number, toPaper: (x: number, z: number) => Vec2): string {
    const style: Style = {stroke: JointColor, width: 0.15};
    return line(toPaper(x0, z0), toPaper(x1, z0), style) + line(toPaper(x0, z1), toPaper(x1, z1), style);
}

/** The positions strictly between `from` and `to` where `phase + x` is a whole multiple of `pitch`. */
export function gridLines(from: number, to: number, pitch: number, phase: number): number[] {
    const lines: number[] = [];
    const first = Math.ceil((from + phase) / pitch) * pitch - phase;
    for (let x = first; x < to - 0.5; x += pitch) {
        if (x > from + 0.5) {
            lines.push(x);
        }
    }
    return lines;
}

/**
 * The herringbone's tiles over a band piece as polygons in elevation mm: the pattern of `herringboneTiles()` as the
 * viewer shows it, on the face's grid. The viewer samples its canvas with `flipY`, so the canvas rows run down the
 * texture's v: the tiles are reflected in y, then turned 45° as the texture is. Empty when the tiles are too small to
 * draw.
 */
function herringboneShapes(x0: number, x1: number, z0: number, z1: number, phase: number, length: number, width: number, scale: number): Vec2[][] {
    if (width / scale < MinJointPitch) {
        return [];
    }
    const c = Math.SQRT1_2;
    // Elevation (x, z) → u, v → the pattern's axes (turned back by 45°), and back.
    const toPattern = (x: number, z: number): Vec2 => ({x: c * (x + phase) + c * z, y: -c * (x + phase) + c * z});
    const fromPattern = (p: Vec2): Vec2 => ({x: c * p.x - c * p.y - phase, y: c * p.x + c * p.y});
    const corners = [toPattern(x0, z0), toPattern(x1, z0), toPattern(x0, z1), toPattern(x1, z1)];
    const size = 2 * length;
    const minX = Math.floor(Math.min(...corners.map((p) => p.x)) / size) - 1;
    const maxX = Math.ceil(Math.max(...corners.map((p) => p.x)) / size);
    const minY = Math.floor(Math.min(...corners.map((p) => p.y)) / size) - 1;
    const maxY = Math.ceil(Math.max(...corners.map((p) => p.y)) / size);
    const patch = herringboneTiles(length, width);
    if ((maxX - minX) * (maxY - minY) * patch.length > MaxPatternTiles) {
        return [];
    }
    const shapes: Vec2[][] = [];
    for (let i = minX; i <= maxX; i++) {
        for (let j = minY; j <= maxY; j++) {
            for (const [tx, ty, tw, th] of patch) {
                const x = tx + i * size;
                const y = -(ty + th) + j * size;
                shapes.push([{x, y}, {x: x + tw, y}, {x: x + tw, y: y + th}, {x, y: y + th}].map(fromPattern));
            }
        }
    }
    // Only the tiles over the piece; the clip trims those across its edges.
    return shapes.filter(
        (tile) =>
            Math.max(...tile.map((p) => p.x)) > x0 &&
            Math.min(...tile.map((p) => p.x)) < x1 &&
            Math.max(...tile.map((p) => p.y)) > z0 &&
            Math.min(...tile.map((p) => p.y)) < z1,
    );
}

/** Content clipped to a shape (an SVG element). */
function clipped(context: FaceContext, shape: string, content: string[]): string {
    const id = context.clipId();
    return `<clipPath id="${id}">${shape}</clipPath><g clip-path="url(#${id})">${content.join('')}</g>`;
}

/** Content clipped to an elevation rectangle. */
function clippedToRect(
    context: FaceContext,
    toPaper: (x: number, z: number) => Vec2,
    x0: number,
    x1: number,
    z0: number,
    z1: number,
    content: string[],
): string {
    const s = context.scale;
    return clipped(context, rect(toPaper(x0, z1), (x1 - x0) / s, (z1 - z0) / s, {fill: '#000000'}), content);
}

/** An opening on a face: the opening, its wall and its extent along the edge (t from a). */
export interface EdgeOpening {
    opening: Opening;
    wall: Wall;
    from: number;
    to: number;
    /** Where the opening's hinge edge lies along the edge (t from a); null for one without. */
    hinge: number | null;
}

/**
 * The openings of the wall faces on the edge (`wallFacesAlongEdge()`; a niche only on the face it is cut into),
 * projected onto it, as `wallGapsAlongEdge()` does.
 */
function edgeOpenings(a: Vec2, dir: Vec2, length: number, edgeFaces: readonly {wall: Wall; side: 'Left' | 'Right'}[]): EdgeOpening[] {
    const result: EdgeOpening[] = [];
    const along = (p: Vec2): number => (p.x - a.x) * dir.x + (p.y - a.y) * dir.y;
    const seen = new Set<string>();
    for (const {wall, side} of edgeFaces) {
        if (seen.has(`${wall.id}:${side}`)) {
            continue;
        }
        seen.add(`${wall.id}:${side}`);
        const frame = wallFrame(wall);
        for (const opening of wall.openings) {
            if (opening.type === 'Niche' && (opening.side ?? 'Left') !== side) {
                continue;
            }
            const t0 = along(pointOnWall(frame, opening.offset));
            const t1 = along(pointOnWall(frame, opening.offset + opening.width));
            const from = Math.max(Math.min(t0, t1), 0);
            const to = Math.min(Math.max(t0, t1), length);
            if (to > from) {
                const hinge = opening.hinge === null ? null : opening.hinge === 'End' ? t1 : t0;
                result.push({opening, wall, from, to, hinge});
            }
        }
    }
    return result;
}

/**
 * An opening seen from the room: cut out of the bands; a window with its frame, panes and solid panel; a door's leaves
 * with the dashed lines that point at the hinges (sliding ones with an arrow the way they open); a niche shaded.
 * Its size (and sill) is written in it when it fits.
 */
function openingSvg(edge: EdgeOpening, xOf: (t: number) => number, toPaper: (x: number, z: number) => Vec2, s: number): string {
    const {opening} = edge;
    const x0 = xOf(edge.to);
    const x1 = xOf(edge.from);
    const z0 = opening.sillHeight;
    const z1 = opening.sillHeight + opening.height;
    const box = (a: number, b: number, c: number, d: number, style: Style): string => rect(toPaper(a, d), (b - a) / s, (d - c) / s, style);
    const thin: Style = {stroke: Ink, width: 0.13};
    const parts: string[] = [box(x0, x1, z0, z1, {fill: opening.type === 'Niche' ? NicheFill : '#ffffff', stroke: Ink, width: 0.25})];
    const hingeX = edge.hinge === null ? null : xOf(edge.hinge);

    if (opening.type === 'Window') {
        const bar = Math.min(WindowFrameBar, (x1 - x0) / 4, (z1 - z0) / 4);
        const [ix0, ix1, iz0, iz1] = [x0 + bar, x1 - bar, z0 + bar, z1 - bar];
        parts.push(box(ix0, ix1, iz0, iz1, thin));
        const panes = opening.walkable === true && opening.mullions === 0 ? 1 : (opening.mullions ?? 1) + 1;
        for (let i = 1; i < panes; i++) {
            const x = ix0 + ((ix1 - ix0) * i) / panes;
            parts.push(line(toPaper(x, iz0), toPaper(x, iz1), thin));
        }
        const rows = (opening.transoms ?? 0) + 1;
        for (let i = 1; i < rows; i++) {
            const z = iz0 + ((iz1 - iz0) * i) / rows;
            parts.push(line(toPaper(ix0, z), toPaper(ix1, z), thin));
        }
        if (opening.panelHeight != null && opening.panelHeight > 0) {
            const z = Math.min(z0 + opening.panelHeight, iz1);
            parts.push(box(ix0, ix1, iz0, z, {fill: NicheFill, stroke: Ink, width: 0.13}));
        }
    } else if (opening.type === 'Door' || opening.type === 'DoubleDoor') {
        const frame = Math.min(DoorFrameWidth, (x1 - x0) / 4);
        const [lx0, lx1, top] = [x0 + frame, x1 - frame, z1 - frame];
        parts.push(box(lx0, lx1, z0, top, thin));
        const swing: Style = {stroke: Muted, width: 0.13, dash: '1 0.7'};
        const hingeLeft = hingeX !== null && Math.abs(hingeX - x0) < Math.abs(hingeX - x1);
        // Dashed lines from the free edge's corners to the middle of the hinged edge. A double door's leaf on `hinge`
        // is `leafWidth` wide (half by default), the other one the rest.
        const space = lx1 - lx0;
        const active = Math.min(Math.max(opening.leafWidth ?? space / 2, 0), space);
        const meeting = hingeLeft ? lx0 + active : lx1 - active;
        const leaves: [number, number][] =
            opening.type === 'DoubleDoor'
                ? [
                    [lx0, meeting],
                    [lx1, meeting],
                ]
                : [hingeLeft ? [lx0, lx1] : [lx1, lx0]];
        for (const [hinged, free] of leaves) {
            if (opening.type === 'DoubleDoor') {
                parts.push(line(toPaper(free, z0), toPaper(free, top), thin));
            }
            parts.push(path([toPaper(free, top), toPaper(hinged, (z0 + top) / 2), toPaper(free, z0)], false, swing));
        }
    } else if (opening.type === 'SlidingDoor' || opening.type === 'PocketDoor') {
        const frame = Math.min(DoorFrameWidth, (x1 - x0) / 4);
        parts.push(box(x0 + frame, x1 - frame, z0, z1 - frame, thin));
        if (hingeX !== null) {
            // It slides open towards the hinge edge.
            const towards = Math.abs(hingeX - x0) < Math.abs(hingeX - x1) ? -1 : 1;
            const mid = (z0 + z1) / 2;
            const centre = (x0 + x1) / 2;
            const reach = (x1 - x0) / 4;
            const tip = toPaper(centre + towards * reach, mid);
            parts.push(line(toPaper(centre - towards * reach, mid), tip, {stroke: Muted, width: 0.15}));
            const head = [{x: tip.x - towards * 1.2, y: tip.y - 0.7}, tip, {x: tip.x - towards * 1.2, y: tip.y + 0.7}];
            parts.push(path(head, false, {stroke: Muted, width: 0.15}));
        }
    }

    const size = 1.6;
    const label = `${Math.round(opening.width)} × ${Math.round(opening.height)}`;
    const sill = opening.sillHeight > 0 ? `sill ${Math.round(opening.sillHeight)}` : null;
    const widthOnPaper = (x1 - x0) / s;
    if (textWidth(label, size) <= widthOnPaper * 0.9 && (z1 - z0) / s >= 3 * size) {
        const centre = toPaper((x0 + x1) / 2, (z0 + z1) / 2);
        parts.push(text({x: centre.x, y: centre.y}, label, size, {color: Muted, anchor: 'middle'}));
        if (sill !== null && textWidth(sill, size) <= widthOnPaper * 0.9) {
            parts.push(text({x: centre.x, y: centre.y + size * 1.3}, sill, size, {color: Muted, anchor: 'middle'}));
        }
    }
    return parts.join('');
}

/** The items standing against a face (their footprint within `AgainstWall` of it, on the room's side), with their extent along it. */
export function itemsAgainst(face: ElevationFace, items: readonly Item[]): {item: Item; from: number; to: number}[] {
    return items
        .flatMap((item) => {
            const corners = itemFootprint(item);
            const curve = face.curve;
            // Along an unrolled curved face: seen square on at the item's place along it, and how far from the face.
            const along = curve === undefined
                ? corners.map((p) => (p.x - face.a.x) * face.dir.x + (p.y - face.a.y) * face.dir.y)
                : curvedExtent(face, curve, item, corners);
            const away = curve === undefined
                ? corners.map((p) => (p.x - face.a.x) * face.normal.x + (p.y - face.a.y) * face.normal.y)
                : corners.map((p) => (acrossWall(curve.frame, p) - curve.across) * Math.sign(curve.across));
            const from = Math.max(0, Math.min(...along));
            const to = Math.min(face.length, Math.max(...along));
            const near = Math.min(...away);
            return near <= AgainstWall && Math.max(...away) > 0 && to - from >= 50 ? [{item, from, to}] : [];
        })
        .sort((p, q) => p.item.z - q.item.z);
}

/**
 * Where an item's corners lie along an unrolled curved face: its place along the face, and its true width about it as
 * seen along the face's normal there.
 */
function curvedExtent(face: ElevationFace, curve: NonNullable<ElevationFace['curve']>, item: Item, corners: readonly Vec2[]): number[] {
    const centre = alongWall(curve.frame, item);
    const at = ((centre - curve.from) / (curve.to - curve.from)) * face.length;
    const tangent = tangentAt(curve.frame, centre);
    // The face runs the wall's way or against it.
    const sign = curve.to > curve.from ? 1 : -1;
    return corners.map((p) => at + sign * ((p.x - item.x) * tangent.x + (p.y - item.y) * tangent.y));
}

/** Under the face: the chain of piers and openings, and the face's whole width. */
function widthDimensions(face: ElevationFace, xOf: (t: number) => number, toPaper: (x: number, z: number) => Vec2): string[] {
    const stops = [
        ...new Set(
            [0, face.length, ...face.gaps.filter((g) => !g.niche).flatMap((g) => [xOf(g.from), xOf(g.to)])].map((x) => Math.round(x)),
        ),
    ].sort((p, q) => p - q);
    const parts: string[] = [];
    const floor = toPaper(0, 0).y;
    if (stops.length > 2) {
        parts.push(...dimension(stops.map((x) => toPaper(x, 0).x), floor + 4, 'horizontal', stops));
    }
    parts.push(...dimension([toPaper(0, 0).x, toPaper(face.length, 0).x], floor + 8.5, 'horizontal', [0, Math.round(face.length)]));
    return parts;
}

/** Right of the face: the band edges and skirting from the floor up, and the height to the ceiling (a vault's crown). */
function heightDimensions(face: ElevationFace, context: FaceContext, toPaper: (x: number, z: number) => Vec2): string[] {
    const {room} = context;
    const levels = new Set<number>([0]);
    for (const segment of face.segments) {
        for (const band of segment.bands) {
            levels.add(Math.round(Math.max(0, band.bottom)));
            levels.add(Math.round(Math.min(face.height, band.top)));
        }
    }
    if (room.skirtingHeight != null && room.skirtingHeight > 0) {
        levels.add(Math.round(room.skirtingHeight));
    }
    const right = face.height;
    const stops = [...levels].filter((z) => z <= right).sort((p, q) => p - q);
    const x = toPaper(face.length, 0).x;
    const parts: string[] = [];
    if (stops.length > 1) {
        parts.push(...dimension(stops.map((z) => toPaper(0, z).y), x + 4, 'vertical', stops));
    }
    parts.push(...dimension([toPaper(0, 0).y, toPaper(0, right).y], x + 9, 'vertical', [0, Math.round(right)]));
    return parts;
}

/**
 * A chain of dimensions on paper: `positions` (paper x for a horizontal chain at `across` = y, paper y for a vertical
 * one at x) with the lengths between the `values` (mm), 45° ticks and the lengths written where they fit.
 */
function dimension(positions: number[], across: number, orientation: 'horizontal' | 'vertical', values: number[]): string[] {
    const style: Style = {stroke: DimensionColor, width: 0.13};
    const at = (p: number, offset = 0): Vec2 => (orientation === 'horizontal' ? {x: p, y: across + offset} : {x: across + offset, y: p});
    const parts = [line(at(positions[0]), at(positions[positions.length - 1]), style)];
    const tick = 0.6;
    for (const p of positions) {
        const centre = at(p);
        parts.push(line({x: centre.x - tick, y: centre.y + tick}, {x: centre.x + tick, y: centre.y - tick}, style));
    }
    const size = 1.6;
    for (let i = 1; i < positions.length; i++) {
        const label = String(Math.abs(values[i] - values[i - 1]));
        const room = Math.abs(positions[i] - positions[i - 1]);
        if (textWidth(label, size) + 0.8 > room) {
            continue;
        }
        const middle = at((positions[i] + positions[i - 1]) / 2, -0.8);
        parts.push(
            orientation === 'horizontal'
                ? text(middle, label, size, {color: DimensionColor, anchor: 'middle'})
                : text(middle, label, size, {color: DimensionColor, anchor: 'middle', rotate: -90}),
        );
    }
    return parts;
}

/** The room's outline in a 45 mm square with the faces' letters inside their edges, plan north up; a north arrow beside it. */
function keyPlanSvg(room: Room, faces: readonly ElevationFace[], at: Vec2, northAngle: number): string {
    const box = KeyPlanSize;
    const xs = room.polygon.map(([x]) => x);
    const ys = room.polygon.map(([, y]) => y);
    const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
    const scale = Math.max(maxX - minX, maxY - minY, 1) / (box - 10);
    const ox = at.x + (box - (maxX - minX) / scale) / 2;
    const oy = at.y + (box - (maxY - minY) / scale) / 2;
    const toPaper = (p: Vec2): Vec2 => ({x: ox + (p.x - minX) / scale, y: oy + (maxY - p.y) / scale});
    const parts = [
        rect(at, box, box, {stroke: Ink, width: 0.2}),
        text({x: at.x + 1.5, y: at.y + 3.5}, truncate(`${room.code} ${room.name}`, box - 3, 2.2), 2.2, {bold: true}),
        path(room.polygon.map(([x, y]) => toPaper({x, y})), true, {fill: '#ffffff', stroke: Ink, width: 0.4}),
        // In the side column's spare width right of the box, clear of the outline and the title.
        northArrowSvg({x: at.x + box + (SideColumn - box) / 2, y: at.y + 10}, northAngle, 0.5),
    ];
    for (const face of faces) {
        const mid = {x: (face.a.x + face.b.x) / 2, y: (face.a.y + face.b.y) / 2};
        const curve = face.curve;
        const inside = toPaper(
            curve === undefined
                ? {x: mid.x + face.normal.x * 3 * scale, y: mid.y + face.normal.y * 3 * scale}
                : pointOnWall(curve.frame, (curve.from + curve.to) / 2, curve.across + Math.sign(curve.across) * 3 * scale),
        );
        parts.push(text({x: inside.x, y: inside.y + 1.1}, face.letter, 3, {bold: true, anchor: 'middle'}));
    }
    return parts.join('');
}

function legendHeight(entries: number): number {
    return 7 + entries * 6;
}

/** The finishes on the page: a swatch in the material's colour, its name and tile size. */
function legendSvg(materials: readonly Material[], at: Vec2): string {
    if (materials.length === 0) {
        return '';
    }
    const parts = [text({x: at.x, y: at.y + 3}, 'Finishes', 2.6, {bold: true})];
    materials.forEach((material, i) => {
        const y = at.y + 7 + i * 6;
        parts.push(rect({x: at.x, y}, 5, 4, {fill: material.color, stroke: Ink, width: 0.13}));
        const size = material.tileWidth !== null && material.tileHeight !== null ? `${material.tileWidth} × ${material.tileHeight}` : null;
        parts.push(text({x: at.x + 7, y: y + 1.8}, truncate(material.name, SideColumn - 9, 2.2), 2.2));
        if (size !== null) {
            parts.push(text({x: at.x + 7, y: y + 4.2}, `${size} mm`, 1.8, {color: Muted}));
        }
    });
    return parts.join('');
}
