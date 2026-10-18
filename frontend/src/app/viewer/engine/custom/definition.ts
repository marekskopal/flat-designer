import {
    Expression,
    ExpressionError,
    ExpressionErrorCode,
    literalExpression,
    parseExpression,
    roundHalfAway,
    typeName,
    Value,
    ValueRecord,
    Variables,
} from './expression';

/**
 * Turns a furniture definition (`FurnitureDefinitionBody`, format 1) and an item's size and parameter values into its
 * parts. Twin: backend/src/Service/Furniture/DefinitionEvaluator.php (with DefinitionParameter, ListValues, PartEntry,
 * PartRepeat, PartOptions), both checked against backend/tests/fixtures/furniture-definitions.json.
 *
 * Variables: `W`, `D`, `H` (the item size), the parameters (item value or default; a list parameter gives a list of
 * records of its fields), then `derived` in order, and inside a repeated part its copy: `repeat: {count, as?}` gives the
 * index as `as` (default `i`), `repeat: {over, as?}` the list item as `as` (default `item`) and its index as
 * `<as>_index` — evaluated per scope, so a nested part repeats by its parent's item. Each copy is kept when `when` is
 * true; `x y z w h d` are numbers or expressions, rounded half away from zero to mm, and `w h d` must come out
 * positive. An `assembly` makes no part itself: its `parts` are placed in its box and see its size as `AW`, `AH`, `AD`.
 * An option written `{"expr": "…"}` is evaluated per copy. Only what the evaluation reads is checked here. The input is
 * untrusted JSON; like PHP's decoder, `{}` and `[]` are both accepted as an empty list or object.
 */

export const DefinitionFormat = 1;
export const MaxPartEntries = 200;
export const MaxParts = 500;
export const MaxRepeat = 100;
/** Levels of parts: a top-level entry is at 1, the parts of its assembly at 2, … */
export const MaxDepth = 4;
/** Lengths beyond this (mm) are a mistake (and would overflow the PHP twin's ints). */
export const MaxLength = 1000000000;

export type DefinitionErrorCode = 'invalid' | 'invalidValue' | 'limit' | 'nonPositiveSize';

/** A definition that cannot be evaluated; `path` points into the definition (`parts[2].y`) or the values (`values.t`). */
export class DefinitionError extends Error {
    public constructor(
        public readonly code: DefinitionErrorCode | ExpressionErrorCode,
        public readonly path: string,
        message: string,
    ) {
        super(`${path}: ${message}`);
        this.name = 'DefinitionError';
    }
}

/**
 * One part of an evaluated definition, in whole mm with the origin at the left-bottom-back corner of the item (x along
 * the width, y up, z towards the front; parts of assemblies are placed in the item's frame too). `index` is the repeat
 * index (null without `repeat`); `props` are the part's other keys, `{"expr": …}` ones evaluated (type-specific:
 * grain, edges, handle, …).
 */
export interface ResolvedPart {
    name: string;
    type: string;
    index: number | null;
    x: number;
    y: number;
    z: number;
    w: number;
    h: number;
    d: number;
    material: string | null;
    props: Record<string, unknown>;
}

export interface ItemSize {
    width: number;
    depth: number;
    height: number;
}

export function evaluateDefinition(definition: unknown, size: ItemSize, values: unknown = {}): ResolvedPart[] {
    const body = isRecord(definition) ? definition : {};
    if (body['format'] !== DefinitionFormat) {
        throw invalid('format', `Unsupported definition format; expected ${DefinitionFormat}.`);
    }
    let variables: Record<string, Value> = {W: size.width, D: size.depth, H: size.height};
    variables = {...variables, ...readValues(readParameters(body['parameters'] ?? []), values ?? {})};
    variables = readDerived(body['derived'] ?? {}, variables);
    const slots = readMaterialSlots(body['materialSlots'] ?? []);
    return readParts(body['parts'], variables, slots);
}

type ParameterType = 'int' | 'bool' | 'select' | 'list';

export interface Parameter {
    key: string;
    type: ParameterType;
    default: Value;
    min: number | null;
    max: number | null;
    options: string[];
    /** The fields of a list parameter. */
    fields: Parameter[];
    /** A field that may be null (missing: its default, or null). */
    optional: boolean;
    minItems: number | null;
    maxItems: number | null;
}

const NameRule = 'Must be a unique name (letters, digits, _), not W, D, H, AW, AH, AD, true, false or null.';

