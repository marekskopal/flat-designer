/**
 * Furniture definitions (format 1): a custom piece of furniture as parameters and parts whose position and size are
 * expressions (see docs/furniture-definitions-plan.md and `viewer/engine/custom`). Lengths are mm; the frame has its
 * origin at the item's left-bottom-back corner: x along the width, y up, z towards the front.
 */

/** A number, or an expression of `W`, `D`, `H`, the parameters, the derived values and a repeat index. */
export type DefinitionExpression = string | number;

/** A field of a list parameter's items: like a parameter, but never a list; an `optional` one may be null. */
export type DefinitionField =
    | {key: string; label?: string; type: 'int'; default?: number | null; min?: number; max?: number; unit?: string; optional?: boolean}
    | {key: string; label?: string; type: 'bool'; default?: boolean | null; optional?: boolean}
    | {key: string; label?: string; type: 'select'; default?: string | null; options: string[]; optional?: boolean};

export type DefinitionParameter =
    | {key: string; label?: string; type: 'int'; default: number; min?: number; max?: number; unit?: string}
    | {key: string; label?: string; type: 'bool'; default: boolean}
    | {key: string; label?: string; type: 'select'; default: string; options: string[]}
    /** The id of an image file of the project (see `ProjectFile`); no default. */
    | {key: string; label?: string; type: 'image'; default?: null}
    | {
          key: string;
          label?: string;
          type: 'list';
          fields: DefinitionField[];
          default: Record<string, number | boolean | string | null>[];
          minItems?: number;
          maxItems?: number;
      };

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
    /**
     * `count` copies with the index (from 0) in the variable `as` (default `i`), or one copy per item of the list `over`
     * with the item in `as` (default `item`) and its index in `<as>_index`.
     */
    repeat?: {count: DefinitionExpression; as?: string} | {over: string; as?: string};
    /** Key of one of the material slots. */
    material?: string;
    x: DefinitionExpression;
    y: DefinitionExpression;
    z: DefinitionExpression;
    w: DefinitionExpression;
    h: DefinitionExpression;
    d: DefinitionExpression;
    /** The parts of an `assembly`, placed in its box (they see its size as AW, AH, AD). */
    parts?: DefinitionPart[];
    /** Options of the part type (grain, edges, handle, …), each a value or `{expr: "…"}` evaluated per copy. */
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
