/**
 * The expression language of furniture definitions. Twin: backend/src/Service/Furniture/Expression (Parser and its
 * nodes), both checked against backend/tests/fixtures/furniture-expressions.json.
 *
 * Lowest precedence first: `a ? b : c` (right associative), `??`, `||`, `&&`, `== !=`, `< <= > >=`, `+ -`, `* / %`,
 * unary `- !`, then `list[i]` and `record.field`; operands are numbers, 'strings', `true` / `false` / `null`, variables,
 * calls of {@link Functions} and parentheses. There are no implicit conversions: arithmetic takes numbers, `&&` / `||` /
 * `!` / `? :` booleans, `==` two numbers, booleans or strings of one type, or anything with null; null fails anything
 * but `??`, `== / != null` and `contains` (error `null`). Unknown functions and wrong argument counts fail when
 * parsing; unknown variables and type errors when evaluating (so `false && x` is fine).
 */

/** Numbers, booleans, strings, null, lists and records (items of list parameters). */
export type Value = number | boolean | string | null | Value[] | ValueRecord;

export interface ValueRecord {
    readonly [field: string]: Value;
}

export type Variables = Readonly<Record<string, Value>>;

export type ExpressionErrorCode =
    | 'syntax'
    | 'null'
    | 'index'
    | 'unknownField'
    | 'unknownVariable'
    | 'unknownFunction'
    | 'arity'
    | 'type'
    | 'divisionByZero'
    | 'nonFinite'
    | 'tooLong'
    | 'tooDeep';

export class ExpressionError extends Error {
    public constructor(
        public readonly code: ExpressionErrorCode,
        message: string,
    ) {
        super(message);
        this.name = 'ExpressionError';
    }
}

export const MaxExpressionLength = 500;
export const MaxExpressionDepth = 50;

/** Rounds half away from zero (as PHP's `round()`), never giving -0. */
export function roundHalfAway(value: number): number {
    return Math.sign(value) * Math.round(Math.abs(value)) || 0;
}

type FunctionDef = {arity: [number, number | null]; apply: (args: Value[], context: string) => Value};

const numbers = (args: Value[], context: string): number[] => args.map((a) => number(a, context));

/** Functions with their least and most argument count (null = any). */
const Functions: Readonly<Record<string, FunctionDef>> = {
    min: {arity: [1, null], apply: (args, c) => Math.min(...numbers(args, c))},
    max: {arity: [1, null], apply: (args, c) => Math.max(...numbers(args, c))},
    round: {arity: [1, 1], apply: ([x], c) => roundHalfAway(number(x, c))},
    floor: {arity: [1, 1], apply: ([x], c) => Math.floor(number(x, c))},
    ceil: {arity: [1, 1], apply: ([x], c) => Math.ceil(number(x, c))},
    abs: {arity: [1, 1], apply: ([x], c) => Math.abs(number(x, c))},
    clamp: {arity: [3, 3], apply: ([x, lo, hi], c) => Math.max(number(lo, c), Math.min(number(hi, c), number(x, c)))},
    len: {arity: [1, 1], apply: ([l], c) => list(l, c).length},
    sum: {arity: [1, 1], apply: ([l], c) => finite(numbers(list(l, c), c).reduce((a, b) => a + b, 0))},
    contains: {arity: [2, 2], apply: ([l, needle], c) => contains(list(l, c), needle)},
    field: {arity: [2, 2], apply: ([l, name], c) => list(l, c).map((item) => fieldOf(record(item, c), string(name, c)))},
    share: {arity: [2, 3], apply: ([l, total, gap], c) => share(list(l, c), number(total, c), gap === undefined ? 0 : number(gap, c), c)},
    offsets: {arity: [1, 2], apply: ([l, gap], c) => offsets(list(l, c), gap === undefined ? 0 : number(gap, c), c)},
};

type Node =
    | {kind: 'literal'; value: Value}
    | {kind: 'nullish'; left: Node; right: Node}
    | {kind: 'index'; target: Node; index: Node}
    | {kind: 'member'; target: Node; field: string}
    | {kind: 'variable'; name: string}
    | {kind: 'unary'; operator: string; operand: Node}
    | {kind: 'binary'; operator: string; left: Node; right: Node}
    | {kind: 'logical'; operator: string; left: Node; right: Node}
    | {kind: 'conditional'; condition: Node; then: Node; else: Node}
    | {kind: 'call'; name: string; args: Node[]};

/** A parsed expression; evaluate it as often as needed with different variables. */
export interface Expression {
    readonly source: string;
    evaluate(variables: Variables): Value;
}