/** The definition's parameters by key. */
export function readParameters(definitions: unknown): Map<string, Parameter> {
    if (!isList(definitions)) {
        throw invalid('parameters', 'Must be a list.');
    }
    const parameters = new Map<string, Parameter>();
    asArray(definitions).forEach((definition, index) => {
        const parameter = readParameter(definition, `parameters[${index}]`, parameters, false);
        parameters.set(parameter.key, parameter);
    });
    return parameters;
}

/** A single value (not a list) as an expression variable, or null when it does not fit. */
export function acceptValue(parameter: Parameter, value: unknown): Value | null {
    switch (parameter.type) {
        case 'int':
            return inRange(value, parameter) ? value : null;
        case 'bool':
            return typeof value === 'boolean' ? value : null;
        case 'select':
            return typeof value === 'string' && parameter.options.includes(value) ? value : null;
        case 'list':
            return null;
    }
}

export function describeParameter(parameter: Parameter): string {
    switch (parameter.type) {
        case 'int': {
            const from = parameter.min === null ? '' : ` from ${parameter.min}`;
            const upTo = parameter.max === null ? '' : ` up to ${parameter.max}`;
            return `a whole number${from}${upTo}`;
        }
        case 'bool':
            return 'true or false';
        case 'select':
            return `one of "${parameter.options.join('", "')}"`;
        case 'list': {
            const counted = parameter.minItems === null && parameter.maxItems === null;
            const range = counted ? '' : `${parameter.minItems ?? 0} to ${parameter.maxItems ?? 'any'} `;
            return `a list of ${range}{${parameter.fields.map((f) => f.key).join(', ')}}`;
        }
    }
}

/** The value as an expression variable; throws `code` at `path` (or the item / field in it) when it does not fit. */
export function parameterValue(parameter: Parameter, value: unknown, path: string, code: DefinitionErrorCode): Value {
    if (parameter.type === 'list') {
        return listValue(parameter, value, path, code);
    }
    if (value === null && parameter.optional) {
        return null;
    }
    const accepted = acceptValue(parameter, value);
    if (accepted === null) {
        throw new DefinitionError(code, path, `Must be ${describeParameter(parameter)}.`);
    }
    return accepted;
}

function readParameter(definition: unknown, path: string, taken: Map<string, Parameter>, field: boolean): Parameter {
    if (typeof definition !== 'object' || definition === null) {
        throw invalid(path, 'Must be an object.');
    }
    const fields = definition as Record<string, unknown>;
    const key = freeName(fields['key'], (name) => taken.has(name));
    if (key === null) {
        throw invalid(`${path}.key`, NameRule);
    }
    const type = fields['type'];
    if (type !== 'int' && type !== 'bool' && type !== 'select' && (field || type !== 'list')) {
        throw invalid(
            `${path}.type`,
            field ? 'Must be "int", "bool" or "select" (a field cannot be a list).' : 'Must be "int", "bool", "select" or "list".',
        );
    }
    const parameter: Parameter = {
        key,
        type,
        default: null,
        min: optionalInt(fields['min'], `${path}.min`),
        max: optionalInt(fields['max'], `${path}.max`),
        options: type === 'select' ? readOptions(fields['options'], `${path}.options`) : [],
        fields: type === 'list' ? readFields(fields['fields'], `${path}.fields`) : [],
        optional: field && fields['optional'] === true,
        minItems: optionalInt(fields['minItems'], `${path}.minItems`),
        maxItems: optionalInt(fields['maxItems'], `${path}.maxItems`),
    };
    const hasDefault = Object.hasOwn(fields, 'default');
    return {
        ...parameter,
        default: hasDefault || !parameter.optional ? parameterValue(parameter, fields['default'] ?? null, `${path}.default`, 'invalid') : null,
    };
}

function readFields(definitions: unknown, path: string): Parameter[] {
    if (!Array.isArray(definitions) || definitions.length === 0) {
        throw invalid(path, 'Must be a non-empty list of fields.');
    }
    const fields = new Map<string, Parameter>();
    definitions.forEach((definition, index) => {
        const field = readParameter(definition, `${path}[${index}]`, fields, true);
        fields.set(field.key, field);
    });
    return [...fields.values()];
}

