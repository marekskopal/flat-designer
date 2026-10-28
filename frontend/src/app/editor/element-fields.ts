import {OpeningType} from '@app/models/scene';
import {roofRidgeHeight, RoofShapeFields} from '@app/viewer/engine/roof-geometry';
import {ElementKind} from '@app/viewer/engine/selection';
import {stairRisers} from '@app/viewer/engine/stairs';

import {isCustom} from './custom-params';

/**
 * `definition`: the furniture definition of a Custom item; `custom`: the form generated from that definition (parameter
 * values, slot materials).
 */
export type FieldType =
    | 'int'
    | 'text'
    | 'select'
    | 'material'
    | 'room'
    | 'group'
    | 'json'
    | 'bool'
    | 'definition'
    | 'model'
    | 'custom'
    | 'spots';

/** Opening types in the order of the inspector and the Add menu. */
export const OpeningTypeLabels: Readonly<Record<OpeningType, string>> = {
    Door: 'Door',
    DoubleDoor: 'Double door',
    SlidingDoor: 'Sliding door',
    PocketDoor: 'Pocket door',
    Passage: 'Passage',
    Window: 'Window',
    Niche: 'Niche',
};

/** Doors with a leaf (or two): not windows, passages or niches. */
const hasLeaf = (element: Record<string, unknown>): boolean =>
    element['type'] !== 'Window' && element['type'] !== 'Passage' && element['type'] !== 'Niche';

/** One editable field of an element, as the inspector shows it. */
export interface FieldDef {
    key: string;
    label: string;
    type: FieldType;
    /** May be cleared (empty input = null). */
    nullable?: boolean;
    options?: readonly string[];
    /** Shown instead of the option values that are not readable as they are. */
    optionLabels?: Readonly<Record<string, string>>;
    unit?: string;
    /** Shown in pairs on one row. */
    half?: boolean;
    /** Shown only for elements it applies to (e.g. by item kind). */
    when?: (element: Record<string, unknown>) => boolean;
    /** The label for the element at hand, in place of `label` (e.g. the compass side of a wall face). */
    labelOf?: (element: Record<string, unknown>) => string;
    /** A note under the field about the element at hand (a derived value, a warning); null for none. */
    hint?: (element: Record<string, unknown>) => string | null;
}

const StairShapeLabels: Readonly<Record<string, string>> = {
    Straight: 'Straight',
    LShaped: 'L (quarter landing)',
    UShaped: 'U (half landing)',
    Spiral: 'Spiral',
};
const hasLanding = (stair: Record<string, unknown>): boolean => stair['shape'] === 'LShaped' || stair['shape'] === 'UShaped';

/** Comfortable stairs: twice the riser plus the going, mm. */
const StepRuleMin = 600;
const StepRuleMax = 650;

/**
 * The riser the stair gets over its floor's rise (`stairRise`, put beside the stair's fields by the panel) and the
 * step rule 2R + G, with a warning when it falls outside 600–650 mm.
 */
export function stairStepHint(stair: Record<string, unknown>): string | null {
    const rise = stair['stairRise'];
    const going = stair['going'];
    if (typeof rise !== 'number' || typeof going !== 'number' || rise <= 0) {
        return null;
    }
    const count = stairRisers(rise, typeof stair['risers'] === 'number' ? stair['risers'] : null);
    const riser = rise / count;
    const rule = 2 * riser + going;
    const comfort = rule < StepRuleMin || rule > StepRuleMax ? ` — outside the comfortable ${StepRuleMin}–${StepRuleMax}` : '';
    return `${count} risers of ${riser.toFixed(0)} mm over ${rise} mm; 2R + G = ${rule.toFixed(0)} mm${comfort}`;
}

const RoofShapeLabels: Readonly<Record<string, string>> = {
    Gable: 'Gable',
    Hip: 'Hip (pyramid over a square)',
    Shed: 'Shed (one slope)',
    Flat: 'Flat',
};
const isPitched = (roof: Record<string, unknown>): boolean => roof['shape'] !== 'Flat';

const RoofWindowTypeLabels: Readonly<Record<string, string>> = {
    CentrePivot: 'Centre pivot',
    TopHung: 'Top hung',
};

/** The height the roof's underside reaches at its ridge (a shed's top edge), above the floor's level. */
export function roofRidgeHint(roof: Record<string, unknown>): string | null {
    const fields = ['width', 'depth', 'eaveHeight', 'pitch'] as const;
    if (!isPitched(roof) || fields.some((key) => typeof roof[key] !== 'number')) {
        return null;
    }
    const ridge = roofRidgeHeight(roof as unknown as RoofShapeFields);
    return `Underside ${(ridge / 1000).toFixed(2)} m above the floor at the ${roof['shape'] === 'Shed' ? 'top edge' : 'ridge'}`;
}

