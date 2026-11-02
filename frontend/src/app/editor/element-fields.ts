import {OpeningType, RailingStyle} from '@app/models/scene';
import {isFenceStyle} from '@app/viewer/engine/railings';
import {canSkew, hasVerges, roofRidgeHeight, RoofShapeFields} from '@app/viewer/engine/roof-geometry';
import {ElementKind} from '@app/viewer/engine/selection';
import {stairRisers} from '@app/viewer/engine/stairs';

import {isCustom} from './custom-params';

/**
 * `definition`: the furniture definition of a Custom item; `custom`: the form generated from that definition (parameter
 * values, slot materials); `finishes`: finish bands `[{materialId, bottom, top}]`; `points`: plan points `[[x, y], …]`.
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
    | 'spots'
    | 'finishes'
    | 'points';

/** Opening types in the order of the inspector and the Add menu. */
export const OpeningTypeLabels: Readonly<Record<OpeningType, string>> = {
    Door: 'Door',
    DoubleDoor: 'Double door',
    SlidingDoor: 'Sliding door',
    PocketDoor: 'Pocket door',
    Passage: 'Passage',
    Window: 'Window',
    Niche: 'Niche',
    GarageDoor: 'Garage door',
};

/** Doors with a leaf (or two): not windows, passages or niches. */
const hasLeaf = (element: Record<string, unknown>): boolean =>
    element['type'] !== 'Window' && element['type'] !== 'Passage' && element['type'] !== 'Niche';

/** Openings with a frame of their own: not passages or niches. */
const framed = (element: Record<string, unknown>): boolean => element['type'] !== 'Passage' && element['type'] !== 'Niche';

/** A window, or a lift-and-slide door (with fixed panes): glazing in a frame. */
const glazedFrame = (element: Record<string, unknown>): boolean =>
    element['type'] === 'Window' || (element['type'] === 'SlidingDoor' && Number(element['fixedPanes'] ?? 0) > 0);

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
    /** Starts a collapsible group of fields with this title in the inspector (it runs to the next one). */
    section?: string;
    /** The group this field starts is shown folded at first. */
    folded?: boolean;
    /** `points`: the fewest it may keep (3, an outline), and whether they run open (a railing's) rather than round. */
    minPoints?: number;
    openRun?: boolean;
}

/** A railing's infill, a stair's too. */
const RailingStyles: readonly RailingStyle[] = ['Balusters', 'Rods', 'Glass', 'Panel', 'Cable'];
/** A railing's infill may make it a fence; a stair's may not. */
const RailingAndFenceStyles: readonly RailingStyle[] = [...RailingStyles, 'Mesh', 'Boards'];
const RailingStyleLabels: Readonly<Record<string, string>> = {Mesh: 'Fence: welded mesh', Boards: 'Fence: boards'};
/** What a railing without a material looks like, by style (`railings.ts`): steel, a fence's timber or dark grey mesh. */
const RailingDefaultLooks: Readonly<Record<string, string>> = {Boards: 'timber', Mesh: 'dark grey'};
const isFence = (railing: Record<string, unknown>): boolean => isFenceStyle(railing['style'] as RailingStyle);

const StairShapeLabels: Readonly<Record<string, string>> = {
    Straight: 'Straight',
    LShaped: 'L (quarter landing)',
    UShaped: 'U (half landing)',
    Spiral: 'Spiral',
    Curved: 'Curved (fan-shaped steps)',
};
const hasLanding = (stair: Record<string, unknown>): boolean => stair['shape'] === 'LShaped' || stair['shape'] === 'UShaped';

/** Comfortable stairs: twice the riser plus the going, mm. */
const StepRuleMin = 600;
const StepRuleMax = 650;

/**
 * The riser the stair gets over its own rise or its floor's (`stairRise`, put beside the stair's fields by the panel)
 * and the step rule 2R + G, with a warning when it falls outside 600–650 mm.
 */
