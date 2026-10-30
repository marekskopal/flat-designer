<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use FlatDesigner\Dto\FloorVoidDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Editor\Geometry\RoofCover;
use FlatDesigner\Service\Editor\Geometry\RoomUnderRoof;
use FlatDesigner\Service\Editor\Geometry\Spans;
use FlatDesigner\Service\Editor\Geometry\WallFaceSpans;
use FlatDesigner\Service\Schedule\VoidArea;
use const M_PI;

/**
 * The surfaces of one room as the viewer draws them, in mm² (or mm for skirting), per surface and material:
 *
 * - the floor in the floor material, less the floor's voids (FD-90);
 * - wall finish bands along the wall faces on the room's edges (`WallFaceSpans`), up to the wall's top, without openings:
 *   the face's own bands (`WallDto::finishesOn()`) or else the room's;
 * - the rest of those wall faces up to the ceiling in the wall's material (paint), without bands and openings; open-air
 *   rooms have no ceiling and paint none (their walls are façades);
 * - the ceiling, on a vault its arched surface, and the beams' underside and sides; a flat one less the voids of the floor
 *   above, a sloped one scaled by the share of the floor they leave;
 * - skirting along the walls, except doorways.
 *
 * Wall faces outside every room (the façade) are not counted. A wall between two rooms counts once for each. Under the
 * floor's roofs (FD-38) a flat ceiling slopes where they come lower, so it counts along the slope (`RoomUnderRoof`), and
 * the bands and paint stop at it (`RoomFaceUnderRoof`).
 */
final class RoomSurfaces
{
	/** Steps of the vault's arc length integration (Simpson's rule). */
	private const int ArcSteps = 64;

	/** @var array<string, array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float}> */
	private array $lines = [];

	/**
	 * @param list<WallDto> $walls
	 * @param list<FloorVoidDto> $voids the floor's
	 * @param list<FloorVoidDto> $voidsAbove the floor above's, through the ceiling
	 * @return list<array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float}> amount in mm², or mm for skirting
	 */
	public static function of(RoomDto $room, array $walls, ?RoofCover $roofs = null, array $voids = [], array $voidsAbove = []): array
	{
		$surfaces = new self();
		$points = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $room->polygon);
		$area = abs(PlanMath::signedArea($points));
		if ($room->floorMaterialId !== null) {
			$surfaces->add(QuantitySurfaceEnum::Floor, $room->floorMaterialId, max(0.0, $area - VoidArea::of($room->polygon, $voids)));
		}
		// Under the roofs: the ceiling along the slope, and the cover to follow it along the walls.
		$under = $roofs === null
			? null
			: RoomUnderRoof::measure($points, $room->ceilingHeight, $room->floorElevation, $room->vaultSpacing !== null, $roofs);
		// The voids of the floor above come off a flat ceiling; a sloped one is scaled by the share of the floor they leave.
		$aboveLeft = $room->vaultSpacing === null && $area > 0 ? max(0.0, $area - VoidArea::of($room->polygon, $voidsAbove)) / $area : 1.0;
		$surfaces->addCeiling($room, $aboveLeft * ($under->ceilingArea ?? $area));
		$cover = $under === null ? null : $roofs;

		$wallTop = self::wallTop($room);
		$polygon = $room->polygon;
		foreach ($polygon as $i => $point) {
			$a = [(float) $point[0], (float) $point[1]];
			$next = $polygon[($i + 1) % count($polygon)];
			$b = [(float) $next[0], (float) $next[1]];
			$faces = WallFaceSpans::faces($a, $b, $walls);
			foreach ($faces as $face) {
				$surfaces->addFace($room, $face, $wallTop, $cover, $a, $b);
			}
			if ($room->skirtingHeight !== null) {
				$surfaces->addSkirting($room, $faces);
			}
		}

