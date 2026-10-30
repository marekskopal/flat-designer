import {levelText} from '@app/core/level-text';
import {Floor, Item, Opening, PlanPoint, Room, Scene, Stair, Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {planLayout, planSheet, roomScheduleSheets} from './plan-drawing';
import {PaperLong, PaperShort, SheetInfo} from './sheet-svg';

const wall = (id: number, start: PlanPoint, end: PlanPoint, openings: Opening[] = []): Wall => ({
    id,
    name: `Wall ${id}`,
    type: 'Exterior',
    startX: start[0],
    startY: start[1],
    endX: end[0],
    endY: end[1],
    thickness: 300,
    height: 2600,
    materialId: null,
    leftFinishes: null,
    rightFinishes: null,
    openings,
});

const opening = (id: number, fields: Partial<Opening>): Opening => ({
    id,
    name: `Opening ${id}`,
    type: 'Door',
    offset: 1150,
    width: 900,
    height: 2000,
    sillHeight: 0,
    leafWidth: null,
    hinge: 'Start',
    side: 'Left',
    entrance: false,
    ...fields,
});

const room = (fields: Partial<Room>): Room => ({
    id: 1,
    code: '1.01',
    name: 'Living room',
    type: 'Living',
    polygon: [[0, 0], [4000, 0], [4000, 3000], [0, 3000]],
    area: 12,
    perimeter: 14,
    ceilingHeight: 2600,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
    ...fields,
});

const item = (id: number, fields: Partial<Item>): Item => ({
    id,
    roomId: 1,
    groupId: null,
    kind: 'Custom',
    definitionId: null,
    name: `Item ${id}`,
    x: 2000,
    y: 1500,
    z: 0,
    width: 1200,
    depth: 600,
    height: 750,
    rotation: 0,
    materialId: null,
    params: {},
    ...fields,
});

/** Centre lines of 300 mm walls around the inner 4000 × 3000 room. */
const box = (south: Opening[] = [], north: Opening[] = []): Wall[] => [
    wall(1, [-150, -150], [4150, -150], south),
    wall(2, [4150, -150], [4150, 3150]),
    wall(3, [4150, 3150], [-150, 3150], north),
    wall(4, [-150, 3150], [-150, -150]),
];

const ground: Floor = {id: 1, name: 'Ground floor', level: 0, elevation: 0, slabThickness: 300};

const scene = (fields: Partial<Scene>): Scene => ({floor: ground, materials: [], rooms: [room({})], walls: box(), items: [], ...fields}) as Scene;

const info: SheetInfo = {
    projectName: 'Flat & garden',
    floorName: 'Ground floor',
    floorElevation: 0,
    address: 'Main Street 1',
    date: new Date(2026, 9, 21),
};

/** The paths filled like the cut walls, in the plan (before the frame and the north arrow). */
const wallPaths = (svg: string): string[] => svg.split('<rect x="10"')[0].match(/<path d="[^"]*" fill="#2f2c28"\/>/g) ?? [];
const texts = (svg: string): string[] => [...svg.matchAll(/<text[^>]*>([^<]*)<\/text>/g)].map((m) => m[1]);

describe('levelText', () => {
    it('writes levels in metres as plans do', () => {
        expect([0, 3000, -150, 450].map(levelText)).toEqual(['±0.000', '+3.000', '−0.150', '+0.450']);
    });
});

describe('planLayout', () => {
    it('takes the largest standard scale that fits A4, turning the paper for a tall plan', () => {
        expect(planLayout({minX: 0, minY: 0, maxX: 4300, maxY: 3300})).toMatchObject({orientation: 'landscape', scale: 50});
        expect(planLayout({minX: 0, minY: 0, maxX: 12000, maxY: 8000})).toMatchObject({orientation: 'landscape', scale: 100});
        expect(planLayout({minX: 0, minY: 0, maxX: 8000, maxY: 14000})).toMatchObject({
            orientation: 'portrait',
            width: PaperShort,
            height: PaperLong,
            scale: 100,
        });
        expect(planLayout({minX: 0, minY: 0, maxX: 60000, maxY: 20000})).toMatchObject({scale: 250});
    });

    it('centres the plan in the area above the title band', () => {
        const layout = planLayout({minX: 0, minY: 0, maxX: 20000, maxY: 10000});
        expect(layout.scale).toBe(100);
        // 297 − 2 · 16 = 265 mm across for 200 mm of plan; 210 − 2 · 16 − 24 = 154 mm down for 100 mm.
        expect(layout.origin.x).toBeCloseTo(16 + (265 - 200) / 2);
        expect(layout.origin.y).toBeCloseTo(16 + (154 - 100) / 2);
    });
});

describe('planSheet', () => {
    it('draws a well-formed A4 page with the room, its dimensions and the title block', () => {
        const sheet = planSheet(scene({walls: box([opening(9, {})])}), info);

        expect(sheet).toMatchObject({width: PaperLong, height: PaperShort, scale: 50});
        const doc = new DOMParser().parseFromString(sheet.svg, 'image/svg+xml');
        expect(doc.querySelector('parsererror')).toBeNull();
        expect(doc.documentElement.getAttribute('width')).toBe('297mm');

        const labels = texts(sheet.svg);
        expect(labels).toEqual(
            expect.arrayContaining(['Living room', '12.00 m²', 'Flat &amp; garden', 'Ground floor ±0.000 · Main Street 1', '1:50']),
        );
        // The overall lengths and the chain of piers and the door along the south wall.
        expect(labels.filter((t) => t === '4000')).toHaveLength(2);
        expect(labels.filter((t) => t === '3000')).toHaveLength(2);
        expect(labels).toEqual(expect.arrayContaining(['1000', '900', '2100']));
    });

    it('fills paths and driveways as open-air rooms', () => {
        const fill = (type: Room['type']): string | undefined =>
            planSheet(scene({rooms: [room({type})]}), info).svg.match(/<path d="[^"]*" fill="(#[0-9a-f]+)"/)?.[1];
        expect(fill('Path')).toBe(fill('Garden'));
        expect(fill('Driveway')).toBe(fill('Terrace'));
        expect(fill('Path')).not.toBe(fill('Living'));
    });

    it('writes the floor level of a raised or sunken room under its area', () => {
        expect(texts(planSheet(scene({rooms: [room({floorElevation: -150})]}), info).svg)).toContain('12.00 m² · −0.150');
        expect(texts(planSheet(scene({rooms: [room({floorElevation: 450})]}), info).svg)).toContain('12.00 m² · +0.450');
        expect(texts(planSheet(scene({}), info).svg)).toContain('12.00 m²');
        // A room too narrow for both keeps its area.
        const narrow = room({polygon: [[0, 0], [700, 0], [700, 3000], [0, 3000]], area: 2.1, floorElevation: -20});
        const labels = texts(planSheet(scene({rooms: [narrow]}), info).svg);
        expect(labels).toContain('2.10 m²');
        expect(labels.some((t) => t.includes('−0.020'))).toBe(false);
    });

    it('draws a plot boundary as a property line and fits the page to it', () => {
        const polygon: PlanPoint[] = [[-3000, -3000], [12000, -3000], [12000, 9000], [-3000, 9000]];
        const plot = {id: 7, name: 'Parcel', polygon, area: 180, groundMaterialId: null};
        const sheet = planSheet(scene({plots: [plot]}), info);

        expect(sheet.scale).toBe(100);
        expect(sheet.svg).toContain('stroke-dasharray="6 1.2 0.6 1.2"');
    });

    it('draws the contour lines and spot heights of a sloping plot', () => {
        const polygon: PlanPoint[] = [[-3000, -3000], [12000, -3000], [12000, 9000], [-3000, 9000]];
        const terrain: [number, number, number][] = [[-3000, -3000, 1250], [-3000, 9000, 1250], [12000, -3000, -250], [12000, 9000, -250]];
        const plot = {id: 7, name: 'Parcel', polygon, area: 180, groundMaterialId: null, terrain};
        const svg = planSheet(scene({plots: [plot]}), info).svg;
        const labels = texts(svg);

        expect(labels).toContain('+1.250');
        expect(labels).toContain('−0.250');
        // Contours at 0, +0.5 and +1.0 m: the full metres heavier.
        expect(svg.match(/stroke="#9a8f7c" stroke-width="0.18"/g)?.length).toBeGreaterThan(0);
        expect(svg.match(/stroke="#9a8f7c" stroke-width="0.09"/g)?.length).toBeGreaterThan(0);
        expect(planSheet(scene({plots: [{...plot, terrain: []}]}), info).svg).not.toContain('#9a8f7c');
    });

    it('draws an item standing on high ground as one above the cut', () => {
        const polygon: PlanPoint[] = [[-3000, -3000], [12000, -3000], [12000, 9000], [-3000, 9000]];
        const terrain: [number, number, number][] = [[-3000, -3000, 2000], [-3000, 9000, 2000], [12000, -3000, 2000], [12000, 9000, 2000]];
        const plot = {id: 7, name: 'Parcel', polygon, area: 180, groundMaterialId: null, terrain};
        const shed = item(1, {name: 'Shed', x: 8000, y: 6000, z: 0, width: 2000, depth: 2000});
        const onGround = planSheet(scene({plots: [plot], items: [{...shed, onGround: true}]}), info).svg;
        const onFloor = planSheet(scene({plots: [plot], items: [shed]}), info).svg;

        expect(texts(onFloor)).toContain('Shed');
        expect(texts(onGround)).not.toContain('Shed');
    });

    it('dimensions only the room edges along a wall, not where open-plan rooms meet', () => {
        const labels = texts(planSheet(scene({walls: box().filter((w) => w.id !== 2)}), info).svg);

        expect(labels.filter((t) => t === '3000')).toHaveLength(1);
        expect(labels.filter((t) => t === '4000')).toHaveLength(2);
    });

    it('leaves the door open in the wall, with its leaf and swing', () => {
        const closed = planSheet(scene({}), info).svg;
        const withDoor = planSheet(scene({walls: box([opening(9, {})])}), info).svg;

        expect(wallPaths(closed)).toHaveLength(4);
        // The south wall is cut in two piers.
        expect(wallPaths(withDoor)).toHaveLength(5);
        // The leaf (white, outlined) and the arc of 25 points.
        expect(withDoor).toMatch(/fill="#ffffff" stroke="#2f2c28" stroke-width="0.25"/);
        expect(withDoor.match(/<path d="M[^"]*"[^>]*/g)?.some((p) => (p.match(/ L /g) ?? []).length === 24)).toBe(true);
    });

    it('cuts a wall the roof brings under the cut as a low wall, leaving its windows whole', () => {
        // A shed roof rising north from 1000 mm over the south wall's outer face: that wall is cut at half its top.
        const shed = {
            id: 1,
            name: 'Roof',
            shape: 'Shed' as const,
            x: 2000,
            y: 1500,
            rotation: 0,
            width: 4600,
            depth: 3600,
            eaveHeight: 1000,
            pitch: 45,
            overhang: 0,
            vergeOverhang: 0,
            thickness: 250,
            parapet: null,
            coveringMaterialId: null,
            soffitMaterialId: null,
            windows: [],
        };
        const window = opening(9, {type: 'Window', hinge: null, sillHeight: 900, height: 1200});
        const dashes = (svg: string): number => (svg.match(/stroke-dasharray="1 0.7"/g) ?? []).length;
        // Under the low roof the cut (about 600 mm) passes under the window's sill: the wall stays whole, the window dashed.
        const low = planSheet(scene({walls: box([window]), roofs: [shed]}), info).svg;
        expect(wallPaths(low)).toHaveLength(4);
        expect(dashes(low)).toBe(1);
        // A higher roof leaves the cut at 1300 mm: the window is cut through.
        expect(wallPaths(planSheet(scene({walls: box([window]), roofs: [{...shed, eaveHeight: 2000}]}), info).svg)).toHaveLength(5);
        // A window the roof leaves no wall for (sill 1200 under a top of about 1000–1300) is left out, dashes and all.
        const above = planSheet(scene({walls: box([opening(9, {type: 'Window', hinge: null, sillHeight: 1200, height: 400})]), roofs: [shed]}), info);
        expect(dashes(above.svg)).toBe(0);
    });

    it('draws the roofs dashed above the cut: outline, ridge once, and the roof windows', () => {
        const gable = {
            id: 1,
            name: 'Roof',
            shape: 'Gable' as const,
            x: 2000,
            y: 1500,
            rotation: 0,
            width: 4600,
            depth: 3600,
            eaveHeight: 2600,
            pitch: 45,
            overhang: 400,
            vergeOverhang: 200,
            thickness: 250,
            parapet: null,
            coveringMaterialId: null,
            soffitMaterialId: null,
            windows: [{id: 3, name: 'W', type: 'CentrePivot' as const, along: 0, across: -1000, width: 780, length: 1180, frameMaterialId: null}],
        };
        const roofDashes = (svg: string): number => (svg.match(/stroke-dasharray="2 0.8"/g) ?? []).length;
        // Two planes of four edges sharing the ridge, and the window.
        const sheet = planSheet(scene({roofs: [gable]}), info);
        expect(roofDashes(sheet.svg)).toBe(8);
        expect(roofDashes(planSheet(scene({}), info).svg)).toBe(0);

        // From 1 m at the eaves, the cut passes through each slab 946–1300 mm up: a section band (outlined, unlike a
        // wall's fill) along each eaves, the overhang under it seen from above with solid edges, the rest dashed.
        const low = planSheet(scene({roofs: [{...gable, eaveHeight: 1000, windows: []}]}), info).svg;
        expect((low.match(/fill="#2f2c28" stroke="#2f2c28" stroke-width="0.1"/g) ?? []).length).toBe(2);
        expect((low.match(/fill="none" stroke="#2f2c28" stroke-width="0.25"/g) ?? []).length).toBeGreaterThan(0);
        expect(roofDashes(low)).toBeGreaterThan(0);
    });

    it('cuts the wall at a window the cut passes through, but draws one above it dashed in a solid wall', () => {
        const window = opening(9, {type: 'Window', hinge: null, sillHeight: 900, height: 1200});
        const high = opening(9, {type: 'Window', hinge: null, sillHeight: 1600, height: 600});

        expect(wallPaths(planSheet(scene({walls: box([], [window])}), info).svg)).toHaveLength(5);
        const highSvg = planSheet(scene({walls: box([], [high])}), info).svg;
        expect(wallPaths(highSvg)).toHaveLength(4);
        expect(highSvg).toContain('stroke-dasharray');
    });

    it('draws the shown items with their names, items above the cut dashed and unnamed, hidden variants not at all', () => {
        const svg = planSheet(
            scene({
                items: [
                    item(1, {name: 'Table', y: 1000}),
                    item(2, {name: 'Wall cabinet', y: 2700, z: 1400, depth: 350}),
                    item(3, {name: 'Island', groupId: 20}),
                ],
                groups: [{id: 20, name: 'Island', variantSetId: 5}],
                variantSets: [{id: 5, name: 'Layout', activeGroupId: null}],
            }),
            info,
        ).svg;

        const labels = texts(svg);
        expect(labels).toContain('Table');
        expect(labels).not.toContain('Wall cabinet');
        expect(labels).not.toContain('Island');
        expect(svg).toContain('fill="#f3f0ea"');
    });

    it('turns an item name along a rotated item, never upside down', () => {
        const svg = planSheet(scene({items: [item(1, {name: 'Sofa', y: 1000, rotation: 180})]}), info).svg;
        // Rotated 180° the text still reads left to right.
        expect(svg).toMatch(/<g><text[^>]*>Sofa<\/text><\/g>/);
        const turned = planSheet(scene({items: [item(1, {name: 'Sofa', x: 1200, rotation: 90})]}), info).svg;
        expect(turned).toMatch(/<g transform="rotate\(90 [^)]*\)"><text[^>]*>Sofa<\/text><\/g>/);
    });

    it('wraps a long room name to the room, and leaves out an item name that would run into it', () => {
        const name = 'Living room with a kitchen, a dining corner and a reading nook by the window';
        const svg = planSheet(scene({rooms: [room({name})], items: [item(1, {name: 'Table'})]}), info).svg;

        const labels = texts(svg);
        // The table lies under the room's name.
        expect(labels).not.toContain('Table');
        const lines = labels.filter((t) => t.length > 3 && name.includes(t));
        expect(lines.length).toBeGreaterThan(1);
        expect(lines.join(' ')).toBe(name);
    });
});

describe('planSheet fixes from review', () => {
    it('puts the label of an L-shaped room inside its wider leg, not in the room its arms wrap', () => {
        // A 6 × 6 L around a 3 × 3 corner at the north-east: the centroid (2.5, 2.5) is inside, but close to the inner corner.
        const l = room({name: 'Living', polygon: [[0, 0], [6000, 0], [6000, 3000], [3000, 3000], [3000, 6000], [0, 6000]]});
        const svg = planSheet(scene({rooms: [l], walls: []}), info).svg;
        const label = svg.match(/<text x="([\d.]+)" y="([\d.]+)"[^>]*font-weight="bold">Living<\/text>/);
        expect(label).not.toBeNull();
        // Paper: 1:50, the plan's north-west corner at the layout origin. The label is inside the L and not in the notch.
        const layout = planLayout({minX: 0, minY: 0, maxX: 6000, maxY: 6000});
        const x = (Number(label![1]) - layout.origin.x) * layout.scale;
        const y = 6000 - (Number(label![2]) - layout.origin.y) * layout.scale;
        expect(x > 3000 && y > 3000).toBe(false);
        expect(x).toBeGreaterThan(0);
        expect(y).toBeGreaterThan(0);
    });

    it('keeps the corner open at a corner window', () => {
        const window = (offset: number): Opening => opening(9, {type: 'Window', hinge: null, sillHeight: 900, height: 1200, offset, width: 1500});
        const walls = [
            wall(1, [-150, -150], [4150, -150]),
            wall(2, [4150, -150], [4150, 3150], [window(1650)]),
            wall(3, [4150, 3150], [-150, 3150], [window(0)]),
            wall(4, [-150, 3150], [-150, -150]),
        ];
        const svg = planSheet(scene({walls}), info).svg;
        // No wall path reaches into the corner at (4150, 3150): on paper, 1:50, the corner is at origin + (86, 0).
        const layout = planLayout({minX: -150 - 60, minY: -150 - 60, maxX: 4150 + 60, maxY: 3150 + 60});
        const cornerX = layout.origin.x + (4300 - 150) / 50;
        const cornerY = layout.origin.y + (3360 - 3150) / 50;
        const near = (m: RegExpMatchArray): boolean => Math.abs(Number(m[1]) - cornerX) < 0.2 && Math.abs(Number(m[2]) - cornerY) < 0.2;
        const reaching = wallPaths(svg).filter((path) => [...path.matchAll(/[ML] ([\d.-]+) ([\d.-]+)/g)].some(near));
        expect(reaching).toHaveLength(0);
    });

    it('draws a hatch the cut does not pass through dashed in the solid wall', () => {
        const hatch = opening(9, {type: 'Passage', hinge: null, sillHeight: 1400, height: 600});
        const svg = planSheet(scene({walls: box([hatch])}), info).svg;
        expect(wallPaths(svg)).toHaveLength(4);
        expect(svg).toContain('stroke-dasharray');
    });

    it('draws a round column as a circle', () => {
        const svg = planSheet(scene({items: [item(1, {kind: 'Column', name: 'C1', width: 300, depth: 300, params: {shape: 'round'}})]}), info).svg;
        const column = svg.match(/<path d="M[^"]*" fill="#2f2c28"\/>/g)?.find((p) => (p.match(/ L /g) ?? []).length === 31);
        expect(column).toBeDefined();
    });
});

describe('planSheet stairs', () => {
    const stair: Stair = {
        id: 1,
        name: 'Stairs',
        shape: 'Straight',
        x: 2000,
        y: 200,
        rotation: 0,
        width: 900,
        going: 250,
        risers: 12,
        firstFlight: null,
        turn: 'Left',
        gap: 100,
        construction: 'Solid',
        railing: 'Right',
        treadMaterialId: null,
        structureMaterialId: null,
    };
    const treadPaths = (svg: string, dashed: boolean): number =>
        (svg.match(/<path d="[^"]*Z"[^>]*\/>/g) ?? []).filter(
            (p) => p.includes('stroke-dasharray') === dashed && p.includes('#f3f0ea') !== dashed,
        ).length;

    it("draws a stair's treads under the cut, those above dashed, the break line and the walking line with UP", () => {
        // 12 risers of 200: the treads at 200 … 1200 are under the 1300 mm cut, the five from 1400 up above it.
        const sheet = planSheet(scene({stairs: [stair], stairRise: 2400}), info);
        expect(treadPaths(sheet.svg, false)).toBe(6);
        expect(treadPaths(sheet.svg, true)).toBe(5);
        expect(texts(sheet.svg)).toContain('UP');
    });

    it("outlines the void of the floor below's stairs with their treads dashed, and no UP", () => {
        const sheet = planSheet(scene({stairsBelow: {floorId: 9, rise: 2400, stairs: [stair]}}), {...info, floorName: 'First floor'});
        expect(treadPaths(sheet.svg, true)).toBe(11);
        expect(texts(sheet.svg)).not.toContain('UP');
    });
});

describe('garage doors on the plan (FD-89)', () => {
    const dashed = (o: Opening): number => (planSheet(scene({walls: box([o])}), info).svg.match(/stroke-dasharray/g) ?? []).length;

    it('dashes where a lifting door goes overhead, and draws a side-hinged one swinging', () => {
        const garage = opening(9, {type: 'GarageDoor', offset: 700, width: 2500, height: 2125, garageDoorStyle: 'Sectional'});
        const plain = dashed(opening(9, {type: 'Passage', offset: 700, width: 2500}));
        expect(dashed(garage)).toBe(plain + 1);
        expect(dashed({...garage, garageDoorStyle: 'Roller'})).toBe(plain + 1);
        expect(planSheet(scene({walls: box([{...garage, garageDoorStyle: 'SideHinged'}])}), info).svg).not.toContain('NaN');
    });
});

describe('roomScheduleSheets', () => {
    it('totals a garage apart from the living area (FD-89)', () => {
        const rooms = [room({id: 1, code: '1.1', area: 20}), room({id: 2, code: '1.2', name: 'Double garage', type: 'Garage', area: 18})];
        const labels = texts(roomScheduleSheets(scene({rooms}), info)[0].svg);
        // The rows, then the totals: the living area and the garage apart.
        expect(labels.slice(labels.indexOf('Indoor'))).toEqual(expect.arrayContaining(['Indoor', '20.00', 'Garage', '18.00']));
        expect(labels.filter((t) => t === 'Garage')).toHaveLength(1);
    });

    it('lists the rooms by code with the indoor and outdoor totals', () => {
        const rooms = [
            room({id: 2, code: '1.10', name: 'Bedroom', area: 10}),
            room({id: 1, code: '1.2', name: 'Living room', area: 20, perimeter: undefined}),
            room({id: 3, code: '1.3', name: 'Terrace', type: 'Terrace', area: 6, ceilingHeight: null}),
        ];
        const sheets = roomScheduleSheets(scene({rooms}), info);

        expect(sheets).toHaveLength(1);
        expect(sheets[0]).toMatchObject({width: PaperShort, height: PaperLong});
        const labels = texts(sheets[0].svg);
        expect(labels.filter((t) => /^1\.\d+$/.test(t))).toEqual(['1.2', '1.3', '1.10']);
        // The perimeter missing in an older version comes from the outline; volume is area × clear height.
        expect(labels).toEqual(expect.arrayContaining(['14.00', '52.0', '26.0', 'Indoor', '30.00', '78.0', 'Outdoor', '6.00']));
    });

    it('gives an attic its clear height range, its volume under the slope and its area with headroom', () => {
        // A 45° gable from 1 m at the eaves over a 10 × 8 m room with a 2.5 m ceiling (the roof fixture's attic).
        const polygon: PlanPoint[] = [[0, 0], [10000, 0], [10000, 8000], [0, 8000]];
        const attic = room({id: 1, code: 'A1', name: 'Attic', area: 80, polygon, ceilingHeight: 2500});
        const gable = {
            id: 1,
            name: 'Roof',
            shape: 'Gable' as const,
            x: 5000,
            y: 4000,
            rotation: 0,
            width: 10000,
            depth: 8000,
            eaveHeight: 1000,
            pitch: 45,
            overhang: 0,
            vergeOverhang: 0,
            thickness: 250,
            parapet: null,
            coveringMaterialId: null,
            soffitMaterialId: null,
            windows: [],
        };
        const labels = texts(roomScheduleSheets(scene({rooms: [attic], roofs: [gable]}), info)[0].svg);
        expect(labels).toEqual(expect.arrayContaining(['1.00–2.50', '177.5', '76.00', '≥ 1.2 m, m²']));
        expect(texts(roomScheduleSheets(scene({rooms: [attic]}), info)[0].svg)).not.toContain('≥ 1.2 m, m²');
    });

    it('runs on to more pages and has none for a floor without rooms', () => {
        const rooms = Array.from({length: 70}, (_, i) => room({id: i + 1, code: `R${i + 1}`}));
        expect(roomScheduleSheets(scene({rooms}), info).length).toBeGreaterThan(1);
        expect(roomScheduleSheets(scene({rooms: []}), info)).toEqual([]);
    });
});

describe('planSheet voids and railings (FD-90)', () => {
    const polygon: PlanPoint[] = [
        [3000, 1000],
        [5000, 1000],
        [5000, 3000],
        [3000, 3000],
    ];
    const hole = {id: 5, name: 'Gallery', polygon, area: 4, liningMaterialId: null, liningDepth: null};
    const railing = {
        id: 6,
        name: 'Balustrade',
        points: [[3000, 1000], [3000, 3000]] as PlanPoint[],
        height: 1000,
        baseZ: 0,
        style: 'Balusters' as const,
        spacing: null,
        postSpacing: 1200,
        handrail: 'Round' as const,
        materialId: null,
        infillMaterialId: null,
    };

    it('crosses a void, draws a railing as a thin line and takes the void off the room it cuts', () => {
        const svg = planSheet(scene({voids: [hole], railings: [railing]}), info).svg;
        // Half the void lies in the 12 m² room.
        expect(texts(svg)).toContain('10.00 m²');
        const thin = svg.match(/<path d="M[^"]*"[^>]*stroke="#2f2c28" stroke-width="0.25"[^>]*\/>/g) ?? [];
        // The void's outline and two diagonals, and the railing.
        expect(thin.length).toBeGreaterThanOrEqual(4);
    });

    it('lists the voids in the room schedule, outside the totals', () => {
        const labels = texts(roomScheduleSheets(scene({voids: [hole]}), info)[0].svg);
        expect(labels).toContain('Gallery (void)');
        expect(labels).toContain('4.00');
        expect(labels.filter((t) => t === '10.00')).toHaveLength(2);
    });
});