function listValue(parameter: Parameter, value: unknown, path: string, code: DefinitionErrorCode): ValueRecord[] {
    const items = isList(value) ? asArray(value) : null;
    const count = items === null ? -1 : items.length;
    if (items === null || count < (parameter.minItems ?? 0) || (parameter.maxItems !== null && count > parameter.maxItems)) {
        throw new DefinitionError(code, path, `Must be ${describeParameter(parameter)}.`);
    }
    if (count > MaxRepeat) {
        throw new DefinitionError('limit', path, `At most ${MaxRepeat} items.`);
    }
    return items.map((item, index) => listRecord(parameter, item, `${path}[${index}]`, code));
}

function listRecord(parameter: Parameter, item: unknown, path: string, code: DefinitionErrorCode): ValueRecord {
    if (!isRecord(item)) {
        throw new DefinitionError(code, path, 'Must be an object.');
    }
    const record: Record<string, Value> = {};
    for (const field of parameter.fields) {
        record[field.key] = Object.hasOwn(item, field.key) ? parameterValue(field, item[field.key], `${path}.${field.key}`, code) : field.default;
    }
    for (const [key] of entries(item)) {
        if (!Object.hasOwn(record, key)) {
            throw new DefinitionError(code, `${path}.${key}`, `Unknown field "${key}".`);
        }
    }
    return record;
}

function inRange(value: unknown, parameter: Parameter): value is number {
    return isWholeNumber(value) && (parameter.min === null || value >= parameter.min) && (parameter.max === null || value <= parameter.max);
}

function optionalInt(value: unknown, path: string): number | null {
    if (value === undefined || value === null) {
        return null;
    }
    if (!isWholeNumber(value)) {
        throw invalid(path, 'Must be a whole number.');
    }
    return value;
}

function readOptions(options: unknown, path: string): string[] {
    if (!Array.isArray(options) || options.length === 0 || !options.every((o) => typeof o === 'string')) {
        throw invalid(path, 'Must be a non-empty list of strings.');
    }
    return options as string[];
}

function readValues(parameters: Map<string, Parameter>, values: unknown): Record<string, Value> {
    if (!isRecord(values)) {
        throw new DefinitionError('invalidValue', 'values', 'Must be an object.');
    }
    const variables: Record<string, Value> = {};
    for (const [key, value] of entries(values)) {
        const path = `values.${key}`;
        const parameter = parameters.get(key);
        if (parameter === undefined) {
            throw new DefinitionError('invalidValue', path, `Unknown parameter "${key}".`);
        }
        variables[key] = parameterValue(parameter, value, path, 'invalidValue');
    }
    for (const [key, parameter] of parameters) {
        if (!Object.hasOwn(variables, key)) {
            variables[key] = parameter.default;
        }
    }
    return variables;
}

function readDerived(derived: unknown, variables: Record<string, Value>): Record<string, Value> {
    if (!isRecord(derived)) {
        throw invalid('derived', 'Must be an object of name: expression.');
    }
    const result = {...variables};
    for (const [key, source] of entries(derived)) {
        const path = `derived.${key}`;
        if (freeName(key, (name) => Object.hasOwn(result, name)) === null) {
            throw invalid(path, `${NameRule} A parameter's name is taken too.`);
        }
        result[key] = run(compile(source, path), result, path);
    }
    return result;
}

function readMaterialSlots(slots: unknown): string[] {
    if (!isList(slots)) {
        throw invalid('materialSlots', 'Must be a list.');
    }
    const keys: string[] = [];
    asArray(slots).forEach((slot, index) => {
        const key = typeof slot === 'object' && slot !== null ? (slot as Record<string, unknown>)['key'] : undefined;
        if (!isName(key) || keys.includes(key)) {
            throw invalid(`materialSlots[${index}].key`, 'Must be a unique name (letters, digits, _).');
        }
        keys.push(key);
    });
    return keys;
}

/** The resolved parts, with the limits on part entries and parts counted across all nesting levels. */
class PartCollector {
    public readonly parts: ResolvedPart[] = [];
    private entries = 0;

    /** One more part entry read from the list at `path`. */
    public entry(path: string): void {
        this.entries++;
        if (this.entries > MaxPartEntries) {
            throw new DefinitionError('limit', path, `At most ${MaxPartEntries} part entries.`);
        }
    }

    public add(part: ResolvedPart, path: string): void {
        if (this.parts.length >= MaxParts) {
            throw new DefinitionError('limit', path, `More than ${MaxParts} parts.`);
        }
        this.parts.push(part);
    }
}

