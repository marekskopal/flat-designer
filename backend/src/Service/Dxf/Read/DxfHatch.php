<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Read;

use Izbik\Service\Dxf\DxfArc;
use Izbik\Service\Dxf\DxfLayer;
use Izbik\Service\Dxf\DxfTransform;
use function deg2rad;

/**
 * A hatch's boundary paths as lines and arcs: polyline paths, and paths of line and arc edges. A path with an ellipse
 * or spline edge ends the boundary there: what follows cannot be found reliably.
 *
 * @phpstan-import-type Pair from DxfTokenizer
 */
final class DxfHatch
{
	private const int PolylinePath = 2;

	private const int LineEdge = 1;

	private const int ArcEdge = 2;

	/** @param list<Pair> $pairs */
	public static function emit(DxfGeometry $geometry, DxfLayer $layer, DxfTransform $transform, array $pairs, ?string $block): void
	{
		$layer->hatches++;
		$at = DxfPairs::indexOf($pairs, 91, 0);
		if ($at === null) {
			return;
		}
		$paths = (int) $pairs[$at][1];
		for ($path = 0; $path < $paths; $path++) {
			$flags = DxfPairs::scan($pairs, 92, $at);
			if ($flags === null) {
				return;
			}
			$complete = ((int) $flags & self::PolylinePath) !== 0
				? self::polylinePath($geometry, $layer, $transform, $pairs, $at, $block)
				: self::edgePath($geometry, $layer, $transform, $pairs, $at, $block);
			if (!$complete) {
				return;
			}
		}
	}

	/** @param list<Pair> $pairs */
	private static function polylinePath(
		DxfGeometry $geometry,
		DxfLayer $layer,
		DxfTransform $transform,
		array $pairs,
		int &$at,
		?string $block,
	): bool {
		$hasBulge = (int) (DxfPairs::scan($pairs, 72, $at) ?? 0) !== 0;
		$closed = (int) (DxfPairs::scan($pairs, 73, $at) ?? 1) !== 0;
		$count = (int) (DxfPairs::scan($pairs, 93, $at) ?? 0);
		$vertices = [];
		for ($i = 0; $i < $count; $i++) {
			$point = DxfPairs::scanAll($pairs, [10, 20], $at);
			if ($point === null) {
				return false;
			}
			$bulge = $hasBulge ? DxfPairs::scan($pairs, 42, $at) ?? 0.0 : 0.0;
			$vertices[] = [$point[0], $point[1], $bulge];
		}
		$geometry->polyline($layer, $transform, $vertices, $closed, $block);

		return true;
	}

	/** @param list<Pair> $pairs */
	private static function edgePath(
		DxfGeometry $geometry,
		DxfLayer $layer,
		DxfTransform $transform,
		array $pairs,
		int &$at,
		?string $block,
	): bool {
		$edges = (int) (DxfPairs::scan($pairs, 93, $at) ?? 0);
		for ($edge = 0; $edge < $edges; $edge++) {
			$type = (int) (DxfPairs::scan($pairs, 72, $at) ?? 0);
			if ($type === self::LineEdge) {
				$line = DxfPairs::scanAll($pairs, [10, 20, 11, 21], $at);
				if ($line === null) {
					return false;
				}
				$geometry->segment($layer, $transform, $line[0], $line[1], $line[2], $line[3], $block);
				continue;
			}
			$arc = $type === self::ArcEdge ? DxfPairs::scanAll($pairs, [10, 20, 40, 50, 51, 73], $at) : null;
			if ($arc === null) {
				return false;
			}
			// A clockwise edge gives its angles measured clockwise.
			[$from, $to] = $arc[5] !== 0.0 ? [$arc[3], $arc[4]] : [-$arc[4], -$arc[3]];
			$geometry->arc($layer, $transform, new DxfArc($layer->name, $arc[0], $arc[1], $arc[2], deg2rad($from), deg2rad($to), $block));
		}

		return true;
	}
}
