<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\Expression;
use FlatDesigner\Service\Furniture\Expression\Node\Values;

/**
 * The options of a part (grain, edges, handle, …: the keys besides `PartEntry::Keys`), literal or written as
 * `{"expr": "…"}`, which is evaluated per copy and then checked like a literal (a known part type's options); a whole
 * number result stays whole (8, not 8.0). The TS twin evaluates without checking (its builders tolerate odd values), so
 * the fixture has no case for a bad evaluated option.
 *
 * @phpstan-import-type Value from Values
 */
final readonly class PartOptions
{
	/** @param array<string, mixed> $options literal values, or `Expression` for `{"expr": …}` ones */
	private function __construct(private string $path, private ?PartTypeEnum $type, private array $options)
	{
	}

	/** @param array<mixed> $entry */
	public static function read(array $entry, string $path, bool $assembly): self
	{
		$type = is_string($entry['type'] ?? null) ? PartTypeEnum::tryFrom($entry['type']) : null;
		$options = [];
		foreach ($entry as $key => $value) {
			if (!is_string($key) || in_array($key, PartEntry::Keys, true) || $assembly && $key === 'parts') {
				continue;
			}
			$options[$key] = self::isExpression($value) && is_array($value)
				? Expressions::compile($value['expr'], sprintf('%s.%s', $path, $key))
				: $value;
		}

		return new self($path, $type, $options);
	}

	/** Whether the value is an option written as an expression: `{"expr": "…"}`. */
	public static function isExpression(mixed $value): bool
	{
		return is_array($value) && array_keys($value) === ['expr'];
	}

	/**
	 * @param array<string, Value> $scope
	 * @return array<string, mixed>
	 */
	public function evaluate(array $scope): array
	{
		$options = [];
		foreach ($this->options as $key => $value) {
			if (!$value instanceof Expression) {
				$options[$key] = $value;
				continue;
			}
			$path = sprintf('%s.%s', $this->path, $key);
			$evaluated = self::plain(Values::toJson(Expressions::run($value, $scope, $path)));
			$error = $this->type?->checkOption($key, $evaluated);
			if ($error !== null) {
				throw DefinitionException::invalid($path, $error);
			}
			$options[$key] = $evaluated;
		}

		return $options;
	}

	private static function plain(mixed $value): mixed
	{
		return is_float($value) && floor($value) === $value && abs($value) <= 2 ** 53 ? (int) $value : $value;
	}
}
