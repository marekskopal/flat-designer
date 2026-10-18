<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

/** Splits an expression into tokens: numbers (`12`, `0.5`), 'strings' / "strings", identifiers and operators. */
final readonly class Lexer
{
	private const array Operators = ['<=', '>=', '==', '!=', '&&', '||', '+', '-', '*', '/', '%', '(', ')', ',', '?', ':', '<', '>', '!'];

	/** @return list<Token> */
	public static function tokenize(string $source): array
	{
		$tokens = [];
		$position = 0;
		while ($position < strlen($source)) {
			if (preg_match('/\G\s+/', $source, $match, 0, $position) === 1) {
				$position += strlen($match[0]);
				continue;
			}
			$token = self::readToken($source, $position);
			$tokens[] = $token;
			$position += strlen($token->value) + ($token->type === TokenTypeEnum::String ? 2 : 0);
		}
		$tokens[] = new Token(TokenTypeEnum::End, '', strlen($source));

		return $tokens;
	}

	private static function readToken(string $source, int $position): Token
	{
		if (preg_match('/\G[0-9]+(\.[0-9]+)?/', $source, $match, 0, $position) === 1) {
			return new Token(TokenTypeEnum::Number, $match[0], $position);
		}
		if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $source, $match, 0, $position) === 1) {
			return new Token(TokenTypeEnum::Identifier, $match[0], $position);
		}

		$char = $source[$position];
		if ($char === '"' || $char === "'") {
			$end = strpos($source, $char, $position + 1);
			if ($end === false) {
				throw new ExpressionException(ExpressionErrorEnum::Syntax, sprintf('Unterminated string at %d.', $position));
			}

			return new Token(TokenTypeEnum::String, substr($source, $position + 1, $end - $position - 1), $position);
		}

		$operator = self::operator(substr($source, $position));
		if ($operator === null) {
			throw new ExpressionException(
				ExpressionErrorEnum::Syntax,
				sprintf('Unexpected "%s" at %d.', mb_substr(substr($source, $position), 0, 1), $position),
			);
		}

		return new Token(TokenTypeEnum::Operator, $operator, $position);
	}

	private static function operator(string $rest): ?string
	{
		foreach (self::Operators as $operator) {
			if (str_starts_with($rest, $operator)) {
				return $operator;
			}
		}

		return null;
	}
}