const ItemKinds = [
    'Sink', 'Hob', 'LedStrip', 'Box', 'Bathtub', 'Washbasin', 'Toilet', 'WashingMachine', 'Mirror', 'TowelRadiator', 'FlushPlate',
    'Fixture', 'Fence', 'Column', 'Beam', 'Custom', 'Model',
] as const;

const RoomTypes = ['Living', 'Bedroom', 'Bathroom', 'Toilet', 'Hallway', 'Terrace', 'Garden', 'Path', 'Driveway'] as const;

/**
 * A wall face by the compass direction it faces, e.g. "North face" for the left face of a wall running east (at its
 * middle for a curved wall, whose faces are also told apart as convex or concave).
 */
export function wallFaceName(wall: Record<string, unknown>, side: 'Left' | 'Right'): string {
    const coordinate = (key: string): number => (typeof wall[key] === 'number' ? wall[key] : 0);
    const dx = coordinate('endX') - coordinate('startX');
    const dy = coordinate('endY') - coordinate('startY');
    // The left normal of start → end is (−dy, dx); the right one points the other way.
    const [nx, ny] = side === 'Left' ? [-dy, dx] : [dy, -dx];
    if (nx === 0 && ny === 0) {
        return `${side} face`;
    }
    const eastWest = nx > 0 ? 'East' : 'West';
    const northSouth = ny > 0 ? 'North' : 'South';
    const direction =
        Math.abs(nx) > Math.abs(ny) ? eastWest : Math.abs(ny) > Math.abs(nx) ? northSouth : `${northSouth}-${eastWest.toLowerCase()}`;
    const sagitta = coordinate('sagitta');
    const curve = sagitta === 0 ? '' : (sagitta > 0) === (side === 'Left') ? ' (convex)' : ' (concave)';
    return `${direction} face${curve}`;
}

