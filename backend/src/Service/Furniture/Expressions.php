<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\Expression;
use FlatDesigner\Service\Furniture\Expression\ExpressionErrorEnum;
use FlatDesigner\Service\Furniture\Expression\ExpressionException;
use FlatDesigner\Service\Furniture\Expression\Node\LiteralNode;
use FlatDesigner\Service\Furniture\Expression\Node\Values;
use FlatDesigner\Service\Furniture\Expression\Parser;

/** Expressions inside a definition: parsing and evaluating them with errors pointing at their path. */
final readonly class Expressions
{
	/** A number or expression (and, with `$booleans`, true / false) as an `Expression`. */
	public static function compile(mixed $source, string $path, bool $booleans = false): Expression
	{
		if (is_int($source) || is_float($source) || $booleans && is_bool($source)) {
			return new Expression(var_export($source, true), new LiteralNode(is_bool($source) ? $source : (float) $source));
		}
		if (!is_string($source)) {
			throw DefinitionException::invalid(
				$path,
				$booleans ? 'Must be an expression, true or false.' : 'Must be a number or an expression.',
			);
		}

		try {
			return Parser::parse($source);
		} catch (ExpressionException $exception) {
			throw DefinitionException::fromExpression($path, $exception);
		}
	}

	/** @param array<string, float|bool|string> $variables */
	public static function run(Expression $expression, array $variables, string $path): float|bool|string
	{
		try {
			return $expression->evaluate($variables);
		} catch (ExpressionException $exception) {
			throw DefinitionException::fromExpression($path, $exception);
		}
	}

	/** @param array<string, float|bool|string> $variables */
	public static function condition(Expression $expression, array $variables, string $path): bool
	{
		$value = self::run($expression, $variables, $path);
		if (!is_bool($value)) {
			throw self::typeError($path, 'true or false', $value);
		}

		return $value;
	}

	/** Lengths beyond this (mm) are a mistake, and PHP ints would overflow further on. */
	public const int MaxLength = 1000000000;

	/** @param array<string, float|bool|string> $variables the result rounded half away from zero to whole mm */
	public static function length(Expression $expression, array $variables, string $path): int
	{
		$value = self::run($expression, $variables, $path);
		if (!is_float($value)) {
			throw self::typeError($path, 'a number (mm)', $value);
		}
		if (abs($value) > self::MaxLength) {
			throw new DefinitionException(DefinitionErrorEnum::Limit, $path, sprintf('Must come out within ±%d mm.', self::MaxLength));
		}

		return (int) round($value);
	}

	private static function typeError(string $path, string $expected, float|bool|string $value): DefinitionException
	{
		return new DefinitionException(
			ExpressionErrorEnum::Type,
			$path,
			sprintf('Must come out %s, got %s.', $expected, Values::typeName($value)),
		);
	}
}
