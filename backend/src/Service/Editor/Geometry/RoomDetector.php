<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use FlatDesigner\Service\Editor\Element\State;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\PolygonValidator;

/**
 * The outline of the area enclosed by walls around a point: the inner faces of the walls (mitred corners
 * included), as a counter-clockwise polygon in whole mm starting at its lowest (then leftmost) vertex.
 *
 * The wall footprints are cut into a planar graph at every crossing, dangling edges are pruned
 * (`FaceGraph`), and the smallest bounded face containing the point is taken. Openings do not matter: a
 * wall is continuous. Twin of `detectRoom()` in `viewer/engine/room-detect.ts`; the shared fixture
 * `tests/fixtures/room-detection.json` keeps them identical, so change both with a fixture case.
 *
 * @phpstan-import-type Point from PlanMath
 */
final readonly class RoomDetector
{
	/**
	 * @param list<WallLine> $walls
	 * @return list<array{int, int}>|RoomDetectErrorEnum
	 */
	public static function detect(array $walls, float $x, float $y): array|RoomDetectErrorEnum
	{
		$point = [$x, $y];
		$footprints = self::footprints($walls);
		$segments = [];
		foreach ($footprints as $footprint) {
			if (PlanMath::contains($footprint, $point)) {
				return RoomDetectErrorEnum::OnWall;
			}
			foreach ($footprint as $i => $corner) {
				$segments[] = [$corner, $footprint[($i + 1) % count($footprint)]];
			}
		}

		$best = null;
		$bestArea = null;
		foreach ((new FaceGraph($segments))->faces() as $face) {
			$area = PlanMath::signedArea($face);
			if ($area <= 0 || $bestArea !== null && $area >= $bestArea || !PlanMath::contains($face, $point)) {
				continue;
			}
			$best = $face;
			$bestArea = $area;
		}
		if ($best === null) {
			return RoomDetectErrorEnum::Open;
		}

		try {
			return PolygonValidator::validate(OutlineCleaner::clean($best), State::CoordinateLimit);
		} catch (EditException) {
			return RoomDetectErrorEnum::Invalid;
		}
	}

	/**
	 * Footprint of every wall with a length (`WallLine::outline()`): right face start → end, then left face end → start.
	 *
	 * @param list<WallLine> $walls
	 * @return list<list<Point>>
	 */
	private static function footprints(array $walls): array
	{
		$joints = WallJoints::of($walls);
		$footprints = [];
		foreach ($walls as $wall) {
			if ($wall->startX === $wall->endX && $wall->startY === $wall->endY) {
				continue;
			}
			$footprints[] = $wall->outline($joints[$wall->id] ?? WallJoints::Square);
		}

		return $footprints;
	}
}