/** The editable fields per element kind; keys are those of the scene JSON and the edit API. */
export const ElementFields: Record<ElementKind, readonly FieldDef[]> = {
    item: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'kind', label: 'Kind', type: 'select', options: ItemKinds},
        {key: 'definitionId', label: 'Furniture', type: 'definition', when: isCustom},
        {key: 'modelId', label: 'Product (3D model)', type: 'model', when: (item) => item['kind'] === 'Model'},
        {key: 'x', label: 'X', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'Y', type: 'int', unit: 'mm', half: true},
        {key: 'width', label: 'Width', type: 'int', unit: 'mm', half: true},
        {key: 'depth', label: 'Depth', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'Height', type: 'int', unit: 'mm', half: true},
        {
            key: 'z',
            label: 'Elevation',
            labelOf: (item) => (item['onGround'] === true ? 'Above the ground' : 'Elevation'),
            type: 'int',
            unit: 'mm',
            half: true,
        },
        {key: 'rotation', label: 'Rotation', type: 'int', unit: '°'},
        {key: 'onGround', label: 'Stands on the ground (a sloping plot)', type: 'bool'},
        {key: 'roomId', label: 'Room', type: 'room', nullable: true},
        {key: 'groupId', label: 'Group', type: 'group', nullable: true},
        {key: 'materialId', label: 'Material', type: 'material', nullable: true},
        {key: 'params', label: 'Options', type: 'custom', when: isCustom},
        {key: 'params', label: 'Params (JSON)', type: 'json', when: (element) => !isCustom(element)},
    ],
    wall: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'type', label: 'Type', type: 'select', options: ['Exterior', 'Interior', 'Shaft']},
        {key: 'startX', label: 'Start X', type: 'int', unit: 'mm', half: true},
        {key: 'startY', label: 'Start Y', type: 'int', unit: 'mm', half: true},
        {key: 'endX', label: 'End X', type: 'int', unit: 'mm', half: true},
        {key: 'endY', label: 'End Y', type: 'int', unit: 'mm', half: true},
        {key: 'sagitta', label: 'Curve: middle off the straight line (+ left, 0 straight)', type: 'int', unit: 'mm'},
        {key: 'thickness', label: 'Thickness', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'Height', type: 'int', unit: 'mm', half: true},
        {key: 'upToRoof', label: 'Up to the roof (a gable)', type: 'bool'},
        {key: 'materialId', label: 'Material', type: 'material', nullable: true},
        {
            key: 'leftFinishes',
            label: 'Left face finishes',
            labelOf: (wall) => `${wallFaceName(wall, 'Left')} finishes [{materialId, bottom, top}] (JSON, empty: the room's)`,
            type: 'json',
            nullable: true,
        },
        {
            key: 'rightFinishes',
            label: 'Right face finishes',
            labelOf: (wall) => `${wallFaceName(wall, 'Right')} finishes [{materialId, bottom, top}] (JSON, empty: the room's)`,
            type: 'json',
            nullable: true,
        },
    ],
    opening: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'type', label: 'Type', type: 'select', options: Object.keys(OpeningTypeLabels), optionLabels: OpeningTypeLabels},
        {key: 'offset', label: 'Offset from wall start', type: 'int', unit: 'mm'},
        {key: 'width', label: 'Width', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'Height', type: 'int', unit: 'mm', half: true},
        {key: 'sillHeight', label: 'Sill', type: 'int', unit: 'mm', half: true},
        {
            key: 'leafWidth',
            label: 'Leaf width (the hinge leaf of a double door)',
            type: 'int',
            unit: 'mm',
            nullable: true,
            half: true,
            when: (element) => element['type'] === 'Door' || element['type'] === 'DoubleDoor',
        },
        {
            key: 'hinge',
            label: 'Hinge (sliding: opens towards)',
            type: 'select',
            options: ['Start', 'End'],
            nullable: true,
            half: true,
            when: (element) => element['type'] !== 'Passage' && element['type'] !== 'Niche',
        },
        {
            key: 'side',
            label: 'Side (swing; sliding: hangs on; window: interior; niche: cut into)',
            type: 'select',
            options: ['Left', 'Right'],
            nullable: true,
            half: true,
            when: (element) => element['type'] !== 'Passage' && element['type'] !== 'PocketDoor',
        },
        {
            key: 'frameStyle',
            label: 'Frame',
            type: 'select',
            options: ['Lining', 'Architrave', 'Flush', 'None'],
            half: true,
            when: (element) => element['type'] !== 'Window' && element['type'] !== 'Niche',
        },
        {
            key: 'handle',
            label: 'Handle',
            type: 'select',
            options: ['Lever', 'Knob', 'Pull', 'None'],
            nullable: true,
            half: true,
            when: hasLeaf,
        },
        {key: 'mullions', label: 'Mullions (vertical bars)', type: 'int', half: true, when: (element) => element['type'] === 'Window'},
        {key: 'transoms', label: 'Transoms (horizontal bars)', type: 'int', half: true, when: (element) => element['type'] === 'Window'},
        {
            key: 'sillBoard',
            label: 'Sill board (reach past the interior wall face)',
            type: 'int',
            unit: 'mm',
            nullable: true,
            when: (element) => element['type'] === 'Window',
        },
        {
            key: 'leafStyle',
            label: 'Leaf',
            type: 'select',
            options: ['Flush', 'Panelled', 'Glazed'],
            half: true,
            when: hasLeaf,
        },
        {
            key: 'leafPanels',
            label: 'Panels / panes',
            type: 'int',
            half: true,
            when: (element) => element['leafStyle'] === 'Panelled' || element['leafStyle'] === 'Glazed',
        },
        {
            key: 'panelHeight',
            label: 'Solid lower panel (from the sill)',
            type: 'int',
            unit: 'mm',
            nullable: true,
            when: (element) => element['type'] === 'Window',
        },
        {
            key: 'shutters',
            label: 'Shutters (outside)',
            type: 'select',
            options: ['Louvred', 'Solid'],
            nullable: true,
            half: true,
            when: (element) => element['type'] === 'Window',
        },
        {
            key: 'shutterMaterialId',
            label: 'Shutter material',
            type: 'material',
            nullable: true,
            half: true,
            when: (element) => element['type'] === 'Window' && element['shutters'] != null,
        },
        {
            key: 'shuttersClosed',
            label: 'Shutters closed',
            type: 'bool',
            when: (element) => element['type'] === 'Window' && element['shutters'] != null,
        },
        {key: 'depth', label: 'Depth (into the wall)', type: 'int', unit: 'mm', when: (element) => element['type'] === 'Niche'},
        {key: 'entrance', label: 'Main entrance (walkthrough starts here)', type: 'bool', when: (element) => element['type'] !== 'Niche'},
        {key: 'walkable', label: 'Walk through (opens in the walkthrough)', type: 'bool', when: (element) => element['type'] === 'Window'},
    ],
    plot: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'groundMaterialId', label: 'Ground (empty: lawn)', type: 'material', nullable: true},
        {key: 'groundLevel', label: 'Ground level (+ above, − below the floor)', type: 'int', unit: 'mm'},
        {key: 'terrain', label: 'Spot heights (sloping ground)', type: 'spots'},
        {key: 'polygon', label: 'Boundary [[x, y], …] (JSON)', type: 'json'},
    ],
    stair: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'shape', label: 'Shape', type: 'select', options: Object.keys(StairShapeLabels), optionLabels: StairShapeLabels},
        {key: 'x', label: 'First riser X', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'First riser Y', type: 'int', unit: 'mm', half: true},
        {key: 'rotation', label: 'Walking-up direction (0 north, counter-clockwise)', type: 'int', unit: '°'},
        {
            key: 'width',
            label: 'Width',
            labelOf: (stair) => (stair['shape'] === 'Spiral' ? 'Diameter' : 'Flight width'),
            type: 'int',
            unit: 'mm',
            half: true,
        },
        {key: 'going', label: 'Going (tread depth)', type: 'int', unit: 'mm', half: true},
        {key: 'risers', label: 'Risers (empty: as the rise needs)', type: 'int', nullable: true, hint: stairStepHint},
        {key: 'firstFlight', label: 'Risers up to the landing (empty: half)', type: 'int', nullable: true, half: true, when: hasLanding},
        {
            key: 'turn',
            label: 'Turns',
            type: 'select',
            options: ['Left', 'Right'],
            half: true,
            when: (stair) => hasLanding(stair) || stair['shape'] === 'Spiral',
        },
        {key: 'gap', label: 'Gap between the flights', type: 'int', unit: 'mm', half: true, when: (stair) => stair['shape'] === 'UShaped'},
        {
            key: 'construction',
            label: 'Construction',
            type: 'select',
            options: ['Solid', 'Stringers', 'Open'],
            optionLabels: {Solid: 'Solid (concrete)', Stringers: 'Stringers', Open: 'Open (floating treads)'},
            half: true,
            when: (stair) => stair['shape'] !== 'Spiral',
        },
        {key: 'railing', label: 'Handrail (walking up)', type: 'select', options: ['None', 'Left', 'Right', 'Both'], half: true},
        {key: 'treadMaterialId', label: 'Tread material', type: 'material', nullable: true, half: true},
        {key: 'structureMaterialId', label: 'Structure material', type: 'material', nullable: true, half: true},
    ],
    roof: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'shape', label: 'Shape', type: 'select', options: Object.keys(RoofShapeLabels), optionLabels: RoofShapeLabels},
        {key: 'x', label: 'Centre X', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'Centre Y', type: 'int', unit: 'mm', half: true},
        {key: 'rotation', label: 'Ridge direction (0 east, counter-clockwise)', type: 'int', unit: '°'},
        {key: 'width', label: 'Width along the ridge', type: 'int', unit: 'mm', half: true},
        {key: 'depth', label: 'Depth across it', type: 'int', unit: 'mm', half: true},
        {key: 'eaveHeight', label: 'Eaves height (underside)', type: 'int', unit: 'mm', half: true},
        {key: 'pitch', label: 'Pitch', type: 'int', unit: '°', half: true, when: isPitched, hint: roofRidgeHint},
        {key: 'overhang', label: 'Eaves overhang', type: 'int', unit: 'mm', half: true},
        {
            key: 'vergeOverhang',
            label: 'Verge overhang',
            type: 'int',
            unit: 'mm',
            half: true,
            when: (roof) => roof['shape'] === 'Gable' || roof['shape'] === 'Shed',
        },
        {key: 'thickness', label: 'Thickness (square to the slope)', type: 'int', unit: 'mm', half: true},
        {key: 'parapet', label: 'Parapet (empty: none)', type: 'int', unit: 'mm', nullable: true, half: true, when: (roof) => !isPitched(roof)},
        {key: 'coveringMaterialId', label: 'Covering', type: 'material', nullable: true, half: true},
        {key: 'soffitMaterialId', label: 'Soffit', type: 'material', nullable: true, half: true},
    ],
    roofWindow: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'type', label: 'Sash', type: 'select', options: Object.keys(RoofWindowTypeLabels), optionLabels: RoofWindowTypeLabels},
        {key: 'along', label: 'Along the ridge (from the roof centre)', type: 'int', unit: 'mm', half: true},
        {key: 'across', label: 'Across it (+ to the back)', type: 'int', unit: 'mm', half: true},
        {key: 'width', label: 'Width', type: 'int', unit: 'mm', half: true},
        {key: 'length', label: 'Length up the slope', type: 'int', unit: 'mm', half: true},
        {key: 'frameMaterialId', label: 'Frame', type: 'material', nullable: true},
    ],
    room: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'code', label: 'Code', type: 'text', half: true},
        {key: 'type', label: 'Type', type: 'select', options: RoomTypes, half: true},
        {key: 'floorElevation', label: 'Floor level (+ raised, − sunken)', type: 'int', unit: 'mm'},
        {key: 'ceilingHeight', label: 'Ceiling height (from its floor)', type: 'int', unit: 'mm', nullable: true},
        {key: 'floorMaterialId', label: 'Floor material', type: 'material', nullable: true},
        {key: 'ceilingMaterialId', label: 'Ceiling material', type: 'material', nullable: true, when: (room) => room['ceilingHeight'] != null},
        {
            key: 'vaultSpacing',
            label: 'Vaulted ceiling: beams every',
            type: 'int',
            unit: 'mm',
            nullable: true,
            when: (room) => room['ceilingHeight'] != null,
        },
        {key: 'vaultAngle', label: 'Beams run at', type: 'int', unit: '°', half: true, when: (room) => room['vaultSpacing'] != null},
        {key: 'vaultRise', label: 'Vault rise', type: 'int', unit: 'mm', half: true, when: (room) => room['vaultSpacing'] != null},
        {key: 'beamWidth', label: 'Beam width', type: 'int', unit: 'mm', half: true, when: (room) => room['vaultSpacing'] != null},
        {key: 'beamDrop', label: 'Beam below vaults', type: 'int', unit: 'mm', half: true, when: (room) => room['vaultSpacing'] != null},
        {key: 'beamMaterialId', label: 'Beam material', type: 'material', nullable: true, when: (room) => room['vaultSpacing'] != null},
        {key: 'skirtingHeight', label: 'Skirting', type: 'int', unit: 'mm', nullable: true, half: true},
        {
            key: 'skirtingMaterialId',
            label: 'Skirting material',
            type: 'material',
            nullable: true,
            half: true,
            when: (room) => room['skirtingHeight'] != null,
        },
        {key: 'polygon', label: 'Outline [[x, y], …] (JSON)', type: 'json'},
        {key: 'wallFinishes', label: 'Wall finishes [{materialId, bottom, top}] (JSON)', type: 'json'},
        {key: 'tileOriginX', label: 'Tile joints cross at x', type: 'int', unit: 'mm', nullable: true, half: true},
        {key: 'tileOriginY', label: 'Tile joints cross at y', type: 'int', unit: 'mm', nullable: true, half: true},
        {key: 'floorAngle', label: 'Floor laid at', type: 'int', unit: '°', half: true},
    ],
    group: [{key: 'name', label: 'Name', type: 'text'}],
    variantSet: [{key: 'name', label: 'Name', type: 'text'}],
};

