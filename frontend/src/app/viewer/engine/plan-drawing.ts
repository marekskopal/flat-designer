import {levelText} from '@app/core/level-text';
import {localDate} from '@app/core/local-date';
import {FloorVoid, isAncillaryRoom, isOutdoorRoom, Item, Opening, PlanPoint, Plot, Railing, Room, Scene, Wall} from '@app/models/scene';
import polygonClipping, {MultiPolygon} from 'polygon-clipping';

import {dimensionMiddle, dimensionPath, roomDimensions} from './dimensions';
import {itemsOverFloor} from './items';
import {
    Bounds,
    cornerWindows,
    frameBand,
    itemFootprint,
    Leaf,
    NoCorners,
    openingFrame,
    openingLeaves,
    pointInPolygon,
    pointOnWall,
    polygonCentroid,
    RollerDrum,
    SquareEnds,
    Vec2,
    WallEnds,
    WallFrame,
    wallFrame,
    wallJoints,
    wallOutline,
    WallPiece,
    wallPieces,
    wallSamples,
} from './plan-geometry';
import {roofPlanes, roofToPlan, roofVerticalThickness, roofWindowLayouts} from './roof-geometry';
import {
    frameSvg,
    Ink,
    line,
    Margin,
    Muted,
    n,
    northArrowSvg,
    Padding,
    PaperLong,
    PaperShort,
    path,
    rect,
    RuleColor,
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
import {StairGeometry, stairGeometry, StairLinePoint} from './stairs';
import {hasTerrain, plotTerrain, terrainContours} from './terrain';
import {belowLine, FloorRoofs, HeightAt, lowestTopAlong, roofBreaks, roofPlaneParts, roomUnderRoofs, wallHeightAt, wallTopAt} from './under-roof';
import {hiddenItemIds} from './variants';
import {netRoomArea, voidDiagonals} from './voids';

// The floor plan as a drawing on paper (A4, in paper mm): walls cut at `PlanCutHeight`, doors with their swings,
// windows, room names and areas, the rooms' dimension lines and the items' footprints, at a standard scale. Plain
// SVG text, so it is downloaded as it is and turned into a PDF page by `app/export/sheets-pdf.ts`.

/** Height above the floor (mm) at which the plan cuts the walls, as the viewer's plan view does. */
export const PlanCutHeight = 1300;
/** Scales tried for the plan, the largest drawing first (1:n). */
export const PlanScales = [50, 100, 200, 250, 500, 1000];
/** Dimension lines and the chains of piers and openings, paper mm from the wall face into the room. */
const DimensionPaperOffset = 5.5;
const ChainPaperOffset = 2.5;
/** Length of the 45° ticks at the ends of a dimension line, paper mm. */
const TickPaper = 1.2;
/** Gap between a glazing line and the window's centre line, mm. */
const GlassGap = 20;
const WallFill = '#2f2c28';
const ItemFill = '#f3f0ea';
const IndoorFill = '#ffffff';
/** A property line: long dash, dot (paper mm). */
const PlotDash = '6 1.2 0.6 1.2';
const OutdoorFill = '#edf2e6';

/** A polygon or polyline in plan mm. */
interface Shape {
    points: Vec2[];
    closed: boolean;
    style: Style;
}

/**
 * Text at a plan point; `angle` turns it along a plan direction (radians counter-clockwise), never upside down. The
 * `shapes` belong to it (a dimension's line and ticks) and are drawn only with it.
 */
interface Label {
    at: Vec2;
    lines: {text: string; size: number; bold?: boolean; color?: string}[];
    angle?: number;
    shapes?: Shape[];
}

/** An axis-aligned box on the paper, mm. */
interface Box {
    left: number;
    top: number;
    right: number;
    bottom: number;
}

/** How the plan lies on the paper. */
export interface PlanLayout {
    orientation: 'landscape' | 'portrait';
    width: number;
    height: number;
    /** 1:scale. */
    scale: number;
    /** Paper position of the plan's north-west bounds corner (minX, maxY). */
    origin: Vec2;
}

/**
 * The paper and standard scale that show the plan's `bounds` (mm) the largest: the scale is the first of
 * `PlanScales` that fits, landscape or portrait, whichever needs the smaller one (landscape for a wide plan on a tie).
 * A plan too large for all of them gets the next scale in hundreds.
 */
export function planLayout(bounds: Bounds): PlanLayout {
    const planWidth = Math.max(bounds.maxX - bounds.minX, 1);
    const planHeight = Math.max(bounds.maxY - bounds.minY, 1);
    const fit = (width: number, height: number): PlanLayout => {
        const areaWidth = width - 2 * (Margin + Padding);
        const areaHeight = height - 2 * (Margin + Padding) - TitleBand;
        const scale =
            PlanScales.find((s) => planWidth / s <= areaWidth && planHeight / s <= areaHeight) ??
            Math.ceil(Math.max(planWidth / areaWidth, planHeight / areaHeight) / 100) * 100;
        const origin = {
            x: Margin + Padding + (areaWidth - planWidth / scale) / 2,
            y: Margin + Padding + (areaHeight - planHeight / scale) / 2,
        };
        return {orientation: width > height ? 'landscape' : 'portrait', width, height, scale, origin};
    };
    const landscape = fit(PaperLong, PaperShort);
    const portrait = fit(PaperShort, PaperLong);
    if (landscape.scale !== portrait.scale) {
        return landscape.scale < portrait.scale ? landscape : portrait;
    }
    return planWidth >= planHeight ? landscape : portrait;
}

/** The floor plan on one A4 page, with the title block, a scale bar and a north arrow. */
export function planSheet(scene: Scene, info: SheetInfo): Sheet & {scale: number} {
    const hidden = hiddenItemIds(scene);
    const items = itemsOverFloor(scene).filter((i) => !hidden.has(i.id)).sort((a, b) => a.z - b.z);
    const rooms = scene.rooms.map(roomShape);
    const plots = (scene.plots ?? []).map(plotShape);
    const contours = (scene.plots ?? []).flatMap(contourShapes);
    const itemShapes = items.map(itemShape);
    const walls = wallShapes(scene.walls, scene.roofs ?? []);
    const openings = scene.walls.flatMap((wall) => openingShapes(wall, scene.roofs ?? []));
    const stairs = stairPlanShapes(scene);
    const roofs = roofPlanShapes(scene.roofs ?? []);
    const voids = (scene.voids ?? []).flatMap(voidShapes);
    const railings = (scene.railings ?? []).map(railingShape);

    const bounds = shapeBounds([...plots, ...rooms, ...itemShapes, ...walls, ...openings, ...stairs.shapes, ...roofs, ...voids, ...railings]);
    const layout = planLayout(bounds);
    const s = layout.scale;
    const toPaper = (p: Vec2): Vec2 => ({x: layout.origin.x + (p.x - bounds.minX) / s, y: layout.origin.y + (bounds.maxY - p.y) / s});
    const crosses = (scene.plots ?? []).flatMap((plot) => spotHeightCrosses(plot, s));

    // Room names first, then the dimensions and the item names where they do not run into the text placed before them.
    const roomLabels = scene.rooms.map((room) => roomLabel({...room, area: netRoomArea(room, scene.voids ?? [])}, s));
    const taken = roomLabels.map((label) => labelBox(label, toPaper));
    const free = (label: Label): boolean => {
        const box = labelBox(label, toPaper);
        if (taken.some((other) => overlaps(box, other))) {
            return false;
        }
        taken.push(box);
        return true;
    };
    const dimensions = dimensionLabels(scene, s).filter(free);
    const spots = (scene.plots ?? []).flatMap((plot) => spotHeightLabels(plot, s)).filter(free);
    const itemLabels = items.flatMap((item) => itemLabel(item, s)).filter(free);
    const labels = [...spots, ...itemLabels, ...stairs.labels, ...roomLabels, ...dimensions];

    const body = [
        ...[
            ...[...plots, ...contours, ...crosses, ...rooms, ...voids, ...stairs.shapes, ...railings, ...roofs, ...walls, ...openings, ...itemShapes],
            ...labels.flatMap((l) => l.shapes ?? []),
        ].map((shape) => shapeSvg(shape, toPaper)),
        ...labels.map((label) => labelSvg(label, toPaper)),
        frameSvg(layout.width, layout.height),
        planBandSvg(layout, info, scene.location?.northAngle ?? 0),
    ];
    return {width: layout.width, height: layout.height, scale: s, svg: sheetSvg(layout.width, layout.height, body)};
}

/**
 * The room schedule of the floor on A4 portrait pages: area, perimeter, clear height and volume per room, with totals.
 * A room under the roofs (`roomUnderRoofs()`, as the backend's schedule) gets its clear height's range and its volume
 * under the slope, and a column its area with at least 1.2 m of headroom. The floor's voids (FD-90) are taken off the
 * rooms' areas (`netRoomArea()`) and listed after them, in no total.
 */
export function roomScheduleSheets(scene: Scene, info: SheetInfo): Sheet[] {
    if (scene.rooms.length === 0) {
        return [];
    }
    const roofs = scene.roofs ?? [];
    const measured = scene.rooms.map((gross) => {
        const room = {...gross, area: netRoomArea(gross, scene.voids ?? [])};
        // Under a roof the volume and the headroom scale with the share of the floor the voids leave (as the backend's schedule).
        const left = gross.area > 0 ? room.area / gross.area : 1;
        const measuredUnder = roomUnderRoofs(room, roofs);
        const under =
            measuredUnder === null ? null : {...measuredUnder, volume: left * measuredUnder.volume, headroomArea: left * measuredUnder.headroomArea};
        const volume = under !== null ? under.volume / 1e9 : room.ceilingHeight === null ? null : (room.area * room.ceilingHeight) / 1000;
        return {room, under, volume};
    });
    const underRoofs = measured.some((m) => m.under !== null);
    const metres = (mm: number): string => (Math.round(mm) / 1000).toFixed(2);
    const rows = [...measured]
        .sort((a, b) => a.room.code.localeCompare(b.room.code, undefined, {numeric: true}))
        .map(({room, under, volume}): string[] => {
            const low = under !== null && Math.round(under.minHeight) !== Math.round(under.maxHeight) ? `${metres(under.minHeight)}–` : '';
            const height = under !== null ? under.maxHeight : room.ceilingHeight;
            return [
                room.code,
                room.name,
                room.area.toFixed(2),
                (room.perimeter ?? perimeter(room) / 1000).toFixed(2),
                height === null ? '–' : `${low}${metres(height)}`,
                volume === null ? '–' : volume.toFixed(1),
                ...(underRoofs ? [under === null ? '' : (under.headroomArea / 1e6).toFixed(2)] : []),
            ];
        });
    const voidRows = (scene.voids ?? []).map((v): string[] => ['', `${v.name} (void)`, v.area.toFixed(2), '', '', '', ...(underRoofs ? [''] : [])]);
    // The living area, garages (indoor, unheated; FD-89) and open-air rooms, each totalled apart.
    const kind = (room: Pick<Room, 'type'>): string => (isOutdoor(room) ? 'Outdoor' : isAncillaryRoom(room) ? 'Garage' : 'Indoor');
    const total = (label: string): string[] | null => {
        const matching = measured.filter((m) => kind(m.room) === label);
        if (matching.length === 0) {
            return null;
        }
        const area = matching.reduce((sum, m) => sum + m.room.area, 0);
        const volume = matching.reduce((sum, m) => sum + (m.volume ?? 0), 0);
        return ['', label, area.toFixed(2), '', '', volume > 0 ? volume.toFixed(1) : '', ...(underRoofs ? [''] : [])];
    };
    const totals = [total('Indoor'), total('Garage'), total('Outdoor')].filter((t) => t !== null);

    const width = PaperShort;
    const height = PaperLong;
    const left = Margin + Padding;
    const right = width - Margin - Padding;
    // Code, room (left-aligned at their x), then the numbers right-aligned at theirs.
    const shift = underRoofs ? 24 : 0;
    const columns: {x: number; end?: boolean; header: string}[] = [
        {x: left, header: 'Code'},
        {x: left + 22, header: 'Room'},
        {x: right - 66 - shift, end: true, header: 'Area m²'},
        {x: right - 44 - shift, end: true, header: 'Perimeter m'},
        {x: right - 20 - shift, end: true, header: 'Clear height m'},
        {x: right - shift, end: true, header: 'Volume m³'},
        ...(underRoofs ? [{x: right, end: true, header: '≥ 1.2 m, m²'}] : []),
    ];
    const RowHeight = 6.5;
    const tableTop = Margin + Padding + 20;
    const tableBottom = height - Margin - Padding - 8;
    const perPage = Math.floor((tableBottom - tableTop) / RowHeight) - 1;
    const allRows = [...[...rows, ...voidRows].map((cells) => ({cells, bold: false})), ...totals.map((cells) => ({cells, bold: true}))];
    const pages = Math.max(1, Math.ceil(allRows.length / perPage));

    return Array.from({length: pages}, (_, page) => {
        const body: string[] = [
            text({x: left, y: Margin + Padding + 5}, 'Room schedule', 5, {bold: true}),
            text({x: left, y: Margin + Padding + 11}, `${info.projectName} · ${info.floorName}`, 2.8, {color: Muted}),
        ];
        const row = (cells: string[], y: number, bold: boolean): void => {
            cells.forEach((cell, i) => {
                const column = columns[i];
                const value = i === 1 ? truncate(cell, columns[2].x - column.x - 24, 2.6) : cell;
                body.push(text({x: column.x, y}, value, 2.6, {bold, anchor: column.end === true ? 'end' : 'start'}));
            });
        };
        row(
            columns.map((c) => c.header),
            tableTop,
            true,
        );
        body.push(line({x: left, y: tableTop + 2}, {x: right, y: tableTop + 2}, {stroke: Ink, width: 0.3}));
        allRows.slice(page * perPage, (page + 1) * perPage).forEach((r, i) => {
            const y = tableTop + (i + 1) * RowHeight;
            if (r.bold && (i === 0 || !allRows[page * perPage + i - 1].bold)) {
                body.push(line({x: left, y: y - RowHeight + 2}, {x: right, y: y - RowHeight + 2}, {stroke: Ink, width: 0.3}));
            }
            row(r.cells, y, r.bold);
            body.push(line({x: left, y: y + 2}, {x: right, y: y + 2}, {stroke: RuleColor, width: 0.15}));
        });
        const footer = `${info.projectName} · ${info.floorName} · ${localDate(info.date)}${pages > 1 ? ` · ${page + 1}/${pages}` : ''}`;
        body.push(text({x: right, y: height - Margin - Padding}, footer, 2.2, {color: Muted, anchor: 'end'}));
        body.push(frameSvg(width, height));
        return {width, height, svg: sheetSvg(width, height, body)};
    });
}

// --- Plan shapes (plan mm) ---

function isOutdoor(room: Pick<Room, 'type'>): boolean {
    return isOutdoorRoom(room);
}

/** The roofs above the cut: their outlines with the overhangs, ridges, hips and valleys, and their windows, dashed. */
const RoofStyle: Style = {stroke: Ink, width: 0.18, dash: '2 0.8'};
/** A roof under the cut, seen from above, and the section where the cut passes through a slab (outlined, unlike a wall's). */
const RoofSeenStyle: Style = {stroke: Ink, width: 0.25};
const RoofSectionStyle: Style = {fill: WallFill, stroke: Ink, width: 0.1};

/**
 * The roofs over the floor as the plan shows them: the edges of each plane where it is the top (`roofPlaneParts()`, so
 * the outline with the overhangs, the ridge and hips, and the valleys where roofs meet), each drawn once, and the
 * windows' outlines in plan. Where the plan's cut (`PlanCutHeight`) passes through a slab (a low roof, an attic's
 * eaves) it is a section, filled as the walls are; a slab wholly under the cut is seen from above, its edges solid;
 * the rest is above the cut, dashed.
 */
function roofPlanShapes(roofs: FloorRoofs): Shape[] {
    const dashed: Shape[] = [];
    const seen: Shape[] = [];
    const sections: Shape[] = [];
    const drawn = new Set<string>();
    const key = (p: Vec2): string => `${Math.round(p.x)},${Math.round(p.y)}`;
    const edges = (region: MultiPolygon, style: Style, into: Shape[]): void => {
        for (const ring of region.flat(1)) {
            const points = ring.slice(0, -1).map(([x, y]): Vec2 => ({x, y}));
            points.forEach((a, i) => {
                const b = points[(i + 1) % points.length];
                const edge = [key(a), key(b)].sort().join(' ');
                if (!drawn.has(edge) && key(a) !== key(b)) {
                    drawn.add(edge);
                    into.push({points: [a, b], closed: false, style});
                }
            });
        }
    };
    for (const roof of roofs) {
        const thick = roofVerticalThickness(roof);
        const planes = roofPlanes(roof);
        roofPlaneParts(roof, roofs).forEach((parts, k) => {
            // The underside over the plane: k0 + g · p.
            const g = planes[k].gradient;
            const k0 = planes[k].height - g.x * roof.x - g.y * roof.y;
            const above = polygonClipping.intersection(parts, belowLine({x: -g.x, y: -g.y}, k0 - PlanCutHeight));
            const under = polygonClipping.intersection(parts, belowLine(g, PlanCutHeight - thick - k0));
            edges(above, RoofStyle, dashed);
            edges(under, RoofSeenStyle, seen);
            for (const [outer] of polygonClipping.difference(parts, above, under)) {
                sections.push({points: outer.slice(0, -1).map(([x, y]): Vec2 => ({x, y})), closed: true, style: RoofSectionStyle});
            }
        });
        for (const {layout} of roofWindowLayouts(roof)) {
            dashed.push({points: layout.corners.map((c) => roofToPlan(roof, c.x, c.y)), closed: true, style: RoofStyle});
        }
    }
    return [...dashed, ...seen, ...sections];
}

/** Contour lines of a sloping plot, mm apart; every `ContourMajor` mm drawn heavier. */
const ContourInterval = 500;
const ContourMajor = 1000;

/**
 * The contour lines of a plot's sloping ground (`terrainContours()`) inside its boundary, under the rooms; the house
 * draws over them.
 */
function contourShapes(plot: Plot): Shape[] {
    if (!hasTerrain(plot)) {
        return [];
    }
    return terrainContours(plotTerrain(plot), ContourInterval).flatMap(({level, segments}) => {
        const style: Style = {stroke: ContourColor, width: level % ContourMajor === 0 ? 0.18 : 0.09};
        return segments
            .filter(([a, b]) => pointInPolygon({x: (a[0] + b[0]) / 2, y: (a[1] + b[1]) / 2}, plot.polygon))
            .map(([a, b]): Shape => ({points: [{x: a[0], y: a[1]}, {x: b[0], y: b[1]}], closed: false, style}));
    });
}
const ContourColor = '#9a8f7c';

/** A plot's spot heights as small crosses. */
function spotHeightCrosses(plot: Plot, scale: number): Shape[] {
    const arm = SpotArm * scale;
    const style: Style = {stroke: Ink, width: 0.13};
    return (plot.terrain ?? []).flatMap(([x, y]): Shape[] => [
        {points: [{x: x - arm, y: y - arm}, {x: x + arm, y: y + arm}], closed: false, style},
        {points: [{x: x - arm, y: y + arm}, {x: x + arm, y: y - arm}], closed: false, style},
    ]);
}

/** The levels of a plot's spot heights (+1.250) beside their crosses, where they do not run into other text. */
function spotHeightLabels(plot: Plot, scale: number): Label[] {
    return (plot.terrain ?? []).map(([x, y, z]) => ({
        at: {x: x + 3.2 * scale, y: y - 1.6 * scale},
        lines: [{text: levelText(z), size: 1.5, color: Muted}],
    }));
}
/** Half the spot height's cross, paper mm. */
const SpotArm = 0.7;

/** A plot's boundary: the dash-dot line of a property line. */
function plotShape(plot: Plot): Shape {
    return {points: plot.polygon.map(([x, y]) => ({x, y})), closed: true, style: {stroke: Ink, width: 0.35, dash: PlotDash}};
}

/** A void (FD-90): its outline crossed by its diagonals, as voids are drawn on plans. */
function voidShapes(floorVoid: FloorVoid): Shape[] {
    const points = floorVoid.polygon.map(([x, y]) => ({x, y}));
    return [
        {points, closed: true, style: {...VoidStyle, fill: '#ffffff'}},
        ...voidDiagonals(floorVoid.polygon).map((diagonal): Shape => ({points: diagonal.map(([x, y]) => ({x, y})), closed: false, style: VoidStyle})),
    ];
}

/** A railing (FD-90): a thin line along it. */
function railingShape(railing: Railing): Shape {
    return {points: railing.points.map(([x, y]) => ({x, y})), closed: false, style: {stroke: Ink, width: 0.25}};
}

function roomShape(room: Room): Shape {
    return {points: room.polygon.map(([x, y]) => ({x, y})), closed: true, style: {fill: isOutdoor(room) ? OutdoorFill : IndoorFill}};
}

/** The level at which a wall is cut: `PlanCutHeight`, or half its height for a wall lower than that. */
function cutLevel(wall: Pick<Wall, 'height'>): number {
    return levelFor(wall.height);
}

/** The cut through a wall `top` mm high: `PlanCutHeight`, or half the height of a knee wall under it (drawn as a low wall). */
function levelFor(top: number): number {
    return top > PlanCutHeight ? PlanCutHeight : top / 2;
}

/** Whether the plan's cut passes through an opening (the wall is open there in the plan). */
function cutsThrough(opening: Opening, level: number): boolean {
    return opening.sillHeight <= level && level < opening.sillHeight + opening.height;
}

/**
 * The stretches (mm along the centre line) of a piece the plan's cut passes through under the roofs: where the cut
 * (`levelFor()` the wall's `top` there) lies between the piece's bottom and its top (the wall's for a piece reaching
 * it). The top is piecewise linear (`roofBreaks()`), and every height the answer turns on is a break, so the middle of
 * a stretch decides for all of it.
 */
function cutStretches(
    frame: WallFrame,
    piece: WallPiece,
    top: HeightAt,
    roofs: FloorRoofs,
    wallHeight: number,
    slope: HeightAt | null = null,
): [number, number][] {
    const levels = [PlanCutHeight, 2 * piece.bottom, 2 * piece.top, wallHeight, piece.top];
    // A sloping top (FD-87) bends where it crosses the cut, or where half of it does (a low wall's cut).
    const slopes: HeightAt[] = slope === null ? [] : [slope, (p): number => slope(p) / 2];
    const spans: [number, number][] = [];
    const samples = wallSamples(frame, piece.from, piece.to);
    for (let i = 1; i < samples.length; i++) {
        const [a, b] = [samples[i - 1], samples[i]];
        const ts = roofBreaks(pointOnWall(frame, a), pointOnWall(frame, b), roofs, levels, slopes);
        for (let k = 1; k < ts.length; k++) {
            const [s0, s1] = [a + (b - a) * ts[k - 1], a + (b - a) * ts[k]];
            const height = top(pointOnWall(frame, (s0 + s1) / 2));
            const level = levelFor(height);
            const pieceTop = piece.top >= wallHeight ? height : Math.min(piece.top, height);
            if (!(height > 0) || piece.bottom > level || level >= pieceTop) {
                continue;
            }
            const last = spans.at(-1);
            if (last !== undefined && s0 <= last[1] + 0.5) {
                last[1] = s1;
            } else {
                spans.push([s0, s1]);
            }
        }
    }
    return spans;
}

/**
 * The cut walls, filled: the wall pieces that reach the cut level (a niche's piece thinner by its depth), merged
 * where they touch, with the mitred corners of `wallJoints()` at the wall ends, except at a corner window, where the
 * corner stays open. Under the `roofs` the cut follows the wall's top (`wallTopAt()`): where the roof brings it under
 * `PlanCutHeight`, the wall is drawn as a low wall, cut at half its top, as a knee wall is.
 */
function wallShapes(walls: readonly Wall[], roofs: FloorRoofs = []): Shape[] {
    const joints = wallJoints(walls);
    const corners = cornerWindows(walls);
    return walls.flatMap((wall) => {
        const frame = wallFrame(wall);
        if (!(frame.length > 0)) {
            return [];
        }
        const level = cutLevel(wall);
        const top = wallTopAt(wall, roofs, frame);
        const slope = wallHeightAt(wall, frame);
        const pieces = wallPieces(frame.length, wall.height, shownOpenings(wall, frame, top, roofs, slope))
            .flatMap((p) => {
                if (top === null) {
                    return p.bottom <= level && level < p.top ? [p] : [];
                }
                return cutStretches(frame, p, top, roofs, wall.height, slope).map(([from, to]) => ({...p, from, to}));
            })
            .sort((a, b) => a.from - b.from);
        const merged: typeof pieces = [];
        for (const piece of pieces) {
            const last = merged.at(-1);
            if (last !== undefined && last.recess === undefined && piece.recess === undefined && piece.from <= last.to + 0.5) {
                last.to = Math.max(last.to, piece.to);
            } else {
                merged.push({...piece});
            }
        }
        const joint = joints.get(wall.id) ?? SquareEnds;
        const corner = corners.get(wall.id) ?? NoCorners;
        const ends: WallEnds = {
            startLeft: corner.start === null ? joint.startLeft : 0,
            startRight: corner.start === null ? joint.startRight : 0,
            endLeft: corner.end === null ? joint.endLeft : 0,
            endRight: corner.end === null ? joint.endRight : 0,
        };
        return merged.map((piece): Shape => ({
            points: wallOutline(frame, wall.thickness, piece.from, piece.to, ends, piece.recess),
            closed: true,
            style: {fill: WallFill},
        }));
    });
}

/** A rectangle on a wall's frame: `from`–`to` along it, `a`–`b` across it (mm to the left). */
function wallRect(frame: WallFrame, from: number, to: number, a: number, b: number, style: Style): Shape {
    return {
        points: [pointOnWall(frame, from, a), pointOnWall(frame, to, a), pointOnWall(frame, to, b), pointOnWall(frame, from, b)],
        closed: true,
        style,
    };
}

function wallLine(frame: WallFrame, from: number, to: number, across: number, style: Style): Shape {
    return {points: [pointOnWall(frame, from, across), pointOnWall(frame, to, across)], closed: false, style};
}

const OutlineStyle: Style = {fill: '#ffffff', stroke: Ink, width: 0.25};
const ThinStyle: Style = {stroke: Ink, width: 0.13};
const HiddenStyle: Style = {stroke: Muted, width: 0.15, dash: '1 0.7'};

/**
 * The symbols of a wall's openings: a window cut by the plan as its frame with two glazing lines (and its sill board);
 * door leaves opened square with their swing, sliding leaves closed with their open place dashed, a pocket door's
 * pocket; a niche's original face dashed. A passage is just the gap. An opening the cut does not pass through (a
 * high window, a hatch) is dashed in the solid wall.
 */
/** The wall's openings the roofs leave wall for (`buildWall()` draws the same): all of them without roofs. */
function shownOpenings(wall: Wall, frame: WallFrame, top: HeightAt | null, roofs: FloorRoofs, slope: HeightAt | null = null): Opening[] {
    const slopes = slope === null ? [] : [slope];
    return wall.openings.filter(
        (o) =>
            top === null ||
            o.sillHeight < lowestTopAlong(pointOnWall(frame, o.offset), pointOnWall(frame, o.offset + o.width), top, roofs, [], slopes),
    );
}

/** The doors, windows and niches of a wall: open in the cut with their symbols, or dashed above or below it. */
function openingShapes(wall: Wall, roofs: FloorRoofs = []): Shape[] {
    const wallPath = wallFrame(wall);
    if (!(wallPath.length > 0)) {
        return [];
    }
    const top = wallTopAt(wall, roofs, wallPath);
    const h = wall.thickness / 2;
    return shownOpenings(wall, wallPath, top, roofs, wallHeightAt(wall, wallPath)).flatMap((opening): Shape[] => {
        const from = opening.offset;
        const to = opening.offset + opening.width;
        // Under a roof the cut over the opening follows the wall's top there.
        const level = top === null ? cutLevel(wall) : levelFor(top(pointOnWall(wallPath, (from + to) / 2)));
        const sign = opening.side === 'Right' ? -1 : 1;
        if (opening.type === 'Niche') {
            // A niche follows its wall's curve.
            const depth = opening.depth ?? h;
            const face = wallSamples(wallPath, from, to).map((along) => pointOnWall(wallPath, along, sign * h));
            const back = wallSamples(wallPath, from, to).map((along) => pointOnWall(wallPath, along, sign * (h - depth)));
            return cutsThrough(opening, level)
                ? [{points: face, closed: false, style: HiddenStyle}]
                : [{points: [...face, ...back.reverse()], closed: true, style: HiddenStyle}];
        }
        // Doors and windows stand straight on a curved wall.
        const frame = openingFrame(wallPath, opening);
        if (!cutsThrough(opening, level)) {
            return [wallRect(frame, from, to, h, -h, HiddenStyle)];
        }
        const shapes: Shape[] = [];
        if (opening.type === 'Window') {
            if (opening.sillBoard != null && opening.sillBoard > 0) {
                shapes.push(wallRect(frame, from - 30, to + 30, sign * h, sign * (h + opening.sillBoard), OutlineStyle));
            }
            shapes.push(wallRect(frame, from, to, h, -h, OutlineStyle));
            if (opening.walkable !== true) {
                // The glass where the frame stands across the wall (set in by `inset`, FD-89).
                const centre = frameBand(opening, wall.thickness).centre;
                shapes.push(
                    wallLine(frame, from, to, centre + GlassGap, ThinStyle),
                    wallLine(frame, from, to, centre - GlassGap, ThinStyle),
                );
            }
        }
        for (const leaf of openingLeaves(opening, wall.thickness)) {
            shapes.push(
                ...(leaf.motion === 'swing'
                    ? swingShapes(frame, leaf)
                    : leaf.motion === 'lift'
                        ? liftShapes(frame, leaf)
                        : slideShapes(frame, leaf, opening)),
            );
        }
        return shapes;
    });
}

/** A swinging leaf opened square into its side, and the arc its free edge sweeps. */
function swingShapes(frame: WallFrame, leaf: Leaf): Shape[] {
    const hinge = pointOnWall(frame, leaf.along, leaf.across);
    const closed = {x: frame.dir.x * leaf.closed, y: frame.dir.y * leaf.closed};
    const open = {x: frame.normal.x * leaf.swing, y: frame.normal.y * leaf.swing};
    const at = (along: number, across: number): Vec2 => ({
        x: hinge.x + open.x * along + closed.x * across,
        y: hinge.y + open.y * along + closed.y * across,
    });
    const t = leaf.thickness / 2;
    const arc = Array.from({length: 25}, (_, i) => {
        const angle = (i / 24) * (Math.PI / 2);
        return at(leaf.width * Math.sin(angle), leaf.width * Math.cos(angle));
    });
    return [
        {points: [at(0, -t), at(leaf.width, -t), at(leaf.width, t), at(0, t)], closed: true, style: OutlineStyle},
        {points: arc, closed: false, style: ThinStyle},
    ];
}

/** A sliding leaf closed, where it slides to dashed, and a pocket door's pocket in the wall. */
function slideShapes(frame: WallFrame, leaf: Leaf, opening: Opening): Shape[] {
    const t = leaf.thickness / 2;
    const leafRect = (shift: number, style: Style): Shape =>
        wallRect(frame, leaf.along + shift, leaf.along + shift + leaf.width, leaf.across + t, leaf.across - t, style);
    return opening.type === 'PocketDoor'
        ? [
            wallRect(frame, leaf.along + leaf.travel, leaf.along + leaf.travel + leaf.width, t + 10, -t - 10, {...OutlineStyle, width: 0.13}),
            leafRect(0, OutlineStyle),
        ]
        : leaf.travel === 0
            ? [leafRect(0, OutlineStyle)]
            : [leafRect(leaf.travel, HiddenStyle), leafRect(0, OutlineStyle)];
}

/**
 * A lifting garage door (FD-89): the leaf closed behind the opening, and dashed where it goes overhead in the garage: a
 * sectional door's as deep as it is high, an up-and-over door's two thirds in (and a third out over the drive), a
 * roller door's drum over the opening.
 */
function liftShapes(frame: WallFrame, leaf: Leaf): Shape[] {
    const t = leaf.thickness / 2;
    const [from, to] = [leaf.along, leaf.along + leaf.width];
    const height = leaf.height ?? 0;
    // Into the garage is `swing` across the wall.
    const inside = (reach: number): number => leaf.across + leaf.swing * reach;
    const overhead =
        leaf.lift === 'Roller'
            ? wallRect(frame, from, to, inside(-RollerDrum / 2), inside(RollerDrum / 2), HiddenStyle)
            : leaf.lift === 'UpAndOver'
                ? wallRect(frame, from, to, inside(-height / 3), inside((2 * height) / 3), HiddenStyle)
                : wallRect(frame, from, to, inside(t), inside(height), HiddenStyle);
    return [overhead, wallRect(frame, from, to, leaf.across + t, leaf.across - t, OutlineStyle)];
}

/** The arrowhead of a walking line, plan mm. */
const StairArrow = 250;
const StairStyle: Style = {fill: ItemFill, stroke: Ink, width: 0.18};
const StairHiddenStyle: Style = {stroke: Muted, width: 0.15, dash: '1 0.7'};
const VoidStyle: Style = {stroke: Ink, width: 0.25};

/**
 * The stairs on the plan. The floor's own: the treads and landings under the cut drawn, those above it dashed, a
 * break line across the flight where the cut passes, and the walking line from the first riser with an arrow and
 * "UP". The floor below's stairs coming up through this floor: the void's outline, their treads dashed below it and
 * their walking line arriving here.
 */
function stairPlanShapes(scene: Scene): {shapes: Shape[]; labels: Label[]} {
    const shapes: Shape[] = [];
    const labels: Label[] = [];
    for (const stair of scene.stairs ?? []) {
        const geometry = stairGeometry(stair, scene.stairRise ?? 0);
        const steps = [...geometry.landings, ...geometry.treads].sort((a, b) => a.top - b.top);
        // Garden steps stand at their base: the cut is that much lower against them.
        const cut = PlanCutHeight - (stair.baseZ ?? 0);
        for (const step of steps) {
            shapes.push({points: step.outline, closed: true, style: step.top <= cut ? StairStyle : StairHiddenStyle});
        }
        const cutTread = geometry.treads.find((t) => t.top > cut);
        if (cutTread !== undefined) {
            shapes.push(breakLine(cutTread.outline));
        }
        shapes.push(...walkLineShapes(geometry.walkLine));
        const start = geometry.walkLine[0];
        if (start !== undefined) {
            // On the first tread, half a going up the walking line.
            const angle = stairAngle(geometry);
            const at = {x: start.point.x + Math.cos(angle) * (stair.going / 2), y: start.point.y + Math.sin(angle) * (stair.going / 2)};
            labels.push({at, lines: [{text: 'UP', size: 1.8, bold: true}], angle});
        }
    }
    const below = scene.stairsBelow ?? null;
    for (const stair of below?.stairs ?? []) {
        const geometry = stairGeometry(stair, below?.rise ?? 0);
        for (const step of [...geometry.landings, ...geometry.treads]) {
            shapes.push({points: step.outline, closed: true, style: StairHiddenStyle});
        }
        shapes.push({points: geometry.footprint, closed: true, style: VoidStyle});
        shapes.push(...walkLineShapes(geometry.walkLine));
    }
    return {shapes, labels};
}

/** The cut through a flight: a line across the tread's front edge with a zigzag in its middle. */
function breakLine(outline: readonly Vec2[]): Shape {
    const [a, b] = [outline[0], outline[outline.length - 1]];
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const length = Math.hypot(dx, dy) || 1;
    const normal = {x: -dy / length, y: dx / length};
    const at = (t: number, off: number): Vec2 => ({x: a.x + dx * t + normal.x * off, y: a.y + dy * t + normal.y * off});
    return {points: [at(0, 0), at(0.42, 0), at(0.47, 80), at(0.53, -80), at(0.58, 0), at(1, 0)], closed: false, style: VoidStyle};
}

/** A walking line with an arrowhead at its end. */
function walkLineShapes(walk: readonly StairLinePoint[]): Shape[] {
    if (walk.length < 2) {
        return [];
    }
    const points = walk.map((p) => p.point);
    const end = points[points.length - 1];
    const before = points[points.length - 2];
    const length = Math.hypot(end.x - before.x, end.y - before.y) || 1;
    const d = {x: (end.x - before.x) / length, y: (end.y - before.y) / length};
    const side = {x: -d.y, y: d.x};
    const head = (s: number): Vec2 => ({
        x: end.x - d.x * StairArrow + side.x * s * StairArrow * 0.4,
        y: end.y - d.y * StairArrow + side.y * s * StairArrow * 0.4,
    });
    return [
        {points, closed: false, style: ThinStyle},
        {points: [head(1), end, head(-1)], closed: false, style: ThinStyle},
    ];
}

/** The plan angle (radians) of the stair's first stretch of walking line, for its "UP". */
function stairAngle(geometry: StairGeometry): number {
    const [a, b] = geometry.walkLine;
    return b === undefined ? 0 : Math.atan2(b.point.y - a.point.y, b.point.x - a.point.x);
}

/** An item's footprint: a column filled like the walls, an item above the cut (a wall cabinet, a beam, a lamp) dashed. */
function itemShape(item: Item): Shape {
    const style: Style =
        item.kind === 'Column'
            ? {fill: WallFill}
            : item.z >= PlanCutHeight
              ? {stroke: Muted, width: 0.15, dash: '1 0.7'}
              : {fill: ItemFill, stroke: Muted, width: 0.18};
    return {points: itemFootprint(item), closed: true, style};
}

/** The item's name in its footprint, along its width or else its depth, if it fits; none for columns and items above the cut. */
function itemLabel(item: Item, scale: number): Label[] {
    if (item.kind === 'Column' || item.z >= PlanCutHeight || item.name.trim() === '') {
        return [];
    }
    const r = (item.rotation * Math.PI) / 180;
    for (const [length, across, angle] of [
        [item.width, item.depth, r],
        [item.depth, item.width, r + Math.PI / 2],
    ]) {
        const size = Math.min(1.8, (across / scale) * 0.6);
        if (size >= 1.2 && textWidth(item.name, size) <= (length / scale) * 0.9) {
            return [{at: {x: item.x, y: item.y}, lines: [{text: item.name, size, color: Muted}], angle}];
        }
    }
    return [];
}

const RoomNameSize = 2.6;
const MinRoomNameSize = 1.8;

/**
 * Where a room's label goes: the point of the room furthest from its outline (so an L-shaped room's label lies in its
 * wider leg, not in the room its arms wrap), with that distance. The centroid is kept when it is clear of the walls.
 */
function labelPoint(polygon: readonly PlanPoint[]): {at: Vec2; clearance: number} {
    const clearanceOf = (p: Vec2): number =>
        Math.min(...polygon.map((corner, i) => segmentDistance(p, toVec(corner), toVec(polygon[(i + 1) % polygon.length]))));
    const centroid = polygonCentroid(polygon);
    if (pointInPolygon(centroid, polygon)) {
        const clearance = clearanceOf(centroid);
        if (clearance >= 800) {
            return {at: centroid, clearance};
        }
    }
    const xs = polygon.map(([x]) => x);
    const ys = polygon.map(([, y]) => y);
    const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
    const Steps = 40;
    let best = {at: centroid, clearance: -Infinity};
    for (let i = 1; i < Steps; i++) {
        for (let j = 1; j < Steps; j++) {
            const p = {x: minX + ((maxX - minX) * i) / Steps, y: minY + ((maxY - minY) * j) / Steps};
            if (pointInPolygon(p, polygon)) {
                const clearance = clearanceOf(p);
                if (clearance > best.clearance) {
                    best = {at: p, clearance};
                }
            }
        }
    }
    return best;
}

const toVec = ([x, y]: PlanPoint): Vec2 => ({x, y});

/** Distance from `p` to the segment a–b. */
function segmentDistance(p: Vec2, a: Vec2, b: Vec2): number {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const length2 = dx * dx + dy * dy;
    const t = length2 === 0 ? 0 : Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / length2));
    return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy));
}

