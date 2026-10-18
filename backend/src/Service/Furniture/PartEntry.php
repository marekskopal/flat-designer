<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\Expression;

/** One entry of a definition's `parts`, read and with its expressions parsed; `resolve()` makes one part of it. */
final readonly class PartEntry
{
	private const array Keys = ['type', 'name', 'when', 'repeat', 'material', 'x', 'y', 'z', 'w', 'h', 'd'];

	private const array Geometry = ['x', 'y', 'z', 'w', 'h', 'd'];

	private const array Sizes = ['w' => 'width', 'h' => 'height', 'd' => 'depth'];

	/**
	 * @param string|null $indexName the repeat index variable, null without `repeat`
	 * @param int|null $count the number of copies, null without `repeat`
	 * @param array<string, Expression> $geometry
	 * @param array<string, mixed> $props
	 */
	private function __construct(
		public string $path,
		public string $type,
		public string $name,
		public ?string $material,
		public ?string $indexName,
		public ?int $count,
		private Expression $when,
		private array $geometry,
		private array $props,
	) {
	}

	/**
	 * Reads `parts[n]`; `repeat.count` is evaluated here, the rest per copy.
	 *
	 * @param array<string, float|bool|string> $variables
	 * @param list<string> $slots material slot keys
	 */
	public static function read(mixed $entry, string $path, array $variables, array $slots): self
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
		$material = $entry['material'] ?? null;
		if ($material !== null && !in_array($material, $slots, true)) {
			throw DefinitionException::invalid($path . '.material', 'Must be the key of one of the materialSlots.');
		}
		[$indexName, $count] = array_key_exists('repeat', $entry)
			? self::repeat($entry['repeat'], $path . '.repeat', $variables)
			: [null, null];
		$when = Expressions::compile($entry['when'] ?? true, $path . '.when', true);

		$geometry = [];
		foreach (self::Geometry as $key) {
			if (!array_key_exists($key, $entry)) {
				throw DefinitionException::invalid(sprintf('%s.%s', $path, $key), sprintf('Missing "%s".', $key));
			}
			$geometry[$key] = Expressions::compile($entry[$key], sprintf('%s.%s', $path, $key));
		}

		return new self($path, $type, $name, $material, $indexName, $count, $when, $geometry, self::props($entry));
	}

	/**
	 * The copy `$index` (null without `repeat`), or null when its `when` is false.
	 *
	 * @param array<string, float|bool|string> $variables
	 */
	public function resolve(array $variables, ?int $index): ?ResolvedPart
	{
		if ($this->indexName !== null && $index !== null) {
			$variables[$this->indexName] = (float) $index;
		}
		if (!Expressions::condition($this->when, $variables, $this->path . '.when')) {
			return null;
		}

		$mm = [];
		foreach ($this->geometry as $key => $expression) {
			$path = sprintf('%s.%s', $this->path, $key);
			$mm[$key] = Expressions::length($expression, $variables, $path);
			if (isset(self::Sizes[$key]) && $mm[$key] <= 0) {
				throw new DefinitionException(
					DefinitionErrorEnum::NonPositiveSize,
					$path,
					sprintf('"%s" gets a %s of %d mm.', $this->name, self::Sizes[$key], $mm[$key]),
				);
			}
		}

		return new ResolvedPart(
			name: $this->name,
			type: $this->type,
			index: $index,
			x: $mm['x'] ?? 0,
			y: $mm['y'] ?? 0,
			z: $mm['z'] ?? 0,
			w: $mm['w'] ?? 0,
			h: $mm['h'] ?? 0,
			d: $mm['d'] ?? 0,
			material: $this->material,
			props: $this->props,
		);
	}

	/**
	 * The keys of a part type (grain, edges, handle, …): all but the ones read here.
	 *
	 * @param array<mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function props(array $entry): array
	{
		$props = [];
		foreach ($entry as $key => $value) {
			if (is_string($key) && !in_array($key, self::Keys, true)) {
				$props[$key] = $value;
			}
		}

		return $props;
	}

	/**
	 * @param array<string, float|bool|string> $variables
	 * @return array{string, int}
	 */
	private static function repeat(mixed $repeat, string $path, array $variables): array
	{
		if (!is_array($repeat) || !array_key_exists('count', $repeat)) {
			throw DefinitionException::invalid($path, 'Must be an object with "count".');
		}
		$as = Names::free($repeat['as'] ?? 'i', $variables);
		if ($as === null) {
			throw DefinitionException::invalid($path . '.as', 'Must be a name (letters, digits, _) not used by another variable.');
		}

		$count = Expressions::run(Expressions::compile($repeat['count'], $path . '.count'), $variables, $path . '.count');
		if (!DefinitionParameter::isWholeNumber($count) || $count < 0) {
			throw DefinitionException::invalid($path . '.count', 'Must come out a whole number, 0 or more.');
		}
		if ($count > DefinitionEvaluator::MaxRepeat) {
			throw new DefinitionException(
				DefinitionErrorEnum::Limit,
				$path . '.count',
				sprintf('At most %d copies.', DefinitionEvaluator::MaxRepeat),
			);
		}

		return [$as, (int) $count];
	}
}
