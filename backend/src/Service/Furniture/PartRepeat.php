<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\Expression;
use FlatDesigner\Service\Furniture\Expression\ExpressionErrorEnum;
use FlatDesigner\Service\Furniture\Expression\Node\Values;

/**
 * The `repeat` of a part entry: `{count, as?}` gives the copy's index as `as` (default `i`), `{over, as?}` the list item
 * as `as` (default `item`) and its index as `<as>_index`. Evaluated per scope, so a nested part repeats by its
 * parent's item (e.g. the leaves of a front slot).
 *
 * @phpstan-import-type Value from Values
 */
final readonly class PartRepeat
{
	private function __construct(private string $path, private bool $over, private Expression $expression, private string $as)
	{
	}

	public static function read(mixed $repeat, string $path): self
	{
		$count = is_array($repeat) && array_key_exists('count', $repeat);
		$over = is_array($repeat) && array_key_exists('over', $repeat);
		if (!is_array($repeat) || $count === $over) {
			throw DefinitionException::invalid($path, 'Must be an object with "count" or "over" (not both).');
		}
		$as = Names::free($repeat['as'] ?? ($count ? 'i' : 'item'), []);
		if ($as === null) {
			throw DefinitionException::invalid($path . '.as', 'Must be a name (letters, digits, _) not used by another variable.');
		}
		$key = $count ? 'count' : 'over';

		return new self($path, $over, Expressions::compile($repeat[$key], sprintf('%s.%s', $path, $key)), $as);
	}

	/**
	 * The copies: the index and the variables of each.
	 *
	 * @param array<string, Value> $variables
	 * @return list<array{int, array<string, Value>}>
	 */
	public function copies(array $variables): array
	{
		if (Names::free($this->as, $variables) === null || $this->over && Names::free($this->as . '_index', $variables) === null) {
			throw DefinitionException::invalid($this->path . '.as', 'Must be a name (letters, digits, _) not used by another variable.');
		}

		$copies = [];
		if ($this->over) {
			foreach ($this->items($variables) as $index => $item) {
				$scope = $variables;
				$scope[$this->as] = $item;
				$scope[$this->as . '_index'] = (float) $index;
				$copies[] = [$index, $scope];
			}
		} else {
			for ($index = 0; $index < $this->count($variables); $index++) {
				$scope = $variables;
				$scope[$this->as] = (float) $index;
				$copies[] = [$index, $scope];
			}
		}

		return $copies;
	}

	/**
	 * @param array<string, Value> $variables
	 * @return list<Value>
	 */
	private function items(array $variables): array
	{
		$path = $this->path . '.over';
		$list = Expressions::run($this->expression, $variables, $path);
		if (!is_array($list)) {
			throw new DefinitionException(
				$list === null ? ExpressionErrorEnum::Null : ExpressionErrorEnum::Type,
				$path,
				sprintf('Must come out a list, got %s.', Values::typeName($list)),
			);
		}
		self::checkCopies(count($list), $path);
		/** @var list<Value> $items the items of a list value are values */
		$items = $list;

		return $items;
	}

	/** @param array<string, Value> $variables */
	private function count(array $variables): int
	{
		$path = $this->path . '.count';
		$count = Expressions::run($this->expression, $variables, $path);
		if (!DefinitionParameter::isWholeNumber($count) || $count < 0) {
			throw DefinitionException::invalid($path, 'Must come out a whole number, 0 or more.');
		}
		self::checkCopies((int) $count, $path);

		return (int) $count;
	}

	private static function checkCopies(int $count, string $path): void
	{
		if ($count > DefinitionEvaluator::MaxRepeat) {
			throw new DefinitionException(DefinitionErrorEnum::Limit, $path, sprintf('At most %d copies.', DefinitionEvaluator::MaxRepeat));
		}
	}
}