/** The room's name, wrapped (and if need be made smaller) to the width free around its label point, over its area. */
/** Paper size of the line under a room's name: its area and level. */
const MetaSize = 2.1;

function roomLabel(room: Room, scale: number): Label {
    const {at, clearance} = labelPoint(room.polygon);
    const width = ((2 * clearance) / scale) * 0.9;
    const names: string[] = [];
    for (const word of room.name.split(/\s+/).filter((w) => w !== '')) {
        const last = names.at(-1);
        if (last !== undefined && textWidth(`${last} ${word}`, RoomNameSize) <= width) {
            names[names.length - 1] = `${last} ${word}`;
        } else {
            names.push(word);
        }
    }
    const widest = Math.max(0, ...names.map((name) => textWidth(name, RoomNameSize)));
    const size = widest > width ? Math.max(MinRoomNameSize, (RoomNameSize * width) / widest) : RoomNameSize;
    const area = `${room.area.toFixed(2)} m²`;
    const elevation = room.floorElevation ?? 0;
    // The floor's height against the floor's level, in metres as levels are written on plans (+0.450, −0.150), when
    // the room is wide enough for it beside the area.
    const level = `${area} · ${levelText(elevation)}`;
    const meta = elevation !== 0 && textWidth(level, MetaSize) <= width ? level : area;
    return {
        at,
        lines: [...names.map((name) => ({text: name, size, bold: true})), {text: meta, size: MetaSize, color: Muted}],
    };
}

