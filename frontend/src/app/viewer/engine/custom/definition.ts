import {
    Expression,
    ExpressionError,
    ExpressionErrorCode,
    literalExpression,
    parseExpression,
    roundHalfAway,
    typeName,
    Value,
    Variables,
} from './expression';

/**
 * Turns a furniture definition (`FurnitureDefinitionBody`, format 1) and an item's size and parameter values into its
 * parts. Twin: backend/src/Service/Furniture/DefinitionEvaluator.php, both checked against
 * backend/tests/fixtures/furniture-definitions.json.
 *
 * Variables: `W`, `D`, `H` (the item size), the parameters (item value or default), then `derived` in order, and inside
 * a repeated part its index (`repeat.as`, default `i`). For each part entry: `repeat.count` copies (one without
 * `repeat`), each kept when `when` is true; `x y z w h d` are numbers or expressions, rounded half away from zero to
 * mm, and `w h d` must come out positive. Only what the evaluation reads is checked here. The input is untrusted JSON;
 * like PHP's decoder, `{}` and `[]` are both accepted as an empty list or object.
 */

export const DefinitionFormat = 1;
export const MaxPartEntries = 200;
export const MaxParts = 500;
export const MaxRepeat = 100;

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
 * the width, y up, z towards the front). `index` is the repeat index (null without `repeat`); `props` are the part's
 * other keys as written (type-specific: grain, edges, handle, …).
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

type ParameterType = 'int' | 'bool' | 'select';

export interface Parameter {
    key: string;
    type: ParameterType;
    default: Value;
    min: number | null;
    max: number | null;
    options: string[];
}

/** The definition's parameters by key. */
export function readParameters(definitions: unknown): Map<string, Parameter> {
    if (!isList(definitions)) {
        throw invalid('parameters', 'Must be a list.');
    }
    const parameters = new Map<string, Parameter>();
    asArray(definitions).forEach((definition, index) => {
        const parameter = readParameter(definition, `parameters[${index}]`, parameters);
        parameters.set(parameter.key, parameter);
    });
    return parameters;
}

/** The value as an expression variable, or null when it does not fit (wrong type, out of range, not an option). */
export function acceptValue(parameter: Parameter, value: unknown): Value | null {
    switch (parameter.type) {
        case 'int':
            return inRange(value, parameter) ? value : null;
        case 'bool':
            return typeof value === 'boolean' ? value : null;
        case 'select':
            return typeof value === 'string' && parameter.options.includes(value) ? value : null;
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
    }
}