		return array_values($surfaces->lines);
	}

	/**
	 * Arc length of one vault (mm): a sine arch `rise` high over `span`.
	 */
	public static function arcLength(float $span, float $rise): float
	{
		$slope = static fn (float $x): float => sqrt(1 + (M_PI * $rise / $span * cos(M_PI * $x / $span)) ** 2);
		$step = $span / self::ArcSteps;
		$sum = $slope(0) + $slope($span);
		for ($i = 1; $i < self::ArcSteps; $i++) {
			$sum += ($i % 2 === 1 ? 4 : 2) * $slope($i * $step);
		}

		return $sum * $step / 3;
	}

	/** Height up to which the walls are painted: the ceiling, on a vault its mean height; null without a ceiling. */
	private static function wallTop(RoomDto $room): ?float
	{
		if ($room->ceilingHeight === null || $room->vaultSpacing === null) {
			return $room->ceilingHeight;
		}
		$span = max($room->vaultSpacing - $room->beamWidth, 0);

		// The mean of a sine arch is 2/π of its rise; over the beams the ceiling is at the springing.
		return $room->ceilingHeight - $room->vaultRise + $room->vaultRise * 2 / M_PI * $span / $room->vaultSpacing;
	}

	/** The ceiling, `area` mm² of it (under a roof its own area along the slope). */
	private function addCeiling(RoomDto $room, float $area): void
	{
		if ($room->ceilingHeight === null) {
			return;
		}
		if ($room->vaultSpacing === null || $room->vaultSpacing <= $room->beamWidth) {
			$this->add(QuantitySurfaceEnum::Ceiling, $room->ceilingMaterialId, $area);
			return;
		}

		$spacing = $room->vaultSpacing;
		$vault = self::arcLength($spacing - $room->beamWidth, $room->vaultRise);
		$this->add(QuantitySurfaceEnum::Ceiling, $room->ceilingMaterialId, $area * $vault / $spacing);
		$this->add(QuantitySurfaceEnum::Beams, $room->beamMaterialId, $area * ($room->beamWidth + 2 * $room->beamDrop) / $spacing);
	}

	/**
	 * Skirting along the faces, except their doorways: the same as `WallFaceSpans::skirting()`, from the faces at hand.
	 *
	 * @param list<array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>}> $faces
	 */
	private function addSkirting(RoomDto $room, array $faces): void
	{
		$spans = [];
		$doorways = [];
		foreach ($faces as $face) {
			$spans[] = [$face['from'], $face['to']];
			foreach ($face['gaps'] as $gap) {
				if ($gap['bottom'] <= 0) {
					$doorways[] = [$gap['from'], $gap['to']];
				}
			}
		}
		foreach (Spans::subtract(Spans::merge($spans), $doorways) as [$from, $to]) {
			$this->add(QuantitySurfaceEnum::Skirting, $room->skirtingMaterialId, $to - $from);
		}
	}

	/**
	 * A wall face on the room edge a → b, under the roofs (`cover` given) by `RoomFaceUnderRoof`.
	 *
	 * @param array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>} $face
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 */
	private function addFace(RoomDto $room, array $face, ?float $wallTop, ?RoofCover $cover, array $a, array $b): void
	{
		if ($cover === null) {
			$this->addWallFace($room, $face, $wallTop);
			return;
		}
		foreach (RoomFaceUnderRoof::amounts($room, $face, $wallTop, $cover, $a, $b) as [$surface, $materialId, $amount]) {
			$this->add($surface, $materialId, $amount);
		}
	}

	/** @param array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>} $face */
	private function addWallFace(RoomDto $room, array $face, ?float $wallTop): void
	{
		$top = $wallTop === null ? (float) $face['wall']->height : min((float) $face['wall']->height, $wallTop);
		$bands = [];
		foreach ($face['wall']->finishesOn($face['side']) ?? $room->wallFinishes as $band) {
			$bottom = max((float) $band->bottom, 0.0);
			$bandTop = min((float) $band->top, $top);
			if ($bandTop <= $bottom) {
				continue;
			}

			$this->add(QuantitySurfaceEnum::WallFinish, $band->materialId, self::faceArea($face, $bottom, $bandTop));
			$bands[] = [$bottom, $bandTop];
		}
		if ($wallTop === null) {
			return;
		}

		foreach (Spans::uncovered($top, $bands) as [$bottom, $paintTop]) {
			$this->add(QuantitySurfaceEnum::Wall, $face['wall']->materialId, self::faceArea($face, $bottom, $paintTop));
		}
	}

	/**
	 * Area of the face between two heights, without its openings.
	 *
	 * @param array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>} $face
	 */
	private static function faceArea(array $face, float $bottom, float $top): float
	{
		$area = ($face['to'] - $face['from']) * ($top - $bottom);
		foreach ($face['gaps'] as $gap) {
			$height = min((float) $gap['top'], $top) - max((float) $gap['bottom'], $bottom);
			if ($height > 0) {
				$area -= ($gap['to'] - $gap['from']) * $height;
			}
		}

		return max($area, 0.0);
	}

	private function add(QuantitySurfaceEnum $surface, ?int $materialId, float $amount): void
	{
		if ($amount <= 0) {
			return;
		}
		$key = $surface->value . ':' . ($materialId ?? '');
		$this->lines[$key] ??= ['surface' => $surface, 'materialId' => $materialId, 'amount' => 0.0];
		$this->lines[$key]['amount'] += $amount;
	}
}