/**
 * The rooms' dimension lines at offsets fitting the scale, along their walls: each a label with the length, drawn with
 * its line and 45° ticks; dimensions shorter than their length's text are left out.
 */
function dimensionLabels(scene: Scene, scale: number): Label[] {
    const lines = roomDimensions(scene.rooms, scene.walls, {
        overall: DimensionPaperOffset * scale,
        chain: ChainPaperOffset * scale,
        walledOnly: true,
    });
    const style: Style = {stroke: '#4a4640', width: 0.13};
    const size = 1.6;
    return lines.flatMap((dimension): Label[] => {
        const value = String(dimension.length);
        if (textWidth(value, size) + 1 > dimension.length / scale) {
            return [];
        }
        const points = dimensionPath(dimension);
        const tick = ((TickPaper / 2) * scale) / Math.SQRT2;
        // 45° ticks at the ends, across the line's direction there (towards the room's side).
        const slashAt = (end: Vec2, next: Vec2, sign: 1 | -1): Shape => {
            const step = Math.hypot(next.x - end.x, next.y - end.y) || 1;
            const dir = {x: ((next.x - end.x) / step) * sign, y: ((next.y - end.y) / step) * sign};
            const slash = {x: (dir.x - dir.y) * tick, y: (dir.y + dir.x) * tick};
            return {points: [{x: end.x - slash.x, y: end.y - slash.y}, {x: end.x + slash.x, y: end.y + slash.y}], closed: false, style};
        };
        const shapes: Shape[] = [
            {points, closed: false, style},
            slashAt(points[0], points[1], 1),
            slashAt(points[points.length - 1], points[points.length - 2], -1),
        ];
        const lift = 1.1 * scale;
        const {at, dir} = dimensionMiddle(dimension);
        const normal = dimension.path === undefined ? dimension.normal : {x: -dir.y, y: dir.x};
        return [{
            at: {x: at.x + normal.x * lift, y: at.y + normal.y * lift},
            lines: [{text: value, size, color: '#4a4640'}],
            angle: Math.atan2(dir.y, dir.x),
            shapes,
        }];
    });
}