function readParts(entriesValue: unknown, variables: Variables, slots: string[]): ResolvedPart[] {
    if (!isList(entriesValue)) {
        throw invalid('parts', 'Must be a list.');
    }
    const list = asArray(entriesValue);
    if (list.length > MaxPartEntries) {
        throw new DefinitionError('limit', 'parts', `At most ${MaxPartEntries} part entries.`);
    }
    const collector = new PartCollector();
    list.forEach((data, index) => {
        collector.entry('parts');
        PartEntry.read(data, `parts[${index}]`, slots, 1, collector).resolve(variables, [0, 0, 0], collector);
    });
    return collector.parts;
}

const PartKeys = ['type', 'name', 'when', 'repeat', 'material', 'x', 'y', 'z', 'w', 'h', 'd'];
const Assembly = 'assembly';
const Geometry = ['x', 'y', 'z', 'w', 'h', 'd'] as const;
type GeometryKey = (typeof Geometry)[number];
const Sizes: Partial<Record<GeometryKey, string>> = {w: 'width', h: 'height', d: 'depth'};

/** The `repeat` of a part entry (see the file comment), evaluated per scope. */
class PartRepeat {
    private constructor(
        private readonly path: string,
        private readonly over: boolean,
        private readonly expression: Expression,
        private readonly as: string,
    ) {}

    public static read(repeat: unknown, path: string): PartRepeat {
        const fields = typeof repeat === 'object' && repeat !== null ? (repeat as Record<string, unknown>) : null;
        const count = fields !== null && Object.hasOwn(fields, 'count');
        const over = fields !== null && Object.hasOwn(fields, 'over');
        if (fields === null || count === over) {
            throw invalid(path, 'Must be an object with "count" or "over" (not both).');
        }
        const as = freeName(fields['as'] ?? (count ? 'i' : 'item'), () => false);
        if (as === null) {
            throw invalid(`${path}.as`, 'Must be a name (letters, digits, _) not used by another variable.');
        }
        const key = count ? 'count' : 'over';
        return new PartRepeat(path, over, compile(fields[key], `${path}.${key}`), as);
    }

    /** The copies: the index and the variables of each. */
    public copies(variables: Variables): [number, Variables][] {
        const taken = (name: string): boolean => Object.hasOwn(variables, name);
        if (freeName(this.as, taken) === null || (this.over && freeName(`${this.as}_index`, taken) === null)) {
            throw invalid(`${this.path}.as`, 'Must be a name (letters, digits, _) not used by another variable.');
        }
        if (this.over) {
            return this.items(variables).map((item, index) => [index, {...variables, [this.as]: item, [`${this.as}_index`]: index}]);
        }
        return Array.from({length: this.count(variables)}, (_, index) => [index, {...variables, [this.as]: index}]);
    }

    private items(variables: Variables): Value[] {
        const path = `${this.path}.over`;
        const list = run(this.expression, variables, path);
        if (!Array.isArray(list)) {
            throw new DefinitionError(list === null ? 'null' : 'type', path, `Must come out a list, got ${typeName(list)}.`);
        }
        checkCopies(list.length, path);
        return list;
    }

    private count(variables: Variables): number {
        const path = `${this.path}.count`;
        const count = run(this.expression, variables, path);
        if (!isWholeNumber(count) || count < 0) {
            throw invalid(path, 'Must come out a whole number, 0 or more.');
        }
        checkCopies(count, path);
        return count;
    }
}

function checkCopies(count: number, path: string): void {
    if (count > MaxRepeat) {
        throw new DefinitionError('limit', path, `At most ${MaxRepeat} copies.`);
    }
}

/** The tilt pivot (`tiltY`, `tiltZ`), written next to the part's `y` / `z`, moved into the item's frame like them. */
function placed(props: Record<string, unknown>, offset: [number, number, number]): Record<string, unknown> {
    const moved = {...props};
    for (const [key, axis] of [
        ['tiltY', 1],
        ['tiltZ', 2],
    ] as const) {
        const value = moved[key];
        if (typeof value === 'number') {
            moved[key] = value + offset[axis];
        }
    }
    return moved;
}

/** An option written `{"expr": "…"}`, parsed. */
class OptionExpression {
    public constructor(public readonly expression: Expression) {}
}