export type ParsedValue = {ok: true; value: unknown} | {ok: false; error: string};

/** The input text for a field's current value. */
export function formatFieldValue(def: FieldDef, value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (def.type === 'json') {
        return JSON.stringify(value);
    }
    return String(value);
}

/** Turns what the user typed into the value the API expects, or explains why it is not valid. */
export function parseFieldValue(def: FieldDef, raw: string): ParsedValue {
    const text = raw.trim();
    if (text === '') {
        if (def.nullable === true) {
            return {ok: true, value: null};
        }
        if (def.type === 'json') {
            return {ok: false, error: 'Enter JSON.'};
        }
        return {ok: false, error: `${def.label} is required.`};
    }

    switch (def.type) {
        case 'bool':
            return {ok: true, value: text === 'true'};
        case 'int': {
            if (!/^-?\d+$/.test(text)) {
                return {ok: false, error: 'Whole number expected.'};
            }
            return {ok: true, value: Number(text)};
        }
        case 'material':
        case 'room':
        case 'group':
        case 'definition':
        case 'model':
            return {ok: true, value: Number(text)};
        case 'json':
            try {
                return {ok: true, value: JSON.parse(text) as unknown};
            } catch {
                return {ok: false, error: 'Invalid JSON.'};
            }
        default:
            return {ok: true, value: text};
    }
}

/** Whether a parsed value differs from the current one (JSON values compared by content). */
export function isChanged(current: unknown, next: unknown): boolean {
    return JSON.stringify(current ?? null) !== JSON.stringify(next ?? null);
}