export function parseExpression(source: string): Expression {
    // Characters (code points), as the PHP twin's mb_strlen.
    if ([...source].length > MaxExpressionLength) {
        throw new ExpressionError('tooLong', `Expression is longer than ${MaxExpressionLength} characters.`);
    }
    const root = new Parser(tokenize(source)).parse();
    return {source, evaluate: (variables) => evaluate(root, variables)};
}

/** An expression of a fixed value (a number or boolean written as such in a definition). */
export function literalExpression(value: Value): Expression {
    return {source: String(value), evaluate: () => value};
}

type TokenType = 'number' | 'string' | 'identifier' | 'operator' | 'end';

interface Token {
    type: TokenType;
    value: string;
    position: number;
}

const Operators = ['<=', '>=', '==', '!=', '&&', '||', '??', '+', '-', '*', '/', '%', '(', ')', '[', ']', ',', '.', '?', ':', '<', '>', '!'];

const SpacePattern = /[ \t\n\v\f\r]+/y;
const NumberPattern = /[0-9]+(\.[0-9]+)?/y;
const IdentifierPattern = /[A-Za-z_][A-Za-z0-9_]*/y;

function matchAt(pattern: RegExp, source: string, position: number): string | null {
    pattern.lastIndex = position;
    return pattern.exec(source)?.[0] ?? null;
}

function tokenize(source: string): Token[] {
    const tokens: Token[] = [];
    let position = 0;
    while (position < source.length) {
        const space = matchAt(SpacePattern, source, position);
        if (space !== null) {
            position += space.length;
            continue;
        }
        const token = readToken(source, position);
        tokens.push(token);
        position += token.type === 'string' ? token.value.length + 2 : token.value.length;
    }
    tokens.push({type: 'end', value: '', position: source.length});
    return tokens;
}

function readToken(source: string, position: number): Token {
    const numberText = matchAt(NumberPattern, source, position);
    if (numberText !== null) {
        return {type: 'number', value: numberText, position};
    }
    const identifier = matchAt(IdentifierPattern, source, position);
    if (identifier !== null) {
        return {type: 'identifier', value: identifier, position};
    }
    const char = source[position];
    if (char === '"' || char === "'") {
        const end = source.indexOf(char, position + 1);
        if (end === -1) {
            throw new ExpressionError('syntax', `Unterminated string at ${position}.`);
        }
        return {type: 'string', value: source.slice(position + 1, end), position};
    }
    const operator = Operators.find((o) => source.startsWith(o, position));
    if (operator === undefined) {
        throw new ExpressionError('syntax', `Unexpected "${char}" at ${position}.`);
    }
    return {type: 'operator', value: operator, position};
}

class Parser {
    private position = 0;
    private depth = 0;

    public constructor(private readonly tokens: Token[]) {}

    public parse(): Node {
        const root = this.parseConditional();
        if (this.peek().type !== 'end') {
            throw this.unexpected();
        }
        return root;
    }

    private parseConditional(): Node {
        this.enter();
        let node = this.parseNullish();
        if (this.accept('?')) {
            const then = this.parseConditional();
            this.expect(':');
            node = {kind: 'conditional', condition: node, then, else: this.parseConditional()};
        }
        this.depth--;
        return node;
    }

    private parseNullish(): Node {
        let node = this.parseOr();
        while (this.accept('??')) {
            node = {kind: 'nullish', left: node, right: this.parseOr()};
        }
        return node;
    }

    private parseOr(): Node {
        let node = this.parseAnd();
        while (this.accept('||')) {
            node = {kind: 'logical', operator: '||', left: node, right: this.parseAnd()};
        }
        return node;
    }

    private parseAnd(): Node {
        let node = this.parseEquality();
        while (this.accept('&&')) {
            node = {kind: 'logical', operator: '&&', left: node, right: this.parseEquality()};
        }
        return node;
    }

    private parseEquality(): Node {
        return this.parseBinary(['==', '!='], () => this.parseComparison());
    }

    private parseComparison(): Node {
        return this.parseBinary(['<', '<=', '>', '>='], () => this.parseAdditive());
    }

    private parseAdditive(): Node {
        return this.parseBinary(['+', '-'], () => this.parseMultiplicative());
    }

    private parseMultiplicative(): Node {
        return this.parseBinary(['*', '/', '%'], () => this.parseUnary());
    }

    private parseBinary(operators: string[], operand: () => Node): Node {
        let node = operand();
        let operator = this.acceptAny(operators);
        while (operator !== null) {
            node = {kind: 'binary', operator, left: node, right: operand()};
            operator = this.acceptAny(operators);
        }
        return node;
    }

    private parseUnary(): Node {
        const operator = this.acceptAny(['-', '!']);
        if (operator === null) {
            return this.parsePostfix();
        }
        this.enter();
        const node: Node = {kind: 'unary', operator, operand: this.parseUnary()};
        this.depth--;
        return node;
    }

