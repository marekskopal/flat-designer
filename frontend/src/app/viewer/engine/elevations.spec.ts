import {Floor, Item, Material, Opening, PlanPoint, Room, Scene, Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {elevationSheets, faceLetter, gridLines, itemsAgainst, layoutFaces, roomElevationSheets, roomFaces} from './elevations';
import {detectRoom} from './room-detect';
import {SheetInfo} from './sheet-svg';

const wall = (id: number, start: PlanPoint, end: PlanPoint, fields: Partial<Wall> = {}): Wall => ({
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
    openings: [],
    ...fields,
});

const door: Opening = {
    id: 9,
    name: 'Door',
    type: 'Door',
    offset: 1150,
    width: 900,
    height: 2000,
    sillHeight: 0,
    leafWidth: null,
    hinge: 'Start',
    side: 'Left',
    entrance: false,
};

const tiles: Material = {
    id: 5,
    code: 'tiles',
    name: 'Wall tiles 300 × 300',
    pattern: 'Tiles',
    color: '#d9d4cc',
    roughness: 0.4,
    metalness: 0,
    texture: null,
    tileWidth: 300,
    tileHeight: 300,
};

const room: Room = {
    id: 1,
    code: '1.01',
    name: 'Bathroom',
    type: 'Bathroom',
    polygon: [[0, 0], [4000, 0], [4000, 3000], [0, 3000]],
    area: 12,
    ceilingHeight: 2600,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
};

/** Centre lines of 300 mm walls around the inner 4000 × 3000 room; the south wall's inner face (its left) is tiled 1200 high. */
const box = (south: Partial<Wall> = {}): Wall[] => [
    wall(1, [-150, -150], [4150, -150], {leftFinishes: [{materialId: 5, bottom: 0, top: 1200}], ...south}),
    wall(2, [4150, -150], [4150, 3150]),
    wall(3, [4150, 3150], [-150, 3150]),
    wall(4, [-150, 3150], [-150, -150]),
];

const ground: Floor = {id: 1, name: 'Ground floor', level: 0, elevation: 0, slabThickness: 300};

const scene = (fields: Partial<Scene>): Scene => ({floor: ground, materials: [tiles], rooms: [room], walls: box(), items: [], ...fields}) as Scene;

const info: SheetInfo = {projectName: 'Flat', floorName: 'Ground floor', floorElevation: 0, address: null, date: new Date(2026, 9, 21)};

/** The tile joints drawn on a sheet. */
const joints = (svg: string): number => (svg.match(/stroke="#7d786f" stroke-width="0.08"/g) ?? []).length;

describe('roomFaces', () => {
    it('letters the room edges with a wall along them, as seen from inside', () => {
        const faces = roomFaces(room, box());

        expect(faces.map((f) => [f.letter, Math.round(f.length), f.height])).toEqual([
            ['A', 4000, 2600],
            ['B', 3000, 2600],
            ['C', 4000, 2600],
            ['D', 3000, 2600],
        ]);
        // The south face is seen from the north: its normal points into the room.
        expect(faces[0].normal).toEqual({x: -0, y: 1});
    });

    it('leaves out an edge where the room opens into another', () => {
        expect(roomFaces(room, box().filter((w) => w.id !== 2)).map((f) => f.letter)).toEqual(['A', 'B', 'C']);
    });

    it('follows the ceiling where a roof brings it lower', () => {
        // A shed rising north at 45° from 1500 mm over the south wall's inner face: the ceiling (2600) slopes there.
        const shed = {
            id: 1,
            shape: 'Shed' as const,
            x: 2000,
            y: 1500,
            rotation: 0,
            width: 4600,
            depth: 3000,
            eaveHeight: 1500,
            pitch: 45,
            overhang: 0,
            vergeOverhang: 0,
            thickness: 250,
        };
        const [south, east] = roomFaces(room, box(), [shed]);
        // Along the south face the roof is level: 1500 less the 10 mm the ceiling keeps under it.
        expect(south.top.every((p) => Math.abs(p.y - 1490) < 1e-6)).toBe(true);
        // The east face runs north (seen from inside, x from its end at the north): the ceiling rises to 2600 by y 1110.
        expect(east.height).toBe(2600);
        expect(east.top[east.top.length - 1].y).toBeCloseTo(1490, 6);
        const knee = east.top.find((p) => Math.abs(p.x - (3000 - 1110)) < 1e-6);
        expect(knee?.y).toBeCloseTo(2600, 6);
        expect(roomFaces(room, box(), [{...shed, x: 50000}])[1].top).toHaveLength(2);
    });

    it('letters on past Z', () => {
        expect([0, 25, 26, 27, 52].map(faceLetter)).toEqual(['A', 'Z', 'AA', 'AB', 'BA']);
    });

    it('follows a vaulted ceiling along the face', () => {
        const vaulted = {...room, vaultSpacing: 1000, vaultRise: 200, vaultAngle: 90, beamWidth: 80, beamDrop: 30};
        const south = roomFaces(vaulted, box())[0];
        // The beams run north, so along the south face the ceiling rises and falls from bay to bay.
        expect(south.height).toBeCloseTo(2600, 0);
        expect(Math.min(...south.top.map((p) => p.y))).toBe(2600 - 200 - 30);
    });
});

describe('roomFaces along a curved wall', () => {
    // The south wall bows 400 mm out (south) between the corners of the 4000 × 3200 room; a door 1600 mm along it.
    const bay = [
        wall(1, [-100, -100], [4100, -100], {thickness: 200, sagitta: -400, openings: [{...door, offset: 1600}]}),
        wall(2, [4100, -100], [4100, 3100], {thickness: 200}),
        wall(3, [4100, 3100], [-100, 3100], {thickness: 200}),
        wall(4, [-100, 3100], [-100, -100], {thickness: 200}),
    ];
    const detected = detectRoom(bay, {x: 2000, y: 1500});
    const bayRoom: Room = {...room, polygon: 'polygon' in detected ? detected.polygon : []};

    it('unrolls the pieces of the curved face into one face', () => {
        const faces = roomFaces(bayRoom, bay);

        expect(faces.map((f) => [f.letter, Math.round(f.length)])).toEqual([
            ['A', 4089],
            ['B', 3032],
            ['C', 4000],
            ['D', 3032],
        ]);
        const [curved] = faces;
        expect(curved.spans.map(([from, to]) => [Math.round(from), Math.round(to)])).toEqual([[0, 4089]]);
        expect(curved.openings.map((o) => [o.opening.id, Math.round(o.from), Math.round(o.to)])).toEqual([[9, 1504, 2388]]);
        expect(curved.gaps.map((g) => [Math.round(g.from), Math.round(g.to)])).toEqual([[1504, 2388]]);
    });

    it('takes the items standing against the curved face', () => {
        const [curved] = roomFaces(bayRoom, bay);
        // A 600 mm deep cabinet with its back at the face's middle, 400 mm south of the corners.
        const cabinet = {
            id: 1, roomId: 1, groupId: null, kind: 'Custom', definitionId: null, name: 'Cabinet', x: 2000, y: -100, z: 0,
            width: 600, depth: 600, height: 850, rotation: 0, materialId: null, params: {},
        } as unknown as Item;
        const [against] = itemsAgainst(curved, [cabinet]);
        // Its true width, at the face's middle.
        expect(against.to - against.from).toBeCloseTo(600, 6);
        expect((against.from + against.to) / 2).toBeCloseTo(curved.length / 2, 0);
    });

    it('draws the unrolled face on its sheet', () => {
        const sheets = roomElevationSheets(scene({rooms: [bayRoom], walls: bay}), bayRoom, info);
        expect(sheets[0].svg).toContain('4089 mm');
    });
});

describe('gridLines', () => {
    it('gives the joints between the ends on the grid shifted by the phase', () => {
        expect(gridLines(0, 1000, 300, 0)).toEqual([300, 600, 900]);
        expect(gridLines(0, 1000, 300, 100)).toEqual([200, 500, 800]);
        expect(gridLines(0, 900, 300, 0)).toEqual([300, 600]);
    });
});

describe('roomElevationSheets', () => {
    it('draws the tile joints on the room grid, the floor tiles\' joints carried up the wall', () => {
        const [sheet] = roomElevationSheets(scene({}), room, info);

        const doc = new DOMParser().parseFromString(sheet.svg, 'image/svg+xml');
        expect(doc.querySelector('parsererror')).toBeNull();
        // 4000 × 1200 of 300 mm tiles from the plan origin: 13 upright joints (x = 300 … 3900) and 3 level ones.
        expect(joints(sheet.svg)).toBe(16);
        expect(sheet.svg).toContain('Wall tiles 300 × 300');
        expect(sheet.svg).toContain('Wall elevations · 1.01 Bathroom');
    });

    it('moves the joints with the room\'s tile origin', () => {
        const shifted = roomElevationSheets(scene({rooms: [{...room, tileOriginX: 150}]}), {...room, tileOriginX: 150}, info)[0];
        // From x = 150 every 300 mm: 150 … 3750, still 13, and 3 level ones.
        expect(joints(shifted.svg)).toBe(16);
        const offGrid = roomElevationSheets(scene({rooms: [{...room, tileOriginX: 0}]}), {...room, tileOriginX: 0}, info)[0];
        expect(shifted.svg).not.toEqual(offGrid.svg);
    });

    it('cuts the band round a door, without joints across the doorway, and writes the door\'s size in it', () => {
        const [sheet] = roomElevationSheets(scene({walls: box({openings: [door]})}), room, info);

        // The doorway (plan x 1000–1900) takes 3 upright joints (1200, 1500, 1800) out of the band.
        expect(joints(sheet.svg)).toBe(10 + 3 * 2);
        expect((sheet.svg.match(/fill="#d9d4cc"/g) ?? []).length).toBe(2 + 1);
        expect(sheet.svg).toContain('900 × 2000');
    });

    it('puts brick joints from the edge\'s start, as the viewer lays bricks, not on the tile grid', () => {
        const brick = {...tiles, pattern: 'Brick' as const, tileWidth: 250, tileHeight: 65};
        // The south edge runs a = (4870, 0) → b = (0, 0): seen from inside, x = 4870 − t, head joints at t = 250 k.
        const wide = {...room, polygon: [[0, 0], [4870, 0], [4870, 3000], [0, 3000]] as PlanPoint[]};
        const walls = [
            wall(1, [-150, -150], [5020, -150], {leftFinishes: [{materialId: 5, bottom: 0, top: 130}]}),
            wall(2, [5020, -150], [5020, 3150]),
            wall(3, [5020, 3150], [-150, 3150]),
            wall(4, [-150, 3150], [-150, -150]),
        ];
        const [sheet] = roomElevationSheets(scene({materials: [brick], rooms: [wide], walls}), wide, info);
        expect(sheet.svg).toContain('1:50 (A4)');
        const layoutLeft = 16 + 52;
        // Paper x of the joints in the lower course (even, shifted half a brick): t = 125 + 250 k → x = 4745 − 250 k.
        const heads = [...sheet.svg.matchAll(/<path d="M ([\d.]+) ([\d.]+) L [\d.]+ ([\d.]+)" fill="none" stroke="#7d786f" stroke-width="0.08"/g)]
            .filter((m) => m[2] !== m[3])
            .map((m) => Math.round((Number(m[1]) - layoutLeft) * 50));
        expect(heads).toContain(4745);
        expect(heads).toContain(4870 - 250);
        expect(heads).not.toContain(4750);
    });

    it('clips the bands to a vaulted ceiling', () => {
        const vaulted = {...room, vaultSpacing: 1000, vaultRise: 200, vaultAngle: 90, beamWidth: 80, beamDrop: 30};
        const walls = box({leftFinishes: [{materialId: 5, bottom: 0, top: 2600}]});
        const [sheet] = roomElevationSheets(scene({rooms: [vaulted], walls}), vaulted, info);
        // The face's content is inside a clip of its outline (a path, not a rectangle).
        expect(sheet.svg).toMatch(/<clipPath id="clip-\d+"><path d="M [^"]*Z" fill="#000000"\/><\/clipPath><g clip-path="url\(#clip-\d+\)">/);
        expect(sheet.svg).toContain('>2600</text>');
    });

    it('draws a double door with its leaf on the hinge as wide as leafWidth', () => {
        const double: Opening = {...door, type: 'DoubleDoor', width: 1200, leafWidth: 900, hinge: 'Start'};
        const [sheet] = roomElevationSheets(scene({walls: box({openings: [double]})}), room, info);
        // Lines from the meeting stile: the door spans plan x 1000–2200 (x = 1800 … 3000 seen from inside), the hinge
        // at the wall's start, i.e. the right-hand edge seen from inside, so the stile is 900 from the right frame.
        const layoutLeft = 16 + 52;
        const stile = (3000 - 50 - 900 - 0) / 50 + layoutLeft;
        expect(sheet.svg).toContain(`M ${stile} `);
    });

    it('draws a herringbone clipped to its band', () => {
        const herringbone = {...tiles, pattern: 'Herringbone' as const, tileWidth: 240, tileHeight: 60};
        const [sheet] = roomElevationSheets(scene({materials: [herringbone]}), room, info);
        expect(sheet.svg).toContain('<clipPath id=');
        expect(sheet.svg.match(/<path d="M[^"]*Z" fill="none" stroke="#7d786f" stroke-width="0.08"/g)?.length).toBeGreaterThan(50);
    });

    it('draws a room without walls not at all', () => {
        expect(roomElevationSheets(scene({walls: []}), room, info)).toEqual([]);
        expect(elevationSheets(scene({walls: []}), info)).toEqual([]);
    });
});

describe('itemsAgainst', () => {
    const item = (fields: Partial<Item>): Item => ({
        id: 1,
        roomId: 1,
        groupId: null,
        kind: 'Custom',
        definitionId: null,
        name: 'Item',
        x: 2000,
        y: 300,
        z: 0,
        width: 1200,
        depth: 600,
        height: 850,
        rotation: 0,
        materialId: null,
        params: {},
        ...fields,
    });

    it('takes the items whose back stands at the wall, with their extent along the face', () => {
        const [south] = roomFaces(room, box());
        const against = itemsAgainst(south, [item({}), item({id: 2, y: 1500}), item({id: 3, x: 3700, y: 450, rotation: 90, width: 900, depth: 600})]);

        expect(against.map((a) => [a.item.id, Math.round(a.from), Math.round(a.to)])).toEqual([
            [1, 1400, 2600],
            [3, 3400, 4000],
        ]);
    });
});

describe('layoutFaces', () => {
    it('takes the largest scale that fits the faces on one page, and runs on to more pages at the last', () => {
        const area = {left: 16, top: 16, width: 265, height: 154, side: {width: 52, height: 60}};
        expect(layoutFaces(roomFaces(room, box()), area).scale).toBe(50);
        // A balcony with the façade along one side only.
        const balcony = {...room, polygon: [[0, 0], [2400, 0], [2400, 1200], [0, 1200]] as PlanPoint[]};
        expect(layoutFaces(roomFaces(balcony, [wall(1, [-150, 1350], [2550, 1350])]), area)).toMatchObject({scale: 20, pages: [expect.any(Array)]});

        const faces = Array.from({length: 12}, () => roomFaces(room, box())[0]);
        const {scale, pages} = layoutFaces(faces, area);
        expect(scale).toBe(50);
        expect(pages.length).toBeGreaterThan(1);
        // Rows below the side column start at the area's left.
        expect(pages[0].some((p) => p.at.x === area.left)).toBe(true);
    });

    it('keeps every face within the paper: a wide façade goes below the side column, a wider one halves the scale', () => {
        const area = {left: 16, top: 16, width: 265, height: 154, side: {width: 52, height: 60}};
        const facade = (length: number): ReturnType<typeof roomFaces> => {
            const balcony = {...room, polygon: [[0, 0], [length, 0], [length, 1200], [0, 1200]] as PlanPoint[]};
            return roomFaces(balcony, [wall(1, [-150, 1350], [length + 150, 1350])]);
        };
        // 11 m at 1:50 is 234 mm: too wide beside the column, so it starts below it.
        const wide = layoutFaces(facade(11000), area);
        expect(wide).toMatchObject({scale: 50, pages: [[{at: {x: 16, y: 16 + 60 + 5}}]]});
        // 14 m is too wide for the paper at 1:50: 1:100.
        expect(layoutFaces(facade(14000), area).scale).toBe(100);
    });
});

