<?php

declare(strict_types=1);

namespace Izbik\Tests\Support;

use function array_map;
use function count;
use function implode;

/** Writes small ASCII DXF drawings for the reader's and the planner's tests: entities as group code / value pairs. */
final class Dxf
{
	/**
	 * @param list<list<array{int, string|int|float}>> $entities
	 * @param array<string, list<list<array{int, string|int|float}>>> $blocks entities by block name, base point 0, 0
	 * @param array<string, int> $layers flags by layer name (1: frozen)
	 */
	public static function document(
		array $entities,
		array $blocks = [],
		?int $units = 4,
		array $layers = [],
		string $newline = "\n",
	): string
	{
		$pairs = [];
		$pairs[] = [0, 'SECTION'];
		$pairs[] = [2, 'HEADER'];
		$pairs[] = [9, '$ACADVER'];
		$pairs[] = [1, 'AC1015'];
		if ($units !== null) {
			$pairs[] = [9, '$INSUNITS'];
			$pairs[] = [70, $units];
		}
		$pairs[] = [0, 'ENDSEC'];
		$pairs[] = [0, 'SECTION'];
		$pairs[] = [2, 'TABLES'];
		$pairs[] = [0, 'TABLE'];
		$pairs[] = [2, 'LAYER'];
		$pairs[] = [70, count($layers)];
		foreach ($layers as $name => $flags) {
			$pairs[] = [0, 'LAYER'];
			$pairs[] = [2, $name];
			$pairs[] = [70, $flags];
			$pairs[] = [62, 7];
		}
		$pairs[] = [0, 'ENDTAB'];
		$pairs[] = [0, 'ENDSEC'];
		$pairs[] = [0, 'SECTION'];
		$pairs[] = [2, 'BLOCKS'];
		foreach ($blocks as $name => $blockEntities) {
			$pairs[] = [0, 'BLOCK'];
			$pairs[] = [8, '0'];
			$pairs[] = [2, $name];
			$pairs[] = [70, 0];
			$pairs[] = [10, 0];
			$pairs[] = [20, 0];
			foreach ($blockEntities as $entity) {
				foreach ($entity as $pair) {
					$pairs[] = $pair;
				}
			}
			$pairs[] = [0, 'ENDBLK'];
		}
		$pairs[] = [0, 'ENDSEC'];
		$pairs[] = [0, 'SECTION'];
		$pairs[] = [2, 'ENTITIES'];
		foreach ($entities as $entity) {
			foreach ($entity as $pair) {
				$pairs[] = $pair;
			}
		}
		$pairs[] = [0, 'ENDSEC'];
		$pairs[] = [0, 'EOF'];

		return implode($newline, array_map(static fn (array $pair): string => '  ' . $pair[0] . $newline . $pair[1], $pairs)) . $newline;
	}

	/** @return list<array{int, string|int|float}> */
	public static function line(string $layer, float $x1, float $y1, float $x2, float $y2): array
	{
		return [[0, 'LINE'], [8, $layer], [10, $x1], [20, $y1], [30, 0], [11, $x2], [21, $y2], [31, 0]];
	}

	/**
	 * @param list<array{float, float}|array{float, float, float}> $points x, y and an optional bulge to the next point
	 * @param array{float, float, float}|null $extrusion
	 * @return list<array{int, string|int|float}>
	 */
	public static function polyline(string $layer, array $points, bool $closed = false, ?array $extrusion = null): array
	{
		$pairs = [[0, 'LWPOLYLINE'], [8, $layer], [90, count($points)], [70, $closed ? 1 : 0]];
		foreach ($points as $point) {
			$pairs[] = [10, $point[0]];
			$pairs[] = [20, $point[1]];
			if (isset($point[2])) {
				$pairs[] = [42, $point[2]];
			}
		}
		if ($extrusion !== null) {
			$pairs[] = [210, $extrusion[0]];
			$pairs[] = [220, $extrusion[1]];
			$pairs[] = [230, $extrusion[2]];
		}

		return $pairs;
	}

	/**
	 * A closed rectangle from (x1, y1) to (x2, y2).
	 *
	 * @return list<array{int, string|int|float}>
	 */
	public static function rectangle(string $layer, float $x1, float $y1, float $x2, float $y2): array
	{
		return self::polyline($layer, [[$x1, $y1], [$x2, $y1], [$x2, $y2], [$x1, $y2]], true);
	}

	/**
	 * @param array{float, float, float}|null $extrusion
	 * @return list<array{int, string|int|float}>
	 */
	public static function arc(
		string $layer,
		float $cx,
		float $cy,
		float $radius,
		float $start,
		float $end,
		?array $extrusion = null,
	): array
	{
		$pairs = [[0, 'ARC'], [8, $layer], [10, $cx], [20, $cy], [30, 0], [40, $radius], [50, $start], [51, $end]];
		if ($extrusion !== null) {
			$pairs[] = [210, $extrusion[0]];
			$pairs[] = [220, $extrusion[1]];
			$pairs[] = [230, $extrusion[2]];
		}

		return $pairs;
	}

	/** @return list<array{int, string|int|float}> */
	public static function insert(
		string $layer,
		string $block,
		float $x,
		float $y,
		float $scaleX = 1.0,
		float $scaleY = 1.0,
		float $rotation = 0.0,
	): array
	{
		return [[0, 'INSERT'], [8, $layer], [2, $block], [10, $x], [20, $y], [30, 0], [41, $scaleX], [42, $scaleY], [50, $rotation]];
	}

	/** @return list<array{int, string|int|float}> */
	public static function text(string $layer, float $x, float $y, string $text): array
	{
		return [[0, 'TEXT'], [8, $layer], [10, $x], [20, $y], [40, 250], [1, $text]];
	}

	/**
	 * Two parallel lines `thickness` apart along the centre line (x1, y1) → (x2, y2): a wall drawn as its faces.
	 *
	 * @return list<list<array{int, string|int|float}>>
	 */
	public static function wallFaces(string $layer, float $x1, float $y1, float $x2, float $y2, float $thickness): array
	{
		$length = hypot($x2 - $x1, $y2 - $y1);
		$nx = -($y2 - $y1) / $length * $thickness / 2;
		$ny = ($x2 - $x1) / $length * $thickness / 2;

		return [
			self::line($layer, $x1 + $nx, $y1 + $ny, $x2 + $nx, $y2 + $ny),
			self::line($layer, $x1 - $nx, $y1 - $ny, $x2 - $nx, $y2 - $ny),
		];
	}
}
