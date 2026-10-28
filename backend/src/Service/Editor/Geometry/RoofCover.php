<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * Roof junctions (FD-38): the parts in plan of the planes of a floor's roofs where each is the top, its underside the
 * highest of the roofs over the point (as `RoofGeometry::undersideOf()` takes it), so roofs meeting form valleys. They
 * cover the roofs' outlines once, each a convex piece with its plane's underside, for the measures under and on the
 * roofs. Twin of `roofPlaneParts()` (`viewer/engine/under-roof.ts`), which draws them, kept alike by the junction cases
 * of `tests/fixtures/roof-geometry.json`.
 *
 * @phpstan-import-type Point from PlanMath
 * @phpstan-type CoverPiece array{roof: int, plane: int, polygon: list<Point>, k: float, gx: float, gy: float}
 */
final readonly class RoofCover
{
	/** mm: parallel planes (two roofs alike) closer than this are one, and the earlier roof's is the top (`Same` in TS). */
	private const float Same = 0.5;

	/** @var list<CoverPiece> */
	public array $pieces;

	/** @param list<RoofGeometry> $roofs */
	public function __construct(public array $roofs)
	{
		$planes = self::planes($roofs);
		$pieces = [];
		foreach ($planes as $plane) {
			foreach (self::top($plane, $planes) as $part) {
				$pieces[] = ['polygon' => $part] + $plane;
			}
		}
		$this->pieces = $pieces;
	}

	/**
	 * Every plane of the roofs, with its underside k + gx·x + gy·y.
	 *
	 * @param list<RoofGeometry> $roofs
	 * @return list<CoverPiece>
	 */
	private static function planes(array $roofs): array
	{
		$planes = [];
		foreach ($roofs as $r => $roof) {
			foreach ($roof->planes() as $k => $plane) {
				[$gx, $gy] = $plane['gradient'];
				$planes[] = [
					'roof' => $r,
					'plane' => $k,
					'polygon' => $plane['polygon'],
					'k' => $plane['height'] - $gx * $roof->x - $gy * $roof->y,
					'gx' => $gx,
					'gy' => $gy,
				];
			}
		}

		return $planes;
	}

	/**
	 * The convex parts of a plane where no other roof's plane is higher.
	 *
	 * @param CoverPiece $plane
	 * @param list<CoverPiece> $planes
	 * @return list<list<Point>>
	 */
	private static function top(array $plane, array $planes): array
	{
		$parts = [$plane['polygon']];
		foreach ($planes as $other) {
			if ($other['roof'] === $plane['roof']) {
				continue;
			}
			// Where the other plane is higher: other − own > 0, exactly where the planes cross, so the parts meet.
			$a = $plane['gx'] - $other['gx'];
			$b = $plane['gy'] - $other['gy'];
			$tie = abs($a) + abs($b) < 1e-9 ? ($other['roof'] < $plane['roof'] ? self::Same : -self::Same) : 0.0;
			$parts = ConvexClip::minus($parts, [...ConvexClip::inside($other['polygon']), [$a, $b, $other['k'] - $plane['k'] + $tie]]);
		}

		return $parts;
	}

	/**
	 * The underside of a piece at a plan point.
	 *
	 * @param CoverPiece $piece
	 */
	public static function heightAt(array $piece, float $x, float $y): float
	{
		return $piece['k'] + $piece['gx'] * $x + $piece['gy'] * $y;
	}

	/** Plan area (mm²) of a roof's plane where it is the top. */
	public function planeArea(int $roof, int $plane): float
	{
		$area = 0.0;
		foreach ($this->pieces as $piece) {
			if ($piece['roof'] === $roof && $piece['plane'] === $plane) {
				$area += PlanMath::signedArea($piece['polygon']);
			}
		}

		return $area;
	}
}