/** The paper box a label's text covers (its turned box's bounds), with a little room around it. */
function labelBox(label: Label, toPaper: (p: Vec2) => Vec2): Box {
    const at = toPaper(label.at);
    const width = Math.max(...label.lines.map((l) => textWidth(l.text, l.size)));
    const height = label.lines.reduce((sum, l) => sum + l.size * 1.25, 0);
    const radians = (paperDegrees(label) * Math.PI) / 180;
    const halfWidth = (Math.abs(width * Math.cos(radians)) + Math.abs(height * Math.sin(radians))) / 2 + 0.3;
    const halfHeight = (Math.abs(width * Math.sin(radians)) + Math.abs(height * Math.cos(radians))) / 2 + 0.3;
    return {left: at.x - halfWidth, right: at.x + halfWidth, top: at.y - halfHeight, bottom: at.y + halfHeight};
}

function overlaps(a: Box, b: Box): boolean {
    return a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
}

function shapeBounds(shapes: readonly Shape[]): Bounds {
    const bounds = {minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity};
    for (const p of shapes.flatMap((s) => s.points)) {
        bounds.minX = Math.min(bounds.minX, p.x);
        bounds.minY = Math.min(bounds.minY, p.y);
        bounds.maxX = Math.max(bounds.maxX, p.x);
        bounds.maxY = Math.max(bounds.maxY, p.y);
    }
    return Number.isFinite(bounds.minX) ? bounds : {minX: 0, minY: 0, maxX: 1000, maxY: 1000};
}