    /** Indexing and field access after an operand: `fronts[0].height`. */
    private parsePostfix(): Node {
        let node = this.parsePrimary();
        for (;;) {
            if (this.accept('[')) {
                node = {kind: 'index', target: node, index: this.parseConditional()};
                this.expect(']');
            } else if (this.accept('.')) {
                node = {kind: 'member', target: node, field: this.fieldName()};
            } else {
                return node;
            }
        }
    }

    private fieldName(): string {
        const field = this.peek();
        if (field.type !== 'identifier') {
            throw this.unexpected(' (expected a field name)');
        }
        this.position++;
        return field.value;
    }

    private parsePrimary(): Node {
        const token = this.peek();
        switch (token.type) {
            case 'number': {
                this.position++;
                const value = Number(token.value);
                if (!Number.isFinite(value)) {
                    throw new ExpressionError('nonFinite', `Number at ${token.position} is too large.`);
                }
                return {kind: 'literal', value};
            }
            case 'string':
                this.position++;
                return {kind: 'literal', value: token.value};
            case 'identifier':
                this.position++;
                if (token.value === 'true' || token.value === 'false') {
                    return {kind: 'literal', value: token.value === 'true'};
                }
                if (token.value === 'null') {
                    return {kind: 'literal', value: null};
                }
                return this.accept('(') ? this.parseCall(token) : {kind: 'variable', name: token.value};
            default:
                if (this.accept('(')) {
                    const node = this.parseConditional();
                    this.expect(')');
                    return node;
                }
                throw this.unexpected();
        }
    }

    /** After the opening parenthesis of `name(…)`. */
    private parseCall(name: Token): Node {
        const fn = Object.hasOwn(Functions, name.value) ? Functions[name.value] : undefined;
        if (fn === undefined) {
            throw new ExpressionError('unknownFunction', `Unknown function "${name.value}".`);
        }
        const args: Node[] = [];
        if (!this.accept(')')) {
            do {
                args.push(this.parseConditional());
            } while (this.accept(','));
            this.expect(')');
        }
        const [least, most] = fn.arity;
        if (args.length < least || (most !== null && args.length > most)) {
            const range = most === least ? `${least}` : `${least} to ${most}`;
            const takes = most === null ? `at least ${least} argument(s)` : `${range} argument(s)`;
            throw new ExpressionError('arity', `Function "${name.value}" takes ${takes}, got ${args.length}.`);
        }
        return {kind: 'call', name: name.value, args};
    }

    private enter(): void {
        this.depth++;
        if (this.depth > MaxExpressionDepth) {
            throw new ExpressionError('tooDeep', `Expression is nested deeper than ${MaxExpressionDepth} levels.`);
        }
    }

    private peek(): Token {
        return this.tokens[Math.min(this.position, this.tokens.length - 1)];
    }

    private accept(operator: string): boolean {
        return this.acceptAny([operator]) !== null;
    }

    private acceptAny(operators: string[]): string | null {
        const token = this.peek();
        if (token.type !== 'operator' || !operators.includes(token.value)) {
            return null;
        }
        this.position++;
        return token.value;
    }

    private expect(operator: string): void {
        if (!this.accept(operator)) {
            throw this.unexpected(` (expected "${operator}")`);
        }
    }

    private unexpected(hint = ''): ExpressionError {
        const token = this.peek();
        return new ExpressionError(
            'syntax',
            token.type === 'end' ? `Unexpected end of expression${hint}.` : `Unexpected "${token.value}" at ${token.position}${hint}.`,
        );
    }
}

export function typeName(value: Value): string {
    if (value === null) {
        return 'null';
    }
    if (Array.isArray(value)) {
        return 'a list';
    }
    switch (typeof value) {
        case 'number':
            return 'a number';
        case 'boolean':
            return 'a boolean';
        case 'string':
            return 'a string';
        default:
            return 'a record';
    }
}

function wrong(value: Value, expected: string, context: string): ExpressionError {
    return new ExpressionError(value === null ? 'null' : 'type', `${context} needs ${expected}, got ${typeName(value)}.`);
}

function number(value: Value | undefined, context: string): number {
    if (typeof value !== 'number') {
        throw wrong(value ?? null, 'a number', context);
    }
    return value;
}

function boolean(value: Value, context: string): boolean {
    if (typeof value !== 'boolean') {
        throw wrong(value, 'true or false', context);
    }
    return value;
}

function string(value: Value | undefined, context: string): string {
    if (typeof value !== 'string') {
        throw wrong(value ?? null, 'a string', context);
    }
    return value;
}

function list(value: Value | undefined, context: string): Value[] {
    if (!Array.isArray(value)) {
        throw wrong(value ?? null, 'a list', context);
    }
    return value;
}

