import {decimalSeparator, fixed} from '@app/core/number-format';
import {OpeningType, RailingStyle} from '@app/models/scene';
import {pluralText} from '@app/shared/i18n/plural.pipe';
import {isFenceStyle} from '@app/viewer/engine/railings';
import {canSkew, hasVerges, roofRidgeHeight, RoofShapeFields} from '@app/viewer/engine/roof-geometry';
import {ElementKind} from '@app/viewer/engine/selection';
import {stairRisers} from '@app/viewer/engine/stairs';
import {TranslateService} from '@ngx-translate/core';

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

/** Opening types in the order of the inspector and the Add menu (named by `enums.openingType.<type>`). */
export const OpeningTypes: readonly OpeningType[] = ['Door', 'DoubleDoor', 'SlidingDoor', 'PocketDoor', 'Passage', 'Window', 'Niche', 'GarageDoor'];

/** Doors with a leaf (or two): not windows, passages or niches. */
const hasLeaf = (element: Record<string, unknown>): boolean =>
    element['type'] !== 'Window' && element['type'] !== 'Passage' && element['type'] !== 'Niche';

/** Openings with a frame of their own: not passages or niches. */
const framed = (element: Record<string, unknown>): boolean => element['type'] !== 'Passage' && element['type'] !== 'Niche';

/** A window, or a lift-and-slide door (with fixed panes): glazing in a frame. */
const glazedFrame = (element: Record<string, unknown>): boolean =>
    element['type'] === 'Window' || (element['type'] === 'SlidingDoor' && Number(element['fixedPanes'] ?? 0) > 0);

/**
 * A text to translate: a key with its params, where a param may be a text of its own (translated first) and `count`
 * picks the plural form (`key.one|few|other`).
 */
export interface FieldText {
    key: string;
    params?: Readonly<Record<string, string | number | FieldText | FieldDecimal>>;
    count?: number;
}

/** A param written with `digits` decimals and the separator of the language shown. */
export interface FieldDecimal {
    decimal: number;
    digits: number;
}

/** The text in the language shown (`locale` picks a plural form). */
export function translateFieldText(translate: TranslateService, locale: string, text: FieldText): string {
    const params: Record<string, string | number> = {};
    for (const [name, value] of Object.entries(text.params ?? {})) {
        params[name] =
            typeof value !== 'object'
                ? value
                : 'decimal' in value
                  ? fixed(value.decimal, value.digits, decimalSeparator(locale))
                  : translateFieldText(translate, locale, value);
    }
    return text.count === undefined ? (translate.instant(text.key, params) as string) : pluralText(translate, locale, text.key, text.count, params);
}

