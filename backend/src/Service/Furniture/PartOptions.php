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
	/**
	 * @param array<string, mixed> $options literal values, or `Expression` for `{"expr": …}` ones
	 * @param list<string> $slots the material slot keys, for options naming one
	 */
	private function __construct(private string $path, private ?PartTypeEnum $type, private array $options, private array $slots)
	{
	}

	/**
	 * @param array<mixed> $entry
	 * @param list<string> $slots
	 */
	public static function read(array $entry, string $path, bool $assembly, array $slots): self
	{
		$type = is_string($entry['type'] ?? null) ? PartTypeEnum::tryFrom($entry['type']) : null;
		$options = [];
		foreach ($entry as $key => $value) {
			if (!is_string($key) || in_array($key, PartEntry::Keys, true) || $assembly && $key === 'parts') {
				continue;
			}
			if (self::isExpression($value) && is_array($value)) {
				$options[$key] = Expressions::compile($value['expr'], sprintf('%s.%s', $path, $key));
				continue;
			}
			self::checkSlot($type, $key, $value, $slots, sprintf('%s.%s', $path, $key));
			$options[$key] = $value;
		}

		return new self($path, $type, $options, $slots);
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
			self::checkSlot($this->type, $key, $evaluated, $this->slots, $path);
			$options[$key] = $evaluated;
		}

		return $options;
	}

	/**
	 * An option naming a material slot (`profile`) must name one of the definition's.
	 *
	 * @param list<string> $slots
	 */
	private static function checkSlot(?PartTypeEnum $type, string $key, mixed $value, array $slots, string $path): void
	{
		if ($type?->isSlotOption($key) === true && !in_array($value, $slots, true)) {
			throw DefinitionException::invalid($path, 'Must be the key of one of the materialSlots.');
		}
	}

	private static function plain(mixed $value): mixed
	{
		return is_float($value) && floor($value) === $value && abs($value) <= 2 ** 53 ? (int) $value : $value;
	}
}