/**
 * One entry of a definition's `parts` (or of an assembly's), read with all its expressions parsed; `resolve()` adds
 * its parts for a scope of variables.
 */
class PartEntry {
    private constructor(
        private readonly path: string,
        private readonly type: string,
        private readonly name: string,
        private readonly material: string | null,
        private readonly repeat: PartRepeat | null,
        private readonly when: Expression,
        private readonly geometry: Map<GeometryKey, Expression>,
        /** Literal values, or `OptionExpression` for `{"expr": …}` ones. */
        private readonly options: Map<string, unknown>,
        private readonly children: PartEntry[],
    ) {}

    /** Reads `parts[n]` (at `depth`, 1 for the top level) with the parts of an assembly. */
    public static read(data: unknown, path: string, slots: string[], depth: number, collector: PartCollector): PartEntry {
        if (!isRecord(data)) {
            throw invalid(path, 'Must be an object.');
        }
        const type = data['type'];
        if (typeof type !== 'string' || type === '') {
            throw invalid(`${path}.type`, 'Must be a part type.');
        }
        const name = data['name'] ?? type;
        if (typeof name !== 'string') {
            throw invalid(`${path}.name`, 'Must be a string.');
        }
        const material = data['material'] ?? null;
        if (material !== null && !slots.includes(material as string)) {
            throw invalid(`${path}.material`, 'Must be the key of one of the materialSlots.');
        }
        const repeat = Object.hasOwn(data, 'repeat') ? PartRepeat.read(data['repeat'], `${path}.repeat`) : null;
        const when = compile(data['when'] ?? true, `${path}.when`, true);
        const geometry = new Map<GeometryKey, Expression>();
        for (const key of Geometry) {
            if (!Object.hasOwn(data, key)) {
                throw invalid(`${path}.${key}`, `Missing "${key}".`);
            }
            geometry.set(key, compile(data[key], `${path}.${key}`));
        }
        const assembly = type === Assembly;
        const options = readPartOptions(data, path, assembly);
        const children = assembly ? readChildren(data['parts'], `${path}.parts`, slots, depth, collector) : [];
        return new PartEntry(path, type, name, material as string | null, repeat, when, geometry, options, children);
    }

    /** Adds the parts of this entry for the variables, its box shifted by the offset (mm) of the assemblies around it. */
    public resolve(variables: Variables, offset: [number, number, number], collector: PartCollector): void {
        const copies: [number | null, Variables][] = this.repeat === null ? [[null, variables]] : this.repeat.copies(variables);
        for (const [index, scope] of copies) {
            if (!condition(this.when, scope, `${this.path}.when`)) {
                continue;
            }
            const mm = this.measure(scope);
            const at: [number, number, number] = [offset[0] + mm.x, offset[1] + mm.y, offset[2] + mm.z];
            if (this.type === Assembly) {
                const inner = {...scope, AW: mm.w, AH: mm.h, AD: mm.d};
                for (const child of this.children) {
                    child.resolve(inner, at, collector);
                }
                continue;
            }
            collector.add(
                {
                    name: this.name,
                    type: this.type,
                    index,
                    x: at[0],
                    y: at[1],
                    z: at[2],
                    w: mm.w,
                    h: mm.h,
                    d: mm.d,
                    material: this.material,
                    props: placed(this.evaluateOptions(scope), offset),
                },
                this.path,
            );
        }
    }

    private measure(scope: Variables): Record<GeometryKey, number> {
        const mm = {x: 0, y: 0, z: 0, w: 0, h: 0, d: 0};
        for (const key of Geometry) {
            const path = `${this.path}.${key}`;
            mm[key] = length(this.geometry.get(key) as Expression, scope, path);
            const dimension = Sizes[key];
            if (dimension !== undefined && mm[key] <= 0) {
                throw new DefinitionError('nonPositiveSize', path, `"${this.name}" gets a ${dimension} of ${mm[key]} mm.`);
            }
        }
        return mm;
    }

    private evaluateOptions(scope: Variables): Record<string, unknown> {
        const props: Record<string, unknown> = {};
        for (const [key, value] of this.options) {
            props[key] = value instanceof OptionExpression ? run(value.expression, scope, `${this.path}.${key}`) : value;
        }
        return props;
    }
}

/** Whether the value is an option written as an expression: `{"expr": "…"}`. */
export function isOptionExpression(value: unknown): value is {expr: unknown} {
    return isRecord(value) && !Array.isArray(value) && Object.keys(value).length === 1 && Object.hasOwn(value, 'expr');
}