/** One editable field of an element, as the inspector shows it. */
export interface FieldDef {
    key: string;
    /** The label's translation key. */
    label: string;
    type: FieldType;
    /** May be cleared (empty input = null). */
    nullable?: boolean;
    options?: readonly string[];
    /** The options are named by `enums.<enum>.<option>` (a select's options are values, not texts). */
    enum?: string;
    unit?: string;
    /** Shown in pairs on one row. */
    half?: boolean;
    /** Shown only for elements it applies to (e.g. by item kind). */
    when?: (element: Record<string, unknown>) => boolean;
    /** The label for the element at hand, in place of `label` (e.g. the compass side of a wall face). */
    labelOf?: (element: Record<string, unknown>) => FieldText;
    /** A note under the field about the element at hand (a derived value, a warning); null for none. */
    hint?: (element: Record<string, unknown>) => FieldText | null;
    /** Starts a collapsible group of fields with this title (a translation key) in the inspector (it runs to the next one). */
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
/** What a railing without a material looks like, by style (`railings.ts`): steel, a fence's timber or dark grey mesh. */
const RailingDefaultLooks: Readonly<Record<string, string>> = {Boards: 'fields.looks.timber', Mesh: 'fields.looks.darkGrey'};
const isFence = (railing: Record<string, unknown>): boolean => isFenceStyle(railing['style'] as RailingStyle);

const StairShapes = ['Straight', 'LShaped', 'UShaped', 'Spiral', 'Curved'] as const;
const hasLanding = (stair: Record<string, unknown>): boolean => stair['shape'] === 'LShaped' || stair['shape'] === 'UShaped';

/** Comfortable stairs: twice the riser plus the going, mm. */
const StepRuleMin = 600;
const StepRuleMax = 650;

/**
 * The riser the stair gets over its own rise or its floor's (`stairRise`, put beside the stair's fields by the panel)
 * and the step rule 2R + G, with a warning when it falls outside 600–650 mm.
 */
export function stairStepHint(stair: Record<string, unknown>): FieldText | null {
    const rise = typeof stair['rise'] === 'number' ? stair['rise'] : stair['stairRise'];
    const going = stair['going'];
    if (typeof rise !== 'number' || typeof going !== 'number' || rise <= 0) {
        return null;
    }
    const count = stairRisers(rise, typeof stair['risers'] === 'number' ? stair['risers'] : null);
    const riser = rise / count;
    const rule = 2 * riser + going;
    const step: FieldText = {key: 'fields.stairStep', count, params: {riser: riser.toFixed(0), rise, rule: rule.toFixed(0)}};
    return rule < StepRuleMin || rule > StepRuleMax
        ? {key: 'fields.stairStepUncomfortable', params: {step, min: StepRuleMin, max: StepRuleMax}}
        : step;
}

const RoofShapes = ['Gable', 'Hip', 'Shed', 'Flat'] as const;
const isPitched = (roof: Record<string, unknown>): boolean => roof['shape'] !== 'Flat';

const RidgeStyles = ['Box', 'Round', 'Angled'] as const;

const Gutters = ['None', 'HalfRound', 'Box'] as const;

const hasGutters = (roof: Record<string, unknown>): boolean => isPitched(roof) && (roof['gutters'] ?? 'None') !== 'None';

const RoofWindowTypes = ['CentrePivot', 'TopHung'] as const;

/** The height the roof's underside reaches at its ridge (a shed's top edge), above the floor's level. */
export function roofRidgeHint(roof: Record<string, unknown>): FieldText | null {
    const fields = ['width', 'depth', 'eaveHeight', 'pitch'] as const;
    if (!isPitched(roof) || fields.some((key) => typeof roof[key] !== 'number')) {
        return null;
    }
    const ridge = roofRidgeHeight(roof as unknown as RoofShapeFields);
    return {key: roof['shape'] === 'Shed' ? 'fields.ridgeHintShed' : 'fields.ridgeHint', params: {height: {decimal: ridge / 1000, digits: 2}}};
}

const ItemKinds = [
    'Sink', 'Hob', 'LedStrip', 'Box', 'Bathtub', 'Washbasin', 'Toilet', 'WashingMachine', 'Mirror', 'TowelRadiator', 'FlushPlate',
    'Fixture', 'Fence', 'Column', 'Beam', 'Chimney', 'Custom', 'Model',
] as const;

const RoomTypes = ['Living', 'Kitchen', 'Bedroom', 'Bathroom', 'Toilet', 'Hallway', 'Terrace', 'Garden', 'Path', 'Driveway', 'Garage'] as const;

/**
 * A wall face by the compass direction it faces, e.g. "North face" for the left face of a wall running east (at its
 * middle for a curved wall, whose faces are also told apart as convex or concave).
 */
export function wallFaceName(wall: Record<string, unknown>, side: 'Left' | 'Right'): FieldText {
    const coordinate = (key: string): number => (typeof wall[key] === 'number' ? wall[key] : 0);
    const dx = coordinate('endX') - coordinate('startX');
    const dy = coordinate('endY') - coordinate('startY');
    // The left normal of start → end is (−dy, dx); the right one points the other way.
    const [nx, ny] = side === 'Left' ? [-dy, dx] : [dy, -dx];
    if (nx === 0 && ny === 0) {
        return {key: side === 'Left' ? 'fields.faces.left' : 'fields.faces.right'};
    }
    const eastWest = nx > 0 ? 'east' : 'west';
    const northSouth = ny > 0 ? 'north' : 'south';
    const direction =
        Math.abs(nx) > Math.abs(ny)
            ? eastWest
            : Math.abs(ny) > Math.abs(nx)
              ? northSouth
              : `${northSouth}${eastWest.charAt(0).toUpperCase()}${eastWest.slice(1)}`;
    const face: FieldText = {key: `fields.faces.${direction}`};
    const sagitta = coordinate('sagitta');
    if (sagitta === 0) {
        return face;
    }
    return {key: (sagitta > 0) === (side === 'Left') ? 'fields.faces.convex' : 'fields.faces.concave', params: {face}};
}

/** The editable fields per element kind; keys are those of the scene JSON and the edit API. */
export const ElementFields: Record<ElementKind, readonly FieldDef[]> = {
    item: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {key: 'kind', label: 'fields.kind', type: 'select', options: ItemKinds, enum: 'itemKind'},
        {key: 'definitionId', label: 'fields.furniture', type: 'definition', when: isCustom},
        {key: 'modelId', label: 'fields.model', type: 'model', when: (item) => item['kind'] === 'Model'},
        {section: 'fields.sections.positionAndSize', key: 'x', label: 'fields.x', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'fields.y', type: 'int', unit: 'mm', half: true},
        {key: 'width', label: 'fields.width', type: 'int', unit: 'mm', half: true},
        {key: 'depth', label: 'fields.depth', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'fields.height', type: 'int', unit: 'mm', half: true},
        {
            key: 'z',
            label: 'fields.elevation',
            labelOf: (item) => ({
                key: item['onGround'] === true ? 'fields.aboveGround' : item['onRoof'] === true ? 'fields.aboveRoof' : 'fields.elevation',
            }),
            type: 'int',
            unit: 'mm',
            half: true,
        },
        {key: 'rotation', label: 'fields.rotation', type: 'int', unit: '°'},
        {key: 'onGround', label: 'fields.itemOnGround', type: 'bool', when: (item) => item['onRoof'] !== true},
        {
            key: 'onRoof',
            label: 'fields.onRoof',
            type: 'bool',
            when: (item) => item['onGround'] !== true && item['kind'] !== 'Chimney',
        },
        {section: 'fields.sections.roomAndGroup', key: 'roomId', label: 'fields.room', type: 'room', nullable: true},
        {key: 'groupId', label: 'fields.group', type: 'group', nullable: true},
        {section: 'fields.sections.lookAndOptions', key: 'materialId', label: 'fields.material', type: 'material', nullable: true},
        {key: 'params', label: 'fields.options', type: 'custom', when: isCustom},
        {key: 'params', label: 'fields.paramsJson', type: 'json', when: (element) => !isCustom(element)},
    ],
    wall: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {key: 'type', label: 'fields.type', type: 'select', options: ['Exterior', 'Interior', 'Shaft'], enum: 'wallType'},
        {section: 'fields.sections.position', folded: true, key: 'startX', label: 'fields.startX', type: 'int', unit: 'mm', half: true},
        {key: 'startY', label: 'fields.startY', type: 'int', unit: 'mm', half: true},
        {key: 'endX', label: 'fields.endX', type: 'int', unit: 'mm', half: true},
        {key: 'endY', label: 'fields.endY', type: 'int', unit: 'mm', half: true},
        {key: 'sagitta', label: 'fields.sagitta', type: 'int', unit: 'mm'},
        {section: 'fields.sections.size', key: 'thickness', label: 'fields.thickness', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'fields.height', type: 'int', unit: 'mm', half: true},
        {key: 'upToRoof', label: 'fields.upToRoof', type: 'bool'},
        {key: 'heightEnd', label: 'fields.heightEnd', type: 'int', unit: 'mm', nullable: true},
        {section: 'fields.sections.groundAndBase', folded: true, key: 'onGround', label: 'fields.wallOnGround', type: 'bool'},
        {key: 'baseStart', label: 'fields.baseStart', type: 'int', unit: 'mm', half: true, when: (wall) => wall['onGround'] !== true},
        {key: 'baseEnd', label: 'fields.baseEnd', type: 'int', unit: 'mm', half: true, when: (wall) => wall['onGround'] !== true},
        {section: 'fields.sections.finishes', key: 'materialId', label: 'fields.material', type: 'material', nullable: true},
        {
            key: 'leftFinishes',
            label: 'fields.leftFinishes',
            labelOf: (wall) => ({key: 'fields.faceFinishes', params: {face: wallFaceName(wall, 'Left')}}),
            type: 'finishes',
            nullable: true,
        },
        {
            key: 'rightFinishes',
            label: 'fields.rightFinishes',
            labelOf: (wall) => ({key: 'fields.faceFinishes', params: {face: wallFaceName(wall, 'Right')}}),
            type: 'finishes',
            nullable: true,
        },
    ],
    opening: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {key: 'type', label: 'fields.type', type: 'select', options: OpeningTypes, enum: 'openingType'},
        {section: 'fields.sections.sizeAndPosition', key: 'offset', label: 'fields.offset', type: 'int', unit: 'mm'},
        {key: 'width', label: 'fields.width', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'fields.height', type: 'int', unit: 'mm', half: true},
        {key: 'sillHeight', label: 'fields.sill', type: 'int', unit: 'mm', half: true},
        {
            key: 'leafWidth',
            label: 'fields.leafWidth',
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
            section: 'fields.sections.leafAndFrame',
            key: 'garageDoorStyle',
            label: 'fields.garageDoorStyle',
            type: 'select',
            options: ['Sectional', 'UpAndOver', 'SideHinged', 'Roller'],
            enum: 'garageDoorStyle',
            half: true,
            when: (element) => element['type'] === 'GarageDoor',
        },
        {
            key: 'fixedPanes',
            label: 'fields.fixedPanes',
            type: 'int',
            half: true,
            when: (element) => element['type'] === 'SlidingDoor',
        },
        {
            key: 'hinge',
            label: 'fields.hinge',
            type: 'select',
            options: ['Start', 'End'],
            enum: 'hinge',
            nullable: true,
            half: true,
            when: (element) => element['type'] !== 'Passage' && element['type'] !== 'Niche',
        },
        {
            key: 'side',
            label: 'fields.side',
            type: 'select',
            options: ['Left', 'Right'],
            enum: 'side',
            nullable: true,
            half: true,
            when: (element) => element['type'] !== 'Passage' && element['type'] !== 'PocketDoor',
        },
        {
            key: 'frameStyle',
            label: 'fields.frameStyle',
            type: 'select',
            options: ['Lining', 'Architrave', 'Steel', 'Flush', 'None'],
            enum: 'frameStyle',
            half: true,
            when: (element) => element['type'] !== 'Window' && element['type'] !== 'Niche' && element['type'] !== 'GarageDoor',
        },
        {
            key: 'handle',
            label: 'fields.handle',
            type: 'select',
            options: ['Lever', 'Knob', 'Pull', 'None'],
            enum: 'handle',
            nullable: true,
            half: true,
            when: hasLeaf,
        },
        {key: 'mullions', label: 'fields.mullions', type: 'int', half: true, when: (element) => element['type'] === 'Window'},
        {key: 'transoms', label: 'fields.transoms', type: 'int', half: true, when: (element) => element['type'] === 'Window'},
        {
            key: 'sillBoard',
            label: 'fields.sillBoard',
            type: 'int',
            unit: 'mm',
            nullable: true,
            when: (element) => element['type'] === 'Window',
        },
        {
            key: 'leafStyle',
            label: 'fields.leafStyle',
            type: 'select',
            options: ['Flush', 'Panelled', 'Glazed', 'Boarded'],
            enum: 'leafStyle',
            half: true,
            when: (element) => hasLeaf(element) && !glazedFrame(element),
        },
        {
            key: 'leafPanels',
            label: 'fields.leafPanels',
            type: 'int',
            half: true,
            when: (element) => hasLeaf(element) && element['leafStyle'] !== 'Flush' && element['leafStyle'] != null && !glazedFrame(element),
        },
        {
            key: 'boardDirection',
            label: 'fields.boardDirection',
            type: 'select',
            options: ['Vertical', 'Horizontal'],
            enum: 'boardDirection',
            half: true,
            when: (element) => hasLeaf(element) && element['leafStyle'] === 'Boarded' && !glazedFrame(element),
        },
        {
            section: 'fields.sections.materials',
            key: 'frameMaterialId',
            label: 'fields.frameMaterial',
            type: 'material',
            nullable: true,
            half: true,
            when: framed,
        },
        {
            key: 'outsideFrameMaterialId',
            label: 'fields.outsideFrameMaterial',
            type: 'material',
            nullable: true,
            half: true,
            when: glazedFrame,
        },
        {
            key: 'leafMaterialId',
            label: 'fields.leafMaterial',
            type: 'material',
            nullable: true,
            half: true,
            when: (element) => hasLeaf(element) && !glazedFrame(element),
        },
        {
            section: 'fields.sections.details', folded: true,
            key: 'inset',
            label: 'fields.inset',
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
            label: 'fields.panelHeight',
            type: 'int',
            unit: 'mm',
            nullable: true,
            when: (element) => element['type'] === 'Window',
        },
        {
            key: 'shutters',
            label: 'fields.shutters',
            type: 'select',
            options: ['Louvred', 'Solid'],
            enum: 'shutters',
            nullable: true,
            half: true,
            when: (element) => element['type'] === 'Window',
        },
        {
            key: 'shutterMaterialId',
            label: 'fields.shutterMaterial',
            type: 'material',
            nullable: true,
            half: true,
            when: (element) => element['type'] === 'Window' && element['shutters'] != null,
        },
        {
            key: 'shuttersClosed',
            label: 'fields.shuttersClosed',
            type: 'bool',
            when: (element) => element['type'] === 'Window' && element['shutters'] != null,
        },
        {key: 'depth', label: 'fields.nicheDepth', type: 'int', unit: 'mm', when: (element) => element['type'] === 'Niche'},
        {
            section: 'fields.sections.walkthrough',
            folded: true,
            key: 'entrance',
            label: 'fields.entrance',
            type: 'bool',
            when: (element) => element['type'] !== 'Niche',
        },
        {key: 'walkable', label: 'fields.walkable', type: 'bool', when: (element) => element['type'] === 'Window'},
        {key: 'revealLining', label: 'fields.revealLining', type: 'bool', when: (element) => element['type'] !== 'Niche'},
    ],
    plot: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {section: 'fields.sections.ground', key: 'groundMaterialId', label: 'fields.groundMaterial', type: 'material', nullable: true},
        {key: 'groundLevel', label: 'fields.groundLevel', type: 'int', unit: 'mm'},
        {key: 'terrain', label: 'fields.terrain', type: 'spots'},
        {section: 'fields.sections.boundary', folded: true, key: 'polygon', label: 'fields.boundaryCorners', type: 'points'},
    ],
    stair: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {key: 'shape', label: 'fields.shape', type: 'select', options: StairShapes, enum: 'stairShape'},
        {section: 'fields.sections.position', key: 'x', label: 'fields.firstRiserX', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'fields.firstRiserY', type: 'int', unit: 'mm', half: true},
        {key: 'rotation', label: 'fields.stairRotation', type: 'int', unit: '°'},
        {
            section: 'fields.sections.sizeAndSteps',
            key: 'width',
            label: 'fields.width',
            labelOf: (stair) => ({key: stair['shape'] === 'Spiral' ? 'fields.diameter' : 'fields.flightWidth'}),
            type: 'int',
            unit: 'mm',
            half: true,
        },
        {key: 'going', label: 'fields.going', type: 'int', unit: 'mm', half: true},
        {key: 'rise', label: 'fields.ownRise', type: 'int', unit: 'mm', nullable: true, half: true},
        {key: 'baseZ', label: 'fields.baseZ', type: 'int', unit: 'mm', half: true},
        {key: 'risers', label: 'fields.risers', type: 'int', nullable: true, hint: stairStepHint},
        {key: 'firstFlight', label: 'fields.firstFlight', type: 'int', nullable: true, half: true, when: hasLanding},
        {
            key: 'turn',
            label: 'fields.turn',
            type: 'select',
            options: ['Left', 'Right'],
            enum: 'turn',
            half: true,
            when: (stair) => hasLanding(stair) || stair['shape'] === 'Spiral' || stair['shape'] === 'Curved',
        },
        {
            key: 'innerRadius',
            label: 'fields.innerRadius',
            type: 'int',
            unit: 'mm',
            half: true,
            when: (stair) => stair['shape'] === 'Curved',
        },
        {key: 'gap', label: 'fields.flightGap', type: 'int', unit: 'mm', half: true, when: (stair) => stair['shape'] === 'UShaped'},
        {
            section: 'fields.sections.construction',
            key: 'construction',
            label: 'fields.construction',
            type: 'select',
            options: ['Solid', 'Stringers', 'Open'],
            enum: 'stairConstruction',
            half: true,
            when: (stair) => stair['shape'] !== 'Spiral',
        },
        {
            section: 'fields.sections.railing',
            key: 'railing',
            label: 'fields.railing',
            type: 'select',
            options: ['None', 'Left', 'Right', 'Both'],
            enum: 'sides',
            half: true,
        },
        {
            key: 'railingStyle',
            label: 'fields.railingStyle',
            type: 'select',
            options: RailingStyles,
            enum: 'railingStyle',
            nullable: true,
            half: true,
            when: (stair) => stair['railing'] !== 'None',
        },
        {key: 'wallHandrail', label: 'fields.wallHandrail', type: 'select', options: ['None', 'Left', 'Right', 'Both'], enum: 'sides', half: true},
        {key: 'railingMaterialId', label: 'fields.railingMaterial', type: 'material', nullable: true, half: true},
        {section: 'fields.sections.materials', key: 'treadMaterialId', label: 'fields.treadMaterial', type: 'material', nullable: true, half: true},
        {key: 'structureMaterialId', label: 'fields.structureMaterial', type: 'material', nullable: true, half: true},
        {
            key: 'voidLiningMaterialId',
            label: 'fields.voidLining',
            type: 'material',
            nullable: true,
            when: (stair) => stair['rise'] == null,
        },
    ],
    void: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {section: 'fields.sections.lining', key: 'liningMaterialId', label: 'fields.liningMaterial', type: 'material', nullable: true, half: true},
        {key: 'liningDepth', label: 'fields.liningDepth', type: 'int', unit: 'mm', nullable: true, half: true},
        {section: 'fields.sections.outline', folded: true, key: 'polygon', label: 'fields.outlineCorners', type: 'points'},
    ],
    railing: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {key: 'style', label: 'fields.infill', type: 'select', options: RailingAndFenceStyles, enum: 'railingStyle', half: true},
        {
            key: 'handrail',
            label: 'fields.handrail',
            type: 'select',
            options: ['Round', 'Square', 'Flat', 'None'],
            enum: 'handrail',
            half: true,
            when: (railing) => !isFence(railing),
        },
        {
            key: 'follow',
            label: 'fields.follow',
            type: 'select',
            options: ['Level', 'Rake', 'Step'],
            enum: 'railingFollow',
            half: true,
            when: isFence,
        },
        {section: 'fields.sections.size', key: 'height', label: 'fields.height', type: 'int', unit: 'mm', half: true},
        {key: 'baseZ', label: 'fields.railingBase', type: 'int', unit: 'mm', half: true},
        {
            key: 'spacing',
            label: 'fields.spacing',
            labelOf: (railing) => ({key: railing['style'] === 'Balusters' ? 'fields.balusterGap' : 'fields.infillPitch'}),
            type: 'int',
            unit: 'mm',
            nullable: true,
            half: true,
            when: (railing) => railing['style'] !== 'Glass' && railing['style'] !== 'Panel' && !isFence(railing),
        },
        {key: 'postSpacing', label: 'fields.postSpacing', type: 'int', unit: 'mm', half: true},
        {
            section: 'fields.sections.materials',
            key: 'materialId',
            label: 'fields.material',
            labelOf: (railing) => ({
                key: 'fields.materialOrLook',
                params: {look: {key: RailingDefaultLooks[railing['style'] as string] ?? 'fields.looks.steel'}},
            }),
            type: 'material',
            nullable: true,
            half: true,
        },
        {
            key: 'infillMaterialId',
            label: 'fields.infillMaterial',
            type: 'material',
            nullable: true,
            half: true,
            when: (railing) => railing['style'] === 'Glass' || railing['style'] === 'Panel',
        },
        {section: 'fields.sections.points', folded: true, key: 'points', label: 'fields.points', type: 'points', minPoints: 2, openRun: true},
    ],
    section: [
        {section: 'fields.sections.general', key: 'name', label: 'fields.sectionName', type: 'text'},
        {section: 'fields.sections.line', key: 'startX', label: 'fields.lineStartX', type: 'int', unit: 'mm', half: true},
        {key: 'startY', label: 'fields.lineStartY', type: 'int', unit: 'mm', half: true},
        {key: 'endX', label: 'fields.lineEndX', type: 'int', unit: 'mm', half: true},
        {key: 'endY', label: 'fields.lineEndY', type: 'int', unit: 'mm', half: true},
        {key: 'depth', label: 'fields.sectionDepth', type: 'int', unit: 'mm', nullable: true},
    ],
    roof: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {key: 'shape', label: 'fields.shape', type: 'select', options: RoofShapes, enum: 'roofShape'},
        {section: 'fields.sections.positionAndSize', key: 'x', label: 'fields.centreX', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'fields.centreY', type: 'int', unit: 'mm', half: true},
        {key: 'rotation', label: 'fields.ridgeDirection', type: 'int', unit: '°'},
        {key: 'width', label: 'fields.widthAlongRidge', type: 'int', unit: 'mm', half: true},
        {key: 'depth', label: 'fields.depthAcrossRidge', type: 'int', unit: 'mm', half: true},
        {section: 'fields.sections.slope', key: 'eaveHeight', label: 'fields.eaveHeight', type: 'int', unit: 'mm', half: true},
        {key: 'pitch', label: 'fields.pitch', type: 'int', unit: '°', half: true, when: isPitched, hint: roofRidgeHint},
        {key: 'overhang', label: 'fields.overhang', type: 'int', unit: 'mm', half: true},
        {
            key: 'vergeOverhang',
            label: 'fields.vergeOverhang',
            type: 'int',
            unit: 'mm',
            half: true,
            when: (roof) => hasVerges(roof as Pick<RoofShapeFields, 'shape'>),
        },
        {
            key: 'startSkew',
            label: 'fields.startVergeSkew',
            labelOf: (roof) => ({key: roof['shape'] === 'Flat' ? 'fields.startEdgeSkew' : 'fields.startVergeSkew'}),
            type: 'int',
            unit: '°',
            half: true,
            when: (roof) => canSkew(roof as Pick<RoofShapeFields, 'shape'>),
        },
        {
            key: 'endSkew',
            label: 'fields.endVergeSkew',
            labelOf: (roof) => ({key: roof['shape'] === 'Flat' ? 'fields.endEdgeSkew' : 'fields.endVergeSkew'}),
            type: 'int',
            unit: '°',
            half: true,
            when: (roof) => canSkew(roof as Pick<RoofShapeFields, 'shape'>),
        },
        {key: 'thickness', label: 'fields.roofThickness', type: 'int', unit: 'mm', half: true},
        {key: 'parapet', label: 'fields.parapet', type: 'int', unit: 'mm', nullable: true, half: true, when: (roof) => !isPitched(roof)},
        {section: 'fields.sections.covering', key: 'coveringMaterialId', label: 'fields.covering', type: 'material', nullable: true, half: true},
        {key: 'soffitMaterialId', label: 'fields.soffit', type: 'material', nullable: true, half: true},
        {
            key: 'ridgeStyle',
            label: 'fields.ridgeStyle',
            type: 'select',
            options: RidgeStyles,
            enum: 'ridgeStyle',
            when: isPitched,
        },
        {key: 'snowGuards', label: 'fields.snowGuards', type: 'int', half: true, when: isPitched},
        {
            section: 'fields.sections.rainwater', folded: true,
            key: 'gutters',
            label: 'fields.gutters',
            type: 'select',
            options: Gutters,
            enum: 'gutters',
            half: true,
            when: isPitched,
        },
        {key: 'gutterMaterialId', label: 'fields.gutterMaterial', type: 'material', nullable: true, when: hasGutters},
        {
            key: 'downpipes',
            label: 'fields.downpipes',
            type: 'json',
            nullable: true,
            when: hasGutters,
        },
    ],
    roofWindow: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {key: 'type', label: 'fields.roofWindowType', type: 'select', options: RoofWindowTypes, enum: 'roofWindowType'},
        {section: 'fields.sections.positionAndSize', key: 'along', label: 'fields.alongRidge', type: 'int', unit: 'mm', half: true},
        {key: 'across', label: 'fields.acrossRidge', type: 'int', unit: 'mm', half: true},
        {key: 'width', label: 'fields.width', type: 'int', unit: 'mm', half: true},
        {key: 'length', label: 'fields.lengthUpSlope', type: 'int', unit: 'mm', half: true},
        {section: 'fields.sections.look', key: 'frameMaterialId', label: 'fields.frame', type: 'material', nullable: true},
    ],
    room: [
        {section: 'fields.sections.general', key: 'name', label: 'common.name', type: 'text'},
        {key: 'code', label: 'fields.code', type: 'text', half: true},
        {key: 'type', label: 'fields.type', type: 'select', options: RoomTypes, enum: 'roomType', half: true},
        {section: 'fields.sections.heightAndLevel', key: 'floorElevation', label: 'fields.floorElevation', type: 'int', unit: 'mm'},
        {key: 'ceilingHeight', label: 'fields.ceilingHeight', type: 'int', unit: 'mm', nullable: true},
        {section: 'fields.sections.surfaces', key: 'floorMaterialId', label: 'fields.floorMaterial', type: 'material', nullable: true},
        {key: 'ceilingMaterialId', label: 'fields.ceilingMaterial', type: 'material', nullable: true, when: (room) => room['ceilingHeight'] != null},
        {
            section: 'fields.sections.vaultedCeiling', folded: true,
            key: 'vaultSpacing',
            label: 'fields.vaultSpacing',
            type: 'int',
            unit: 'mm',
            nullable: true,
            when: (room) => room['ceilingHeight'] != null,
        },
        {key: 'vaultAngle', label: 'fields.vaultAngle', type: 'int', unit: '°', half: true, when: (room) => room['vaultSpacing'] != null},
        {key: 'vaultRise', label: 'fields.vaultRise', type: 'int', unit: 'mm', half: true, when: (room) => room['vaultSpacing'] != null},
        {key: 'beamWidth', label: 'fields.beamWidth', type: 'int', unit: 'mm', half: true, when: (room) => room['vaultSpacing'] != null},
        {key: 'beamDrop', label: 'fields.beamDrop', type: 'int', unit: 'mm', half: true, when: (room) => room['vaultSpacing'] != null},
        {key: 'beamMaterialId', label: 'fields.beamMaterial', type: 'material', nullable: true, when: (room) => room['vaultSpacing'] != null},
        {
            section: 'fields.sections.skirtingAndSoffit',
            key: 'skirtingHeight',
            label: 'fields.skirting',
            type: 'int',
            unit: 'mm',
            nullable: true,
            half: true,
        },
        {
            key: 'skirtingMaterialId',
            label: 'fields.skirtingMaterial',
            type: 'material',
            nullable: true,
            half: true,
            when: (room) => room['skirtingHeight'] != null,
        },
        {key: 'soffitMaterialId', label: 'fields.slabSoffit', type: 'material', nullable: true},
        {section: 'fields.sections.outline', folded: true, key: 'polygon', label: 'fields.outlineCorners', type: 'points'},
        {section: 'fields.sections.wallFinishes', key: 'wallFinishes', label: 'fields.bands', type: 'finishes'},
        {
            section: 'fields.sections.tiling',
            folded: true,
            key: 'tileOriginX',
            label: 'fields.tileOriginX',
            type: 'int',
            unit: 'mm',
            nullable: true,
            half: true,
        },
        {key: 'tileOriginY', label: 'fields.tileOriginY', type: 'int', unit: 'mm', nullable: true, half: true},
        {key: 'floorAngle', label: 'fields.floorAngle', type: 'int', unit: '°', half: true},
    ],
    group: [{key: 'name', label: 'common.name', type: 'text'}],
    variantSet: [{key: 'name', label: 'common.name', type: 'text'}],
};

export type ParsedValue = {ok: true; value: unknown} | {ok: false; error: FieldText};

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

/**
 * Turns what the user typed into the value the API expects, or explains why it is not valid; the error names the field
 * by `label` (the label shown for the element at hand).
 */
export function parseFieldValue(def: FieldDef, raw: string, label: FieldText = {key: def.label}): ParsedValue {
    const text = raw.trim();
    if (text === '') {
        if (def.nullable === true) {
            return {ok: true, value: null};
        }
        if (def.type === 'json') {
            return {ok: false, error: {key: 'fields.errors.enterJson'}};
        }
        return {ok: false, error: {key: 'fields.errors.required', params: {label}}};
    }

    switch (def.type) {
        case 'bool':
            return {ok: true, value: text === 'true'};
        case 'int': {
            if (!/^-?\d+$/.test(text)) {
                return {ok: false, error: {key: 'fields.errors.wholeNumber'}};
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
                return {ok: false, error: {key: 'fields.errors.invalidJson'}};
            }
        default:
            return {ok: true, value: text};
    }
}

/** Whether a parsed value differs from the current one (JSON values compared by content). */
export function isChanged(current: unknown, next: unknown): boolean {
    return JSON.stringify(current ?? null) !== JSON.stringify(next ?? null);
}
