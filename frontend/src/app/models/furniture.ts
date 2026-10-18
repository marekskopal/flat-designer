/**
 * Furniture definitions (format 1): a custom piece of furniture as parameters and parts whose position and size are
 * expressions (see docs/furniture-definitions-plan.md and `viewer/engine/custom`). Lengths are mm; the frame has its
 * origin at the item's left-bottom-back corner: x along the width, y up, z towards the front.
 */

/** A number, or an expression of `W`, `D`, `H`, the parameters, the derived values and a repeat index. */
export type DefinitionExpression = string | number;

export type DefinitionParameter =
    | {key: string; label?: string; type: 'int'; default: number; min?: number; max?: number; unit?: string}
    | {key: string; label?: string; type: 'bool'; default: boolean}
    | {key: string; label?: string; type: 'select'; default: string; options: string[]};

export interface MaterialSlot {
    key: string;
    label?: string;
    /** Code of the material a new item gets. */
    default?: string;
}

export interface DefinitionPart {
    type: string;
    name?: string;
    /** The part is left out when this comes out false. */
    when?: string | boolean;
    /** `count` copies, the copy's index (from 0) in the variable `as` (default `i`). */
    repeat?: {count: DefinitionExpression; as?: string};
    /** Key of one of the material slots. */
    material?: string;
    x: DefinitionExpression;
    y: DefinitionExpression;
    z: DefinitionExpression;
    w: DefinitionExpression;
    h: DefinitionExpression;
    d: DefinitionExpression;
    /** Options of the part type (grain, edges, handle, …). */
    [prop: string]: unknown;
}

export interface FurnitureDefinitionBody {
    format: 1;
    /** Size of a new item (mm). */
    size?: {width: number; depth: number; height: number};
    parameters?: DefinitionParameter[];
    /** Values computed in order from the size, the parameters and the values before them. */
    derived?: Record<string, DefinitionExpression>;
    materialSlots?: MaterialSlot[];
    parts: DefinitionPart[];
}

export type FurnitureScope = 'global' | 'user' | 'project';

/** A furniture definition as the API serves it (`FurnitureDefinitionDto`). */
export interface FurnitureDefinition {
    id: number;
    name: string;
    description: string;
    scope: FurnitureScope;
    /** The library it is in (scope `user`). */
    userId: number | null;
    userName: string | null;
    projectId: number | null;
    definition: FurnitureDefinitionBody;
    /** Whether the signed-in user may change it (global ones: admins). */
    canEdit: boolean;
    /** Items in all projects drawn from it; only when one definition is read. */
    itemCount: number | null;
    createdByName: string | null;
    createdAt: string;
    updatedAt: string;
}

export interface FurnitureDefinitionInput {
    name: string;
    description: string;
    definition: FurnitureDefinitionBody;
    /** Create only (default `user`: my library). */
    scope?: FurnitureScope;
    projectId?: number;
}

export interface FurnitureValidationError {
    code: string;
    /** Where in the definition, e.g. `parts[2].y`. */
    path: string;
    message: string;
}

/** The server's check of a definition: its parts at the default size, or the first error. */
export interface FurnitureValidation {
    valid: boolean;
    error: FurnitureValidationError | null;
}

export const ScopeLabels: Record<FurnitureScope, string> = {global: 'Global', user: 'My library', project: 'This project'};
