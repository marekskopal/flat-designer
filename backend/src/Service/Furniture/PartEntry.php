<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\Expression;
use FlatDesigner\Service\Furniture\Expression\Node\Values;
use LogicException;

/**
 * One entry of a definition's `parts` (or of an assembly's), read with all its expressions parsed; `resolve()` makes
 * its parts for a scope of variables, the copies of its `repeat` (`PartRepeat`) evaluated per scope. An `assembly`
 * places its `parts` in its own box, where they also see `AW`, `AH`, `AD` (its size).
 * An option written `{"expr": "…"}` is evaluated per copy; a whole number stays whole. So may `material` be, e.g. book
 * spines picking one of several slots by `hash(i)`; it must come out as a slot key.
 *
 * @phpstan-import-type Value from Values
 */
final readonly class PartEntry
{
	/** The keys every part has; the others are options of its type. */
	public const array Keys = ['type', 'name', 'when', 'repeat', 'material', 'x', 'y', 'z', 'w', 'h', 'd'];

	public const string Assembly = 'assembly';

	/** Levels of parts: a top-level entry is at 1, the parts of its assembly at 2, … */
	public const int MaxDepth = 4;

	private const array Geometry = ['x', 'y', 'z', 'w', 'h', 'd'];

	private const array Sizes = ['w' => 'width', 'h' => 'height', 'd' => 'depth'];

	/**
	 * @param array<string, Expression> $geometry
	 * @param list<self> $children the parts of an assembly
	 * @param list<string> $slots the material slot keys, for a `material` expression
	 */
	private function __construct(
		public string $path,
		public string $type,
		public string $name,
		public string|Expression|null $material,
		private ?PartRepeat $repeat,
		private Expression $when,
		private array $geometry,
		private PartOptions $options,
		private array $children,
		private array $slots,
	) {
	}

	/**
	 * Reads `parts[n]` (at `$depth`, 1 for the top level) with the parts of an assembly.
	 *
	 * @param list<string> $slots material slot keys
	 */
	public static function read(mixed $entry, string $path, array $slots, int $depth, PartCollector $collector): self
	{
		if (!is_array($entry) || $entry !== [] && array_is_list($entry)) {
			throw DefinitionException::invalid($path, 'Must be an object.');
		}

		$type = $entry['type'] ?? null;
		if (!is_string($type) || $type === '') {
			throw DefinitionException::invalid($path . '.type', 'Must be a part type.');
		}
		$name = $entry['name'] ?? $type;
		if (!is_string($name)) {
			throw DefinitionException::invalid($path . '.name', 'Must be a string.');
		}
		$material = self::readMaterial($entry['material'] ?? null, $path . '.material', $slots);
		$repeat = array_key_exists('repeat', $entry) ? PartRepeat::read($entry['repeat'], $path . '.repeat') : null;
		$when = Expressions::compile($entry['when'] ?? true, $path . '.when', true);

		$geometry = [];
		foreach (self::Geometry as $key) {
			if (!array_key_exists($key, $entry)) {
				throw DefinitionException::invalid(sprintf('%s.%s', $path, $key), sprintf('Missing "%s".', $key));
			}
			$geometry[$key] = Expressions::compile($entry[$key], sprintf('%s.%s', $path, $key));
		}
		$options = PartOptions::read($entry, $path, $type === self::Assembly, $slots);
		$children = $type === self::Assembly ? self::children($entry['parts'] ?? null, $path . '.parts', $slots, $depth, $collector) : [];

		return new self($path, $type, $name, $material, $repeat, $when, $geometry, $options, $children, $slots);
	}

	/**
	 * Adds the parts of this entry for the variables, its box shifted by the offset (mm) of the assemblies around it.
	 *
	 * @param array<string, Value> $variables
	 * @param array{int, int, int} $offset
	 */
	public function resolve(array $variables, array $offset, PartCollector $collector): void
	{
		$copies = $this->repeat === null ? [[null, $variables]] : $this->repeat->copies($variables);
		foreach ($copies as [$index, $scope]) {
			if (!Expressions::condition($this->when, $scope, $this->path . '.when')) {
				continue;
			}

			$mm = $this->geometry($scope);
			$x = $offset[0] + $mm['x'];
			$y = $offset[1] + $mm['y'];
			$z = $offset[2] + $mm['z'];
			if ($this->type === self::Assembly) {
				$inner = ['AW' => (float) $mm['w'], 'AH' => (float) $mm['h'], 'AD' => (float) $mm['d']] + $scope;
				foreach ($this->children as $child) {
					$child->resolve($inner, [$x, $y, $z], $collector);
				}
				continue;
			}

			$collector->add(new ResolvedPart(
				name: $this->name,
				type: $this->type,
				index: $index,
				x: $x,
				y: $y,
				z: $z,
				w: $mm['w'],
				h: $mm['h'],
				d: $mm['d'],
				material: $this->material($scope),
				props: self::placed($this->options->evaluate($scope), $offset),
				path: $this->path,
			));
		}
	}

	/**
	 * A slot key, or `{"expr": …}` choosing one per copy.
	 *
	 * @param list<string> $slots
	 */
	private static function readMaterial(mixed $material, string $path, array $slots): string|Expression|null
	{
		if (PartOptions::isExpression($material) && is_array($material)) {
			return Expressions::compile($material['expr'], $path);
		}
		if ($material !== null && (!is_string($material) || !in_array($material, $slots, true))) {
			throw DefinitionException::invalid($path, 'Must be the key of one of the materialSlots.');
		}

		return $material;
	}

	/** @param array<string, Value> $scope */
	private function material(array $scope): ?string
	{
		if (!$this->material instanceof Expression) {
			return $this->material;
		}
		$path = $this->path . '.material';
		$key = Expressions::run($this->material, $scope, $path);
		if (!is_string($key) || !in_array($key, $this->slots, true)) {
			throw DefinitionException::invalid($path, 'Must come out as the key of one of the materialSlots.');
		}

		return $key;
	}

	/**
	 * The tilt pivot (`tiltY`, `tiltZ`), written next to the part's `y` / `z`, moved into the item's frame like them.
	 *
	 * @param array<string, mixed> $props
	 * @param array{int, int, int} $offset
	 * @return array<string, mixed>
	 */
	private static function placed(array $props, array $offset): array
	{
		foreach (['tiltY' => 1, 'tiltZ' => 2] as $key => $axis) {
			if (is_int($props[$key] ?? null) || is_float($props[$key] ?? null)) {
				$props[$key] += $offset[$axis];
			}
		}

		return $props;
	}

	/**
	 * @param array<string, Value> $scope
	 * @return array{x: int, y: int, z: int, w: int, h: int, d: int}
	 */
	private function geometry(array $scope): array
	{
		$mm = [];
		foreach (self::Geometry as $key) {
			$path = sprintf('%s.%s', $this->path, $key);
			$mm[$key] = Expressions::length($this->geometry[$key] ?? throw new LogicException('Geometry not read.'), $scope, $path);
			if (isset(self::Sizes[$key]) && $mm[$key] <= 0) {
				throw new DefinitionException(
					DefinitionErrorEnum::NonPositiveSize,
					$path,
					sprintf('"%s" gets a %s of %d mm.', $this->name, self::Sizes[$key], $mm[$key]),
				);
			}
		}

		return ['x' => $mm['x'], 'y' => $mm['y'], 'z' => $mm['z'], 'w' => $mm['w'], 'h' => $mm['h'], 'd' => $mm['d']];
	}

	/**
	 * @param list<string> $slots
	 * @return list<self>
	 */
	private static function children(mixed $entries, string $path, array $slots, int $depth, PartCollector $collector): array
	{
		if (!is_array($entries) || !array_is_list($entries)) {
			throw DefinitionException::invalid($path, 'An assembly needs a list of parts.');
		}
		if ($depth >= self::MaxDepth) {
			throw new DefinitionException(DefinitionErrorEnum::Limit, $path, sprintf('Parts nest at most %d levels deep.', self::MaxDepth));
		}

		$children = [];
		foreach ($entries as $index => $entry) {
			$collector->entry($path);
			$children[] = self::read($entry, sprintf('%s[%d]', $path, $index), $slots, $depth + 1, $collector);
		}

		return $children;
	}
}