function isRecord(value: Value): value is ValueRecord {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function record(value: Value, context: string): ValueRecord {
    if (!isRecord(value)) {
        throw wrong(value, 'a record', context);
    }
    return value;
}

function fieldOf(value: ValueRecord, field: string): Value {
    if (!Object.hasOwn(value, field)) {
        throw new ExpressionError('unknownField', `No field "${field}".`);
    }
    return value[field];
}

/** A number, boolean, string or null: what `==` and `contains` compare. */
function isScalar(value: Value): value is number | boolean | string | null {
    return value === null || typeof value !== 'object';
}

/** `==`: null equals only null; otherwise two numbers, booleans or strings of one type. */
function equals(a: Value, b: Value, context: string): boolean {
    if (a === null || b === null) {
        return a === b;
    }
    if (!isScalar(a) || !isScalar(b) || typeof a !== typeof b) {
        throw new ExpressionError('type', `${context} compares ${typeName(a)} with ${typeName(b)}.`);
    }
    return a === b;
}

function contains(items: Value[], needle: Value | undefined): boolean {
    const value = needle ?? null;
    if (!isScalar(value)) {
        throw new ExpressionError('type', `Function "contains" looks for a number, boolean, string or null, got ${typeName(value)}.`);
    }
    return items.some((item) => isScalar(item) && item === value);
}

/** The sizes with each null replaced by an equal share of what the others and the gaps between them leave of `total`. */
function share(sizes: Value[], total: number, gap: number, context: string): number[] {
    const given = finite(sizes.filter((s) => s !== null).reduce((sum: number, s) => sum + number(s, context), 0));
    const open = sizes.filter((s) => s === null).length;
    const each = finite(open === 0 ? 0 : (total - given - gap * Math.max(sizes.length - 1, 0)) / open);
    return sizes.map((s) => (s === null ? each : number(s, context)));
}

/** Where each size starts when laid out in a row from 0 with `gap` between them. */
function offsets(sizes: Value[], gap: number, context: string): number[] {
    let position = 0;
    return sizes.map((s) => {
        const start = position;
        position = finite(position + number(s, context) + gap);
        return start;
    });
}

function finite(value: number): number {
    if (!Number.isFinite(value)) {
        throw new ExpressionError('nonFinite', 'Result is too large.');
    }
    return value;
}

function evaluate(node: Node, variables: Variables): Value {
    switch (node.kind) {
        case 'literal':
            return node.value;
        case 'variable':
            if (!Object.hasOwn(variables, node.name)) {
                throw new ExpressionError('unknownVariable', `Unknown variable "${node.name}".`);
            }
            return variables[node.name];
        case 'nullish':
            return evaluate(node.left, variables) ?? evaluate(node.right, variables);
        case 'index':
            return evaluateIndex(list(evaluate(node.target, variables), '"[ ]"'), number(evaluate(node.index, variables), 'An index'));
        case 'member':
            return fieldOf(record(evaluate(node.target, variables), `".${node.field}"`), node.field);
        case 'unary': {
            const value = evaluate(node.operand, variables);
            return node.operator === '-' ? -number(value, 'Unary "-"') : !boolean(value, '"!"');
        }
        case 'binary':
            return evaluateBinary(node.operator, evaluate(node.left, variables), evaluate(node.right, variables));
        case 'logical': {
            const context = `"${node.operator}"`;
            const left = boolean(evaluate(node.left, variables), context);
            return left === (node.operator === '||') ? left : boolean(evaluate(node.right, variables), context);
        }
        case 'conditional':
            return boolean(evaluate(node.condition, variables), '"? :"') ? evaluate(node.then, variables) : evaluate(node.else, variables);
        case 'call': {
            const args = node.args.map((arg) => evaluate(arg, variables));
            return Functions[node.name].apply(args, `Function "${node.name}"`);
        }
    }
}

function evaluateIndex(items: Value[], index: number): Value {
    if (!Number.isInteger(index) || index < 0 || index >= items.length) {
        throw new ExpressionError('index', `Index ${index} is not within the list (${items.length} items).`);
    }
    return items[index];
}

function evaluateBinary(operator: string, left: Value, right: Value): Value {
    const context = `"${operator}"`;
    if (operator === '==' || operator === '!=') {
        return equals(left, right, context) === (operator === '==');
    }
    const a = number(left, context);
    const b = number(right, context);
    if ((operator === '/' || operator === '%') && b === 0) {
        throw new ExpressionError('divisionByZero', `"${operator}" by zero.`);
    }
    switch (operator) {
        case '+':
            return finite(a + b);
        case '-':
            return finite(a - b);
        case '*':
            return finite(a * b);
        case '/':
            return finite(a / b);
        case '%':
            return a % b;
        case '<':
            return a < b;
        case '<=':
            return a <= b;
        case '>':
            return a > b;
        default:
            return a >= b;
    }
}
