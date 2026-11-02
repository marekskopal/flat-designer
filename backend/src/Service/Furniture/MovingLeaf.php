<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture;

/**
 * An assembly whose `opens` is not `none` moves as one leaf, e.g. a drawer front with its drawer box: each of
 * its parts gets `props.leaf`, `{key, box, …the assembly's options}` (the box in the item's frame), and none of them may
 * open on its own (an `opens` other than `none`). The TS twin is in frontend/src/app/viewer/engine/custom/definition.ts
 * (`leafKey()`, `opensAlone()`).
 */
final readonly class MovingLeaf
{
	/**
	 * The key of an assembly's copy, unique in the item and alike in both twins: the last segment of each entry's path
	 * (`parts[3]`) with its copy index (`#2`) when repeated, joined by dots from the top, e.g. `parts[5]#2.parts[1]`.
	 */
	public static function key(string $trail, string $path, ?int $index): string
	{
		$dot = strrpos($path, '.');
		$segment = ($dot === false ? $path : substr($path, $dot + 1)) . ($index === null ? '' : '#' . $index);

		return $trail === '' ? $segment : $trail . '.' . $segment;
	}

	/**
	 * The leaf an assembly's parts move with: its own when it opens, else the one around it.
	 *
	 * @param list<int> $box
	 * @param array<string, mixed> $options the assembly's evaluated options
	 * @param array<string, mixed>|null $outer
	 * @return array<string, mixed>|null
	 */
	public static function of(string $key, array $box, array $options, ?array $outer, string $path): ?array
	{
		return self::opensAlone($options, $outer, $path) && is_string($options['opens'] ?? null)
			? ['key' => $key, 'box' => $box] + $options
			: $outer;
	}

	/**
	 * A part's props with the leaf it moves with.
	 *
	 * @param array<string, mixed> $props
	 * @param array<string, mixed>|null $leaf
	 * @return array<string, mixed>
	 */
	public static function attach(array $props, ?array $leaf, string $path): array
	{
		self::opensAlone($props, $leaf, $path);
		if ($leaf !== null) {
			$props['leaf'] = $leaf;
		}

		return $props;
	}

	/**
	 * Whether the part's evaluated `opens` makes it open (not `none`); refused inside an opening assembly.
	 *
	 * @param array<string, mixed> $options
	 * @param array<string, mixed>|null $leaf
	 */
	private static function opensAlone(array $options, ?array $leaf, string $path): bool
	{
		$opens = ($options['opens'] ?? null) !== null && $options['opens'] !== 'none';
		if ($opens && $leaf !== null) {
			throw DefinitionException::invalid($path . '.opens', 'A part of an opening assembly moves with it; it cannot open on its own.');
		}

		return $opens;
	}
}