/** The options of the part type (grain, edges, handle, …): all but the keys read here, `{"expr": …}` ones parsed. */
function readPartOptions(data: Record<string, unknown>, path: string, assembly: boolean): Map<string, unknown> {
    const options = new Map<string, unknown>();
    for (const [key, value] of entries(data)) {
        if (PartKeys.includes(key) || (assembly && key === 'parts')) {
            continue;
        }
        options.set(key, isOptionExpression(value) ? new OptionExpression(compile(value.expr, `${path}.${key}`)) : value);
    }
    return options;
}

function readChildren(entriesValue: unknown, path: string, slots: string[], depth: number, collector: PartCollector): PartEntry[] {
    if (!isList(entriesValue)) {
        throw invalid(path, 'An assembly needs a list of parts.');
    }
    if (depth >= MaxDepth) {
        throw new DefinitionError('limit', path, `Parts nest at most ${MaxDepth} levels deep.`);
    }
    return asArray(entriesValue).map((data, index) => {
        collector.entry(path);
        return PartEntry.read(data, `${path}[${index}]`, slots, depth + 1, collector);
    });
}

/** A number or expression (and, with `booleans`, true / false) as an `Expression`. */
function compile(source: unknown, path: string, booleans = false): Expression {
    if (typeof source === 'number' || (booleans && typeof source === 'boolean')) {
        return literalExpression(source);
    }
    if (typeof source !== 'string') {
        throw invalid(path, booleans ? 'Must be an expression, true or false.' : 'Must be a number or an expression.');
    }
    try {
        return parseExpression(source);
    } catch (e) {
        throw fromExpression(path, e);
    }
}

function run(expression: Expression, variables: Variables, path: string): Value {
    try {
        return expression.evaluate(variables);
    } catch (e) {
        throw fromExpression(path, e);
    }
}

function condition(expression: Expression, variables: Variables, path: string): boolean {
    const value = run(expression, variables, path);
    if (typeof value !== 'boolean') {
        throw new DefinitionError(value === null ? 'null' : 'type', path, `Must come out true or false, got ${typeName(value)}.`);
    }
    return value;
}

/** The result rounded half away from zero to whole mm. */
function length(expression: Expression, variables: Variables, path: string): number {
    const value = run(expression, variables, path);
    if (typeof value !== 'number') {
        throw new DefinitionError(value === null ? 'null' : 'type', path, `Must come out a number (mm), got ${typeName(value)}.`);
    }
    if (Math.abs(value) > MaxLength) {
        throw new DefinitionError('limit', path, `Must come out within ±${MaxLength} mm.`);
    }
    return roundHalfAway(value);
}

function fromExpression(path: string, error: unknown): unknown {
    return error instanceof ExpressionError ? new DefinitionError(error.code, path, error.message) : error;
}

function invalid(path: string, message: string): DefinitionError {
    return new DefinitionError('invalid', path, message);
}

/**
 * The item size, an assembly's size (AW, AH, AD), the literals true, false and null, and `__proto__` (a plain object
 * cannot hold it); no parameter, derived value or repeat index may take them.
 */
const Reserved = ['W', 'D', 'H', 'AW', 'AH', 'AD', 'true', 'false', 'null', '__proto__'];

function isName(value: unknown): value is string {
    return typeof value === 'string' && /^[A-Za-z_][A-Za-z0-9_]*$/.test(value);
}

/** `value` when it is a name for a new variable (not reserved, not taken), otherwise null. */
function freeName(value: unknown, taken: (name: string) => boolean): string | null {
    return isName(value) && !Reserved.includes(value) && !taken(value) ? value : null;
}

function isWholeNumber(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value);
}

/** An object, or an empty list (PHP decodes `{}` and `[]` alike). */
function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && (!Array.isArray(value) || value.length === 0);
}

/** A list, or an empty object (PHP decodes `{}` and `[]` alike). */
function isList(value: unknown): boolean {
    return Array.isArray(value) || (isRecord(value) && Object.keys(value).length === 0);
}

function asArray(value: unknown): unknown[] {
    return Array.isArray(value) ? value : [];
}

function entries(value: Record<string, unknown>): [string, unknown][] {
    return Array.isArray(value) ? [] : Object.entries(value);
}
