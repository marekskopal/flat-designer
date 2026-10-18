<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

/**
 * Splits an expression into tokens: numbers (`12`, `0.5`), 'strings' / "strings", identifiers and operators. Positions
 * (in the tokens and the error messages) count characters, as the TS twin's do.
 */
final readonly class Lexer
{
	private const array Operators = [
		'<=',
		'>=',
		'==',
		'!=',
		'&&',
		'||',
		'??',
		'+',
		'-',
		'*',
		'/',
		'%',
		'(',
		')',
		'[',
		']',
		',',
		'.',
		'?',
		':',
		'<',
		'>',
		'!',
	];

	/** @return list<Token> */
	public static function tokenize(string $source): array
	{
		$tokens = [];
		$position = 0;
		$length = mb_strlen($source);
		while ($position < $length) {
			$rest = mb_substr($source, $position);
			// ASCII whitespace only, as the TS twin (a non-breaking space is an error in both).
			if (preg_match('/^[ \t\n\v\f\r]+/', $rest, $match) === 1) {
				$position += mb_strlen($match[0]);
				continue;
			}
			$token = self::readToken($rest, $position);
			$tokens[] = $token;
			$position += mb_strlen($token->value) + ($token->type === TokenTypeEnum::String ? 2 : 0);
		}
		$tokens[] = new Token(TokenTypeEnum::End, '', $length);

		return $tokens;
	}

	/** @param string $rest the source from `$position` on */
	private static function readToken(string $rest, int $position): Token
	{
		if (preg_match('/^[0-9]+(\.[0-9]+)?/', $rest, $match) === 1) {
			return new Token(TokenTypeEnum::Number, $match[0], $position);
		}
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', $rest, $match) === 1) {
			return new Token(TokenTypeEnum::Identifier, $match[0], $position);
		}

		$char = mb_substr($rest, 0, 1);
		if ($char === '"' || $char === "'") {
			$end = mb_strpos($rest, $char, 1);
			if ($end === false) {
				throw new ExpressionException(ExpressionErrorEnum::Syntax, sprintf('Unterminated string at %d.', $position));
			}

			return new Token(TokenTypeEnum::String, mb_substr($rest, 1, $end - 1), $position);
		}

		$operator = self::operator($rest);
		if ($operator === null) {
			throw new ExpressionException(ExpressionErrorEnum::Syntax, sprintf('Unexpected "%s" at %d.', $char, $position));
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
