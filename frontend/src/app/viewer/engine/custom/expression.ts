/**
 * The expression language of furniture definitions. Twin: backend/src/Service/Furniture/Expression (Parser and its
 * nodes), both checked against backend/tests/fixtures/furniture-expressions.json.
 *
 * Lowest precedence first: `a ? b : c` (right associative), `||`, `&&`, `== !=`, `< <= > >=`, `+ -`, `* / %`, unary
 * `- !`; operands are numbers, 'strings', `true` / `false`, variables, calls of {@link Functions} and parentheses. There
 * are no implicit conversions: arithmetic takes numbers, `&&` / `||` / `!` / `? :` booleans, `==` two values of one type.
 * Unknown functions and wrong argument counts fail when parsing; unknown variables and type errors when evaluating (so
 * `false && x` is fine).
 */

export type Value = number | boolean | string;

export type Variables = Readonly<Record<string, Value>>;

export type ExpressionErrorCode =
    | 'syntax'
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

/** Functions with their least and most argument count (null = any). */
const Functions: Readonly<Record<string, {arity: [number, number | null]; apply: (args: number[]) => number}>> = {
    min: {arity: [1, null], apply: (args) => Math.min(...args)},
    max: {arity: [1, null], apply: (args) => Math.max(...args)},
    round: {arity: [1, 1], apply: ([x]) => roundHalfAway(x)},
    floor: {arity: [1, 1], apply: ([x]) => Math.floor(x)},
    ceil: {arity: [1, 1], apply: ([x]) => Math.ceil(x)},
    abs: {arity: [1, 1], apply: ([x]) => Math.abs(x)},
    clamp: {arity: [3, 3], apply: ([x, lo, hi]) => Math.max(lo, Math.min(hi, x))},
};

type Node =
    | {kind: 'literal'; value: Value}
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
    if (source.length > MaxExpressionLength) {
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

const Operators = ['<=', '>=', '==', '!=', '&&', '||', '+', '-', '*', '/', '%', '(', ')', ',', '?', ':', '<', '>', '!'];

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
        let node = this.parseOr();
        if (this.accept('?')) {
            const then = this.parseConditional();
            this.expect(':');
            node = {kind: 'conditional', condition: node, then, else: this.parseConditional()};
        }
        this.depth--;
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
            return this.parsePrimary();
        }
        this.enter();
        const node: Node = {kind: 'unary', operator, operand: this.parseUnary()};
        this.depth--;
        return node;
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
            const takes = most === null ? `at least ${least} argument(s)` : `${least} argument(s)`;
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
    switch (typeof value) {
        case 'number':
            return 'a number';
        case 'boolean':
            return 'a boolean';
        default:
            return 'a string';
    }
}

function number(value: Value, context: string): number {
    if (typeof value !== 'number') {
        throw new ExpressionError('type', `${context} needs a number, got ${typeName(value)}.`);
    }
    return value;
}

function boolean(value: Value, context: string): boolean {
    if (typeof value !== 'boolean') {
        throw new ExpressionError('type', `${context} needs true or false, got ${typeName(value)}.`);
    }
    return value;
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
            const context = `Function "${node.name}"`;
            return Functions[node.name].apply(node.args.map((arg) => number(evaluate(arg, variables), context)));
        }
    }
}

function evaluateBinary(operator: string, left: Value, right: Value): Value {
    if (operator === '==' || operator === '!=') {
        if (typeof left !== typeof right) {
            throw new ExpressionError('type', `"${operator}" compares ${typeName(left)} with ${typeName(right)}.`);
        }
        return (left === right) === (operator === '==');
    }
    const context = `"${operator}"`;
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