function perimeter(room: Pick<Room, 'polygon'>): number {
    return room.polygon.reduce((sum, [x, y], i) => {
        const [nx, ny] = room.polygon[(i + 1) % room.polygon.length];
        return sum + Math.hypot(nx - x, ny - y);
    }, 0);
}

// --- Page furniture (paper mm) ---

/** The band under the plan: a scale bar and a north arrow at the left, the title block at the right. */
function planBandSvg(layout: PlanLayout, info: SheetInfo, northAngle: number): string {
    const top = layout.height - Margin - TitleBand;
    const left = Margin + Padding;
    const parts: string[] = [];

    // Scale bar: the longest round length up to 50 mm on paper, in five parts.
    const metres = [100, 50, 20, 10, 5, 2, 1].find((m) => (m * 1000) / layout.scale <= 50) ?? 1;
    const barLength = (metres * 1000) / layout.scale;
    const barY = top + 13;
    for (let i = 0; i < 5; i++) {
        const fill = i % 2 === 0 ? Ink : '#ffffff';
        parts.push(rect({x: left + (i * barLength) / 5, y: barY}, barLength / 5, 1.6, {fill, stroke: Ink, width: 0.15}));
    }
    parts.push(text({x: left, y: barY - 1.5}, '0', 2.2, {anchor: 'middle'}));
    parts.push(text({x: left + barLength, y: barY - 1.5}, `${metres} m`, 2.2, {anchor: 'middle'}));
    parts.push(text({x: left, y: barY + 6}, `1:${layout.scale}`, 2.4, {bold: true}));

    // North arrow: true north, turned from the plan's y axis by the site's north angle.
    parts.push(northArrowSvg({x: left + barLength + 16, y: top + 14}, northAngle));

    parts.push(titleBlockSvg(layout.width, layout.height, info, `Floor plan · 1:${layout.scale} (A4)`));
    return parts.join('');
}