export function stairStepHint(stair: Record<string, unknown>): string | null {
    const rise = typeof stair['rise'] === 'number' ? stair['rise'] : stair['stairRise'];
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

const RidgeStyleLabels: Readonly<Record<string, string>> = {
    Box: 'Square cap',
    Round: 'Half-round ridge tiles',
    Angled: 'Angled metal ridge',
};

const GutterLabels: Readonly<Record<string, string>> = {
    None: 'None',
    HalfRound: 'Half-round',
    Box: 'Box',
};

const hasGutters = (roof: Record<string, unknown>): boolean => isPitched(roof) && (roof['gutters'] ?? 'None') !== 'None';

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
    'Fixture', 'Fence', 'Column', 'Beam', 'Chimney', 'Custom', 'Model',
] as const;

const RoomTypes = ['Living', 'Bedroom', 'Bathroom', 'Toilet', 'Hallway', 'Terrace', 'Garden', 'Path', 'Driveway', 'Garage'] as const;

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
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {key: 'kind', label: 'Kind', type: 'select', options: ItemKinds},
        {key: 'definitionId', label: 'Furniture', type: 'definition', when: isCustom},
        {key: 'modelId', label: 'Product (3D model)', type: 'model', when: (item) => item['kind'] === 'Model'},
        {section: 'Position & size', key: 'x', label: 'X', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'Y', type: 'int', unit: 'mm', half: true},
        {key: 'width', label: 'Width', type: 'int', unit: 'mm', half: true},
        {key: 'depth', label: 'Depth', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'Height', type: 'int', unit: 'mm', half: true},
        {
            key: 'z',
            label: 'Elevation',
            labelOf: (item) => (item['onGround'] === true ? 'Above the ground' : item['onRoof'] === true ? 'Above the roof' : 'Elevation'),
            type: 'int',
            unit: 'mm',
            half: true,
        },
        {key: 'rotation', label: 'Rotation', type: 'int', unit: '°'},
        {key: 'onGround', label: 'Stands on the ground (a sloping plot)', type: 'bool', when: (item) => item['onRoof'] !== true},
        {
            key: 'onRoof',
            label: 'Lies on the roof (a solar panel, a vent tile)',
            type: 'bool',
            when: (item) => item['onGround'] !== true && item['kind'] !== 'Chimney',
        },
        {section: 'Room & group', key: 'roomId', label: 'Room', type: 'room', nullable: true},
        {key: 'groupId', label: 'Group', type: 'group', nullable: true},
        {section: 'Look & options', key: 'materialId', label: 'Material', type: 'material', nullable: true},
        {key: 'params', label: 'Options', type: 'custom', when: isCustom},
        {key: 'params', label: 'Params (JSON)', type: 'json', when: (element) => !isCustom(element)},
    ],
    wall: [
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {key: 'type', label: 'Type', type: 'select', options: ['Exterior', 'Interior', 'Shaft']},
        {section: 'Position', folded: true, key: 'startX', label: 'Start X', type: 'int', unit: 'mm', half: true},
        {key: 'startY', label: 'Start Y', type: 'int', unit: 'mm', half: true},
        {key: 'endX', label: 'End X', type: 'int', unit: 'mm', half: true},
        {key: 'endY', label: 'End Y', type: 'int', unit: 'mm', half: true},
        {key: 'sagitta', label: 'Curve: middle off the straight line (+ left, 0 straight)', type: 'int', unit: 'mm'},
        {section: 'Size', key: 'thickness', label: 'Thickness', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'Height', type: 'int', unit: 'mm', half: true},
        {key: 'upToRoof', label: 'Up to the roof (a gable)', type: 'bool'},
        {key: 'heightEnd', label: 'Height at the end (empty: level top)', type: 'int', unit: 'mm', nullable: true},
        {section: 'Ground & base', folded: true, key: 'onGround', label: 'Stands on the ground (a garden wall on a slope)', type: 'bool'},
        {key: 'baseStart', label: 'Bottom at the start', type: 'int', unit: 'mm', half: true, when: (wall) => wall['onGround'] !== true},
        {key: 'baseEnd', label: 'Bottom at the end', type: 'int', unit: 'mm', half: true, when: (wall) => wall['onGround'] !== true},
        {section: 'Finishes', key: 'materialId', label: 'Material', type: 'material', nullable: true},
        {
            key: 'leftFinishes',
            label: 'Left face finishes',
            labelOf: (wall) => `${wallFaceName(wall, 'Left')} finishes`,
            type: 'finishes',
            nullable: true,
        },
        {
            key: 'rightFinishes',
            label: 'Right face finishes',
            labelOf: (wall) => `${wallFaceName(wall, 'Right')} finishes`,
            type: 'finishes',
            nullable: true,
        },
    ],
    opening: [
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {key: 'type', label: 'Type', type: 'select', options: Object.keys(OpeningTypeLabels), optionLabels: OpeningTypeLabels},
        {section: 'Size & position', key: 'offset', label: 'Offset from wall start', type: 'int', unit: 'mm'},
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
            when: (element) =>
                element['type'] === 'Door' ||
                element['type'] === 'DoubleDoor' ||
                (element['type'] === 'GarageDoor' && element['garageDoorStyle'] === 'SideHinged'),
        },
        {
            section: 'Leaf & frame',
            key: 'garageDoorStyle',
            label: 'Garage door',
            type: 'select',
            options: ['Sectional', 'UpAndOver', 'SideHinged', 'Roller'],
            optionLabels: {Sectional: 'Sectional', UpAndOver: 'Up and over', SideHinged: 'Side-hinged', Roller: 'Roller'},
            half: true,
            when: (element) => element['type'] === 'GarageDoor',
        },
        {
            key: 'fixedPanes',
            label: 'Fixed panes (lift-and-slide)',
            type: 'int',
            half: true,
            when: (element) => element['type'] === 'SlidingDoor',
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
            label: 'Side (swing; sliding: hangs on; window: interior; niche: cut into; garage: inside)',
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
            when: (element) => element['type'] !== 'Window' && element['type'] !== 'Niche' && element['type'] !== 'GarageDoor',
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
            options: ['Flush', 'Panelled', 'Glazed', 'Boarded'],
            half: true,
            when: (element) => hasLeaf(element) && !glazedFrame(element),
        },
        {
            key: 'leafPanels',
            label: 'Panels / panes / boards',
            type: 'int',
            half: true,
            when: (element) => hasLeaf(element) && element['leafStyle'] !== 'Flush' && element['leafStyle'] != null && !glazedFrame(element),
        },
        {
            key: 'boardDirection',
            label: 'Boards',
            type: 'select',
            options: ['Vertical', 'Horizontal'],
            half: true,
            when: (element) => hasLeaf(element) && element['leafStyle'] === 'Boarded' && !glazedFrame(element),
        },
        {section: 'Materials', key: 'frameMaterialId', label: 'Frame material', type: 'material', nullable: true, half: true, when: framed},
        {
            key: 'outsideFrameMaterialId',
            label: 'Frame outside (empty: as inside)',
            type: 'material',
            nullable: true,
            half: true,
            when: glazedFrame,
        },
        {
            key: 'leafMaterialId',
            label: 'Leaf material (empty: the frame\'s)',
            type: 'material',
            nullable: true,
            half: true,
            when: (element) => hasLeaf(element) && !glazedFrame(element),
        },
        {
            section: 'Details', folded: true,
            key: 'inset',
            label: 'Frame in from the outer face (empty: centred)',
            type: 'int',
            unit: 'mm',
            nullable: true,
            when: (element) =>
                framed(element) &&
                element['type'] !== 'PocketDoor' &&
                element['type'] !== 'GarageDoor' &&
                (element['type'] !== 'SlidingDoor' || glazedFrame(element)),
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
        {
            section: 'Walkthrough',
            folded: true,
            key: 'entrance',
            label: 'Main entrance (walkthrough starts here)',
            type: 'bool',
            when: (element) => element['type'] !== 'Niche',
        },
        {key: 'walkable', label: 'Walk through (opens in the walkthrough)', type: 'bool', when: (element) => element['type'] === 'Window'},
        {key: 'revealLining', label: 'Line the reveal with the façade', type: 'bool', when: (element) => element['type'] !== 'Niche'},
    ],
    plot: [
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {section: 'Ground', key: 'groundMaterialId', label: 'Ground (empty: lawn)', type: 'material', nullable: true},
        {key: 'groundLevel', label: 'Ground level (+ above, − below the floor)', type: 'int', unit: 'mm'},
        {key: 'terrain', label: 'Spot heights (sloping ground)', type: 'spots'},
        {section: 'Boundary', folded: true, key: 'polygon', label: 'Boundary corners', type: 'points'},
    ],
    stair: [
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {key: 'shape', label: 'Shape', type: 'select', options: Object.keys(StairShapeLabels), optionLabels: StairShapeLabels},
        {section: 'Position', key: 'x', label: 'First riser X', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'First riser Y', type: 'int', unit: 'mm', half: true},
        {key: 'rotation', label: 'Walking-up direction (0 north, counter-clockwise)', type: 'int', unit: '°'},
        {
            section: 'Size & steps',
            key: 'width',
            label: 'Width',
            labelOf: (stair) => (stair['shape'] === 'Spiral' ? 'Diameter' : 'Flight width'),
            type: 'int',
            unit: 'mm',
            half: true,
        },
        {key: 'going', label: 'Going (tread depth)', type: 'int', unit: 'mm', half: true},
        {key: 'rise', label: 'Own rise (garden steps; empty: to the next floor)', type: 'int', unit: 'mm', nullable: true, half: true},
        {key: 'baseZ', label: 'First riser at', type: 'int', unit: 'mm', half: true},
        {key: 'risers', label: 'Risers (empty: as the rise needs)', type: 'int', nullable: true, hint: stairStepHint},
        {key: 'firstFlight', label: 'Risers up to the landing (empty: half)', type: 'int', nullable: true, half: true, when: hasLanding},
        {
            key: 'turn',
            label: 'Turns',
            type: 'select',
            options: ['Left', 'Right'],
            half: true,
            when: (stair) => hasLanding(stair) || stair['shape'] === 'Spiral' || stair['shape'] === 'Curved',
        },
        {
            key: 'innerRadius',
            label: 'Inner edge from the centre',
            type: 'int',
            unit: 'mm',
            half: true,
            when: (stair) => stair['shape'] === 'Curved',
        },
        {key: 'gap', label: 'Gap between the flights', type: 'int', unit: 'mm', half: true, when: (stair) => stair['shape'] === 'UShaped'},
        {
            section: 'Construction',
            key: 'construction',
            label: 'Construction',
            type: 'select',
            options: ['Solid', 'Stringers', 'Open'],
            optionLabels: {Solid: 'Solid (concrete)', Stringers: 'Stringers', Open: 'Open (floating treads)'},
            half: true,
            when: (stair) => stair['shape'] !== 'Spiral',
        },
        {section: 'Railing', key: 'railing', label: 'Railing (walking up)', type: 'select', options: ['None', 'Left', 'Right', 'Both'], half: true},
        {
            key: 'railingStyle',
            label: 'Railing infill (empty: posts only)',
            type: 'select',
            options: RailingStyles,
            nullable: true,
            half: true,
            when: (stair) => stair['railing'] !== 'None',
        },
        {key: 'wallHandrail', label: 'Wall handrail', type: 'select', options: ['None', 'Left', 'Right', 'Both'], half: true},
        {key: 'railingMaterialId', label: 'Railing material', type: 'material', nullable: true, half: true},
        {section: 'Materials', key: 'treadMaterialId', label: 'Tread material', type: 'material', nullable: true, half: true},
        {key: 'structureMaterialId', label: 'Structure material', type: 'material', nullable: true, half: true},
        {
            key: 'voidLiningMaterialId',
            label: 'Lining of the void upstairs',
            type: 'material',
            nullable: true,
            when: (stair) => stair['rise'] == null,
        },
    ],
    void: [
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {section: 'Lining', key: 'liningMaterialId', label: 'Edge lining (empty: none)', type: 'material', nullable: true, half: true},
        {key: 'liningDepth', label: 'Lining depth (empty: to the ceilings below)', type: 'int', unit: 'mm', nullable: true, half: true},
        {section: 'Outline', folded: true, key: 'polygon', label: 'Outline corners', type: 'points'},
    ],
    railing: [
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {key: 'style', label: 'Infill', type: 'select', options: RailingAndFenceStyles, optionLabels: RailingStyleLabels, half: true},
        {
            key: 'handrail',
            label: 'Handrail',
            type: 'select',
            options: ['Round', 'Square', 'Flat', 'None'],
            optionLabels: {Flat: 'Flat cap'},
            half: true,
            when: (railing) => !isFence(railing),
        },
        {
            key: 'follow',
            label: 'On the ground',
            type: 'select',
            options: ['Level', 'Rake', 'Step'],
            optionLabels: {Level: 'No: level at its base', Rake: 'Raking with it', Step: 'Stepping per bay'},
            half: true,
            when: isFence,
        },
        {section: 'Size', key: 'height', label: 'Height', type: 'int', unit: 'mm', half: true},
        {key: 'baseZ', label: 'Base (+ above, − below the floor)', type: 'int', unit: 'mm', half: true},
        {
            key: 'spacing',
            label: 'Spacing (empty: the style\'s)',
            labelOf: (railing) => (railing['style'] === 'Balusters' ? 'Gap between balusters (empty: 100)' : 'Pitch (empty: the style\'s)'),
            type: 'int',
            unit: 'mm',
            nullable: true,
            half: true,
            when: (railing) => railing['style'] !== 'Glass' && railing['style'] !== 'Panel' && !isFence(railing),
        },
        {key: 'postSpacing', label: 'Posts at most', type: 'int', unit: 'mm', half: true},
        {
            section: 'Materials',
            key: 'materialId',
            label: 'Material (empty: steel)',
            labelOf: (railing) => `Material (empty: ${RailingDefaultLooks[railing['style'] as string] ?? 'steel'})`,
            type: 'material',
            nullable: true,
            half: true,
        },
        {
            key: 'infillMaterialId',
            label: 'Infill material',
            type: 'material',
            nullable: true,
            half: true,
            when: (railing) => railing['style'] === 'Glass' || railing['style'] === 'Panel',
        },
        {section: 'Points', folded: true, key: 'points', label: 'Points', type: 'points', minPoints: 2, openRun: true},
    ],
    section: [
        {section: 'General', key: 'name', label: 'Name (A: Section A–A)', type: 'text'},
        {section: 'Line', key: 'startX', label: 'Start x', type: 'int', unit: 'mm', half: true},
        {key: 'startY', label: 'Start y', type: 'int', unit: 'mm', half: true},
        {key: 'endX', label: 'End x', type: 'int', unit: 'mm', half: true},
        {key: 'endY', label: 'End y', type: 'int', unit: 'mm', half: true},
        {key: 'depth', label: 'Depth beyond the cut (empty: all)', type: 'int', unit: 'mm', nullable: true},
    ],
    roof: [
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {key: 'shape', label: 'Shape', type: 'select', options: Object.keys(RoofShapeLabels), optionLabels: RoofShapeLabels},
        {section: 'Position & size', key: 'x', label: 'Centre X', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'Centre Y', type: 'int', unit: 'mm', half: true},
        {key: 'rotation', label: 'Ridge direction (0 east, counter-clockwise)', type: 'int', unit: '°'},
        {key: 'width', label: 'Width along the ridge', type: 'int', unit: 'mm', half: true},
        {key: 'depth', label: 'Depth across it', type: 'int', unit: 'mm', half: true},
        {section: 'Slope', key: 'eaveHeight', label: 'Eaves height (underside)', type: 'int', unit: 'mm', half: true},
        {key: 'pitch', label: 'Pitch', type: 'int', unit: '°', half: true, when: isPitched, hint: roofRidgeHint},
        {key: 'overhang', label: 'Eaves overhang', type: 'int', unit: 'mm', half: true},
        {
            key: 'vergeOverhang',
            label: 'Verge overhang',
            type: 'int',
            unit: 'mm',
            half: true,
            when: (roof) => hasVerges(roof as Pick<RoofShapeFields, 'shape'>),
        },
        {
            key: 'startSkew',
            label: 'Start verge skew',
            labelOf: (roof) => (roof['shape'] === 'Flat' ? 'Start edge skew' : 'Start verge skew'),
            type: 'int',
            unit: '°',
            half: true,
            when: (roof) => canSkew(roof as Pick<RoofShapeFields, 'shape'>),
        },
        {
            key: 'endSkew',
            label: 'End verge skew',
            labelOf: (roof) => (roof['shape'] === 'Flat' ? 'End edge skew' : 'End verge skew'),
            type: 'int',
            unit: '°',
            half: true,
            when: (roof) => canSkew(roof as Pick<RoofShapeFields, 'shape'>),
        },
        {key: 'thickness', label: 'Thickness (square to the slope)', type: 'int', unit: 'mm', half: true},
        {key: 'parapet', label: 'Parapet (empty: none)', type: 'int', unit: 'mm', nullable: true, half: true, when: (roof) => !isPitched(roof)},
        {section: 'Covering', key: 'coveringMaterialId', label: 'Covering', type: 'material', nullable: true, half: true},
        {key: 'soffitMaterialId', label: 'Soffit', type: 'material', nullable: true, half: true},
        {key: 'ridgeStyle', label: 'Ridge', type: 'select', options: Object.keys(RidgeStyleLabels), optionLabels: RidgeStyleLabels, when: isPitched},
        {key: 'snowGuards', label: 'Rows of snow guards', type: 'int', half: true, when: isPitched},
        {
            section: 'Rainwater', folded: true,
            key: 'gutters',
            label: 'Gutters',
            type: 'select',
            options: Object.keys(GutterLabels),
            optionLabels: GutterLabels,
            half: true,
            when: isPitched,
        },
        {key: 'gutterMaterialId', label: 'Gutters and downpipes', type: 'material', nullable: true, when: hasGutters},
        {
            key: 'downpipes',
            label: 'Downpipes [{edge: Front | Back | Left | Right, along}] (JSON, empty: at the corners)',
            type: 'json',
            nullable: true,
            when: hasGutters,
        },
    ],
    roofWindow: [
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {key: 'type', label: 'Sash', type: 'select', options: Object.keys(RoofWindowTypeLabels), optionLabels: RoofWindowTypeLabels},
        {section: 'Position & size', key: 'along', label: 'Along the ridge (from the roof centre)', type: 'int', unit: 'mm', half: true},
        {key: 'across', label: 'Across it (+ to the back)', type: 'int', unit: 'mm', half: true},
        {key: 'width', label: 'Width', type: 'int', unit: 'mm', half: true},
        {key: 'length', label: 'Length up the slope', type: 'int', unit: 'mm', half: true},
        {section: 'Look', key: 'frameMaterialId', label: 'Frame', type: 'material', nullable: true},
    ],
    room: [
        {section: 'General', key: 'name', label: 'Name', type: 'text'},
        {key: 'code', label: 'Code', type: 'text', half: true},
        {key: 'type', label: 'Type', type: 'select', options: RoomTypes, half: true},
        {section: 'Height & level', key: 'floorElevation', label: 'Floor level (+ raised, − sunken)', type: 'int', unit: 'mm'},
        {key: 'ceilingHeight', label: 'Ceiling height (from its floor)', type: 'int', unit: 'mm', nullable: true},
        {section: 'Surfaces', key: 'floorMaterialId', label: 'Floor material', type: 'material', nullable: true},
        {key: 'ceilingMaterialId', label: 'Ceiling material', type: 'material', nullable: true, when: (room) => room['ceilingHeight'] != null},
        {
            section: 'Vaulted ceiling', folded: true,
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
        {section: 'Skirting & soffit', key: 'skirtingHeight', label: 'Skirting', type: 'int', unit: 'mm', nullable: true, half: true},
        {
            key: 'skirtingMaterialId',
            label: 'Skirting material',
            type: 'material',
            nullable: true,
            half: true,
            when: (room) => room['skirtingHeight'] != null,
        },
        {key: 'soffitMaterialId', label: 'Slab soffit where it overhangs (empty: façade)', type: 'material', nullable: true},
        {section: 'Outline', folded: true, key: 'polygon', label: 'Outline corners', type: 'points'},
        {section: 'Wall finishes', key: 'wallFinishes', label: 'Bands', type: 'finishes'},
        {section: 'Tiling', folded: true, key: 'tileOriginX', label: 'Tile joints cross at x', type: 'int', unit: 'mm', nullable: true, half: true},
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
