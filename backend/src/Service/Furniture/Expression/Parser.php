<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

use FlatDesigner\Service\Furniture\Expression\Node\BinaryNode;
use FlatDesigner\Service\Furniture\Expression\Node\CallNode;
use FlatDesigner\Service\Furniture\Expression\Node\ConditionalNode;
use FlatDesigner\Service\Furniture\Expression\Node\LiteralNode;
use FlatDesigner\Service\Furniture\Expression\Node\LogicalNode;
use FlatDesigner\Service\Furniture\Expression\Node\Node;
use FlatDesigner\Service\Furniture\Expression\Node\UnaryNode;
use FlatDesigner\Service\Furniture\Expression\Node\VariableNode;

/**
 * Parses the expression language of furniture definitions (twin: frontend/src/app/viewer/engine/custom/expression.ts,
 * both checked against tests/fixtures/furniture-expressions.json). Lowest precedence first: `a ? b : c` (right
 * associative), `||`, `&&`, `== !=`, `< <= > >=`, `+ -`, `* / %`, unary `- !`; operands are numbers, 'strings',
 * `true` / `false`, variables, calls of `FunctionEnum` and parentheses. Unknown functions and wrong argument counts
 * fail here; unknown variables and type errors only when evaluated (so `false && x` is fine).
 */
final class Parser
{
	public const int MaxLength = 500;

	public const int MaxDepth = 50;

	private int $position = 0;

	private int $depth = 0;

	/** @param list<Token> $tokens */
	private function __construct(private readonly array $tokens)
	{
	}

	public static function parse(string $source): Expression
	{
		if (mb_strlen($source) > self::MaxLength) {
			throw new ExpressionException(
				ExpressionErrorEnum::TooLong,
				sprintf('Expression is longer than %d characters.', self::MaxLength),
			);
		}

		$parser = new self(Lexer::tokenize($source));
		$root = $parser->parseConditional();
		if ($parser->peek()->type !== TokenTypeEnum::End) {
			throw $parser->unexpected();
		}

		return new Expression($source, $root);
	}

	private function parseConditional(): Node
	{
		$this->enter();
		$node = $this->parseOr();
		if ($this->accept('?')) {
			$then = $this->parseConditional();
			$this->expect(':');
			$node = new ConditionalNode($node, $then, $this->parseConditional());
		}
		$this->depth--;

		return $node;
	}

	private function parseOr(): Node
	{
		$node = $this->parseAnd();
		while ($this->accept('||')) {
			$node = new LogicalNode('||', $node, $this->parseAnd());
		}

		return $node;
	}

	private function parseAnd(): Node
	{
		$node = $this->parseEquality();
		while ($this->accept('&&')) {
			$node = new LogicalNode('&&', $node, $this->parseEquality());
		}

		return $node;
	}

	private function parseEquality(): Node
	{
		$node = $this->parseComparison();
		while (($operator = $this->acceptAny(['==', '!='])) !== null) {
			$node = new BinaryNode($operator, $node, $this->parseComparison());
		}

		return $node;
	}

	private function parseComparison(): Node
	{
		$node = $this->parseAdditive();
		while (($operator = $this->acceptAny(['<', '<=', '>', '>='])) !== null) {
			$node = new BinaryNode($operator, $node, $this->parseAdditive());
		}

		return $node;
	}

	private function parseAdditive(): Node
	{
		$node = $this->parseMultiplicative();
		while (($operator = $this->acceptAny(['+', '-'])) !== null) {
			$node = new BinaryNode($operator, $node, $this->parseMultiplicative());
		}

		return $node;
	}

	private function parseMultiplicative(): Node
	{
		$node = $this->parseUnary();
		while (($operator = $this->acceptAny(['*', '/', '%'])) !== null) {
			$node = new BinaryNode($operator, $node, $this->parseUnary());
		}

		return $node;
	}

	private function parseUnary(): Node
	{
		$operator = $this->acceptAny(['-', '!']);
		if ($operator === null) {
			return $this->parsePrimary();
		}

		$this->enter();
		$node = new UnaryNode($operator, $this->parseUnary());
		$this->depth--;

		return $node;
	}

	private function parsePrimary(): Node
	{
		$token = $this->peek();

		switch ($token->type) {
			case TokenTypeEnum::Number:
				$this->position++;
				$value = (float) $token->value;
				if (!is_finite($value)) {
					throw new ExpressionException(ExpressionErrorEnum::NonFinite, sprintf('Number at %d is too large.', $token->position));
				}

				return new LiteralNode($value);
			case TokenTypeEnum::String:
				$this->position++;

				return new LiteralNode($token->value);
			case TokenTypeEnum::Identifier:
				$this->position++;
				if ($token->value === 'true' || $token->value === 'false') {
					return new LiteralNode($token->value === 'true');
				}

				return $this->accept('(') ? $this->parseCall($token) : new VariableNode($token->value);
			default:
				if ($this->accept('(')) {
					$node = $this->parseConditional();
					$this->expect(')');

					return $node;
				}

				throw $this->unexpected();
		}
	}

	/** After the opening parenthesis of `name(…)`. */
	private function parseCall(Token $name): Node
	{
		$function = FunctionEnum::tryFrom($name->value);
		if ($function === null) {
			throw new ExpressionException(ExpressionErrorEnum::UnknownFunction, sprintf('Unknown function "%s".', $name->value));
		}

		$arguments = [];
		if (!$this->accept(')')) {
			do {
				$arguments[] = $this->parseConditional();
			} while ($this->accept(','));
			$this->expect(')');
		}

		[$least, $most] = $function->arity();
		$first = array_shift($arguments);
		$count = $first === null ? 0 : count($arguments) + 1;
		if ($first === null || $count < $least || $most !== null && $count > $most) {
			throw new ExpressionException(ExpressionErrorEnum::Arity, sprintf(
				'Function "%s" takes %s, got %d.',
				$function->value,
				$most === null ? sprintf('at least %d argument(s)', $least) : sprintf('%d argument(s)', $least),
				$count,
			));
		}

		return new CallNode($function, $first, $arguments);
	}

	private function enter(): void
	{
		$this->depth++;
		if ($this->depth > self::MaxDepth) {
			throw new ExpressionException(
				ExpressionErrorEnum::TooDeep,
				sprintf('Expression is nested deeper than %d levels.', self::MaxDepth),
			);
		}
	}

	private function peek(): Token
	{
		return $this->tokens[$this->position] ?? $this->tokens[count($this->tokens) - 1];
	}

	private function accept(string $operator): bool
	{
		return $this->acceptAny([$operator]) !== null;
	}

	/** @param list<string> $operators */
	private function acceptAny(array $operators): ?string
	{
		$token = $this->peek();
		if ($token->type !== TokenTypeEnum::Operator || !in_array($token->value, $operators, true)) {
			return null;
		}

		$this->position++;

		return $token->value;
	}

	private function expect(string $operator): void
	{
		if (!$this->accept($operator)) {
			throw $this->unexpected(sprintf(' (expected "%s")', $operator));
		}
	}

	private function unexpected(string $hint = ''): ExpressionException
	{
		$token = $this->peek();
		$message = $token->type === TokenTypeEnum::End
			? sprintf('Unexpected end of expression%s.', $hint)
			: sprintf('Unexpected "%s" at %d%s.', $token->value, $token->position, $hint);

		return new ExpressionException(ExpressionErrorEnum::Syntax, $message);
	}
}