// --- SVG writing ---

function shapeSvg(shape: Shape, toPaper: (p: Vec2) => Vec2): string {
    return path(shape.points.map(toPaper), shape.closed, shape.style);
}

/** A label's lines stacked around its point, turned along its angle (as the paper shows it: y down) and never upside down. */
function labelSvg(label: Label, toPaper: (p: Vec2) => Vec2): string {
    const at = toPaper(label.at);
    const degrees = paperDegrees(label);
    const total = label.lines.reduce((sum, l) => sum + l.size * 1.25, 0);
    let y = at.y - total / 2;
    const texts = label.lines.map((l) => {
        y += l.size * 1.25;
        // The baseline sits a little above the bottom of the line box.
        return text({x: at.x, y: y - l.size * 0.3}, l.text, l.size, {bold: l.bold, color: l.color, anchor: 'middle'});
    });
    const rotation = Math.abs(degrees) < 0.01 ? '' : ` transform="rotate(${n(degrees)} ${n(at.x)} ${n(at.y)})"`;
    return `<g${rotation}>${texts.join('')}</g>`;
}

/** A label's turn on the paper (y down), degrees in (−90, 90] so that it never reads upside down. */
function paperDegrees(label: Label): number {
    let degrees = label.angle === undefined ? 0 : (-label.angle * 180) / Math.PI;
    degrees = ((((degrees + 180) % 360) + 360) % 360) - 180;
    if (degrees > 90) {
        degrees -= 180;
    } else if (degrees <= -90) {
        degrees += 180;
    }
    return degrees;
}