function readParameter(definition: unknown, path: string, taken: Map<string, Parameter>): Parameter {
    if (typeof definition !== 'object' || definition === null) {
        throw invalid(path, 'Must be an object.');
    }
    const fields = definition as Record<string, unknown>;
    const key = freeName(fields['key'], (name) => taken.has(name));
    if (key === null) {
        throw invalid(`${path}.key`, 'Must be a unique name (letters, digits, _), not W, D, H, true or false.');
    }
    const type = fields['type'];
    if (type !== 'int' && type !== 'bool' && type !== 'select') {
        throw invalid(`${path}.type`, 'Must be "int", "bool" or "select".');
    }
    const parameter: Parameter = {
        key,
        type,
        default: false,
        min: optionalInt(fields['min'], `${path}.min`),
        max: optionalInt(fields['max'], `${path}.max`),
        options: type === 'select' ? readOptions(fields['options'], `${path}.options`) : [],
    };
    const accepted = acceptValue(parameter, fields['default']);
    if (accepted === null) {
        throw invalid(`${path}.default`, `Must be ${describeParameter(parameter)}.`);
    }
    return {...parameter, default: accepted};
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
        const accepted = acceptValue(parameter, value);
        if (accepted === null) {
            throw new DefinitionError('invalidValue', path, `Must be ${describeParameter(parameter)}.`);
        }
        variables[key] = accepted;
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
            throw invalid(path, 'Must be a unique name (letters, digits, _), not W, D, H, true, false or a parameter.');
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

function readParts(entriesValue: unknown, variables: Variables, slots: string[]): ResolvedPart[] {
    if (!isList(entriesValue)) {
        throw invalid('parts', 'Must be a list.');
    }
    const list = asArray(entriesValue);
    if (list.length > MaxPartEntries) {
        throw new DefinitionError('limit', 'parts', `At most ${MaxPartEntries} part entries.`);
    }
    const parts: ResolvedPart[] = [];
    list.forEach((data, index) => {
        const entry = PartEntry.read(data, `parts[${index}]`, variables, slots);
        const copies = entry.count === null ? [null] : Array.from({length: entry.count}, (_, i) => i);
        for (const copy of copies) {
            const part = entry.resolve(variables, copy);
            if (part === null) {
                continue;
            }
            if (parts.length >= MaxParts) {
                throw new DefinitionError('limit', entry.path, `More than ${MaxParts} parts.`);
            }
            parts.push(part);
        }
    });
    return parts;
}

const PartKeys = ['type', 'name', 'when', 'repeat', 'material', 'x', 'y', 'z', 'w', 'h', 'd'];
const Geometry = ['x', 'y', 'z', 'w', 'h', 'd'] as const;
const Sizes: Partial<Record<(typeof Geometry)[number], string>> = {w: 'width', h: 'height', d: 'depth'};

/** One entry of a definition's `parts`, read and with its expressions parsed; `resolve()` makes one part of it. */
class PartEntry {
    private constructor(
        public readonly path: string,
        public readonly type: string,
        public readonly name: string,
        public readonly material: string | null,
        public readonly indexName: string | null,
        public readonly count: number | null,
        private readonly when: Expression,
        private readonly geometry: Map<(typeof Geometry)[number], Expression>,
        private readonly props: Record<string, unknown>,
    ) {}

    /** Reads `parts[n]`; `repeat.count` is evaluated here, the rest per copy. */
    public static read(data: unknown, path: string, variables: Variables, slots: string[]): PartEntry {
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
        const [indexName, count] = Object.hasOwn(data, 'repeat') ? readRepeat(data['repeat'], `${path}.repeat`, variables) : [null, null];
        const when = compile(data['when'] ?? true, `${path}.when`, true);
        const geometry = new Map<(typeof Geometry)[number], Expression>();
        for (const key of Geometry) {
            if (!Object.hasOwn(data, key)) {
                throw invalid(`${path}.${key}`, `Missing "${key}".`);
            }
            geometry.set(key, compile(data[key], `${path}.${key}`));
        }
        const props = Object.fromEntries(entries(data).filter(([key]) => !PartKeys.includes(key)));
        return new PartEntry(path, type, name, material as string | null, indexName, count, when, geometry, props);
    }

    /** The copy `index` (null without `repeat`), or null when its `when` is false. */
    public resolve(variables: Variables, index: number | null): ResolvedPart | null {
        const scope = this.indexName !== null && index !== null ? {...variables, [this.indexName]: index} : variables;
        if (!condition(this.when, scope, `${this.path}.when`)) {
            return null;
        }
        const mm: Record<string, number> = {};
        for (const [key, expression] of this.geometry) {
            const path = `${this.path}.${key}`;
            mm[key] = length(expression, scope, path);
            const dimension = Sizes[key];
            if (dimension !== undefined && mm[key] <= 0) {
                throw new DefinitionError('nonPositiveSize', path, `"${this.name}" gets a ${dimension} of ${mm[key]} mm.`);
            }
        }
        return {
            name: this.name,
            type: this.type,
            index,
            x: mm['x'],
            y: mm['y'],
            z: mm['z'],
            w: mm['w'],
            h: mm['h'],
            d: mm['d'],
            material: this.material,
            props: this.props,
        };
    }
}

function readRepeat(repeat: unknown, path: string, variables: Variables): [string, number] {
    if (typeof repeat !== 'object' || repeat === null || !Object.hasOwn(repeat, 'count')) {
        throw invalid(path, 'Must be an object with "count".');
    }
    const fields = repeat as Record<string, unknown>;
    const as = freeName(fields['as'] ?? 'i', (name) => Object.hasOwn(variables, name));
    if (as === null) {
        throw invalid(`${path}.as`, 'Must be a name (letters, digits, _) not used by another variable.');
    }
    const count = run(compile(fields['count'], `${path}.count`), variables, `${path}.count`);
    if (!isWholeNumber(count) || count < 0) {
        throw invalid(`${path}.count`, 'Must come out a whole number, 0 or more.');
    }
    if (count > MaxRepeat) {
        throw new DefinitionError('limit', `${path}.count`, `At most ${MaxRepeat} copies.`);
    }
    return [as, count];
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
        throw new DefinitionError('type', path, `Must come out true or false, got ${typeName(value)}.`);
    }
    return value;
}

/** The result rounded half away from zero to whole mm. */
function length(expression: Expression, variables: Variables, path: string): number {
    const value = run(expression, variables, path);
    if (typeof value !== 'number') {
        throw new DefinitionError('type', path, `Must come out a number (mm), got ${typeName(value)}.`);
    }
    return roundHalfAway(value);
}

function fromExpression(path: string, error: unknown): unknown {
    return error instanceof ExpressionError ? new DefinitionError(error.code, path, error.message) : error;
}

function invalid(path: string, message: string): DefinitionError {
    return new DefinitionError('invalid', path, message);
}

/** The item size and the boolean literals; no parameter, derived value or repeat index may take them. */
const Reserved = ['W', 'D', 'H', 'true', 'false'];

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
