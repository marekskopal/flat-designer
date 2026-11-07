<?php

declare(strict_types=1);

namespace Izbik\Service\Quantities;

use Izbik\Dto\FloorVoidDto;
use Izbik\Dto\RoomDto;
use Izbik\Dto\WallDto;
use Izbik\Model\Entity\Enum\OpeningSideEnum;
use Izbik\Model\Entity\Enum\QuantitySurfaceEnum;
use Izbik\Service\Editor\Geometry\PlanMath;
use Izbik\Service\Editor\Geometry\RoofCover;
use Izbik\Service\Editor\Geometry\RoomUnderRoof;
use Izbik\Service\Editor\Geometry\Spans;
use Izbik\Service\Editor\Geometry\WallFaceSpans;
use Izbik\Service\Schedule\VoidArea;
use const M_PI;

/**
 * The surfaces of one room as the viewer draws them, in mm² (or mm for skirting), per surface and material:
 *
 * - the floor in the floor material, less the floor's voids and its floor zones, which count in their own materials;
 * - wall finish bands along the wall faces on the room's edges (`WallFaceSpans`), up to the wall's top, without openings:
 *   the face's own bands (`WallDto::finishesOn()`) or else the room's;
 * - the rest of those wall faces up to the ceiling in the wall's material (paint), without bands and openings; open-air
 *   rooms have no ceiling and paint none (their walls are façades);
 * - the ceiling, on a vault its arched surface, and the beams' underside and sides; a flat one less the voids of the floor
 *   above, a sloped one scaled by the share of the floor they leave;
 * - a dropped ceiling (a ceiling zone): its soffit and sides as ceiling, in its material or the ceiling's;
 * - skirting along the walls, except doorways.
 *
 * Wall faces outside every room (the façade) are not counted. A wall between two rooms counts once for each. Under the
 * floor's roofs a flat ceiling slopes where they come lower, so it counts along the slope (`RoomUnderRoof`), and
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
		$zoned = $surfaces->addFloorZones($room);
		if ($room->floorMaterialId !== null) {
			$surfaces->add(
				QuantitySurfaceEnum::Floor,
				$room->floorMaterialId,
				max(0.0, $area - VoidArea::of($room->polygon, $voids) - $zoned),
			);
		}
		// Under the roofs: the ceiling along the slope, and the cover to follow it along the walls.
		$under = $roofs === null
			? null
			: RoomUnderRoof::measure($points, $room->ceilingHeight, $room->floorElevation, $room->vaultSpacing !== null, $roofs);
		// The voids of the floor above come off a flat ceiling; a sloped one is scaled by the share of the floor they leave.
		$aboveLeft = $room->vaultSpacing === null && $area > 0 ? max(0.0, $area - VoidArea::of($room->polygon, $voidsAbove)) / $area : 1.0;
		$surfaces->addCeiling($room, $aboveLeft * ($under->ceilingArea ?? $area));
		$surfaces->addCeilingZones($room);
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
	 * The floor zones in their materials (taken not to overlap); returns their area, which the floor material leaves.
	 */
	private function addFloorZones(RoomDto $room): float
	{
		$total = 0.0;
		foreach ($room->floorZones as $zone) {
			$area = abs(PlanMath::signedArea(self::points($zone['polygon'])));
			$this->add(QuantitySurfaceEnum::Floor, $zone['materialId'], $area);
			$total += $area;
		}

		return $total;
	}

	/**
	 * The dropped ceilings: each zone's soffit and its sides up to the ceiling, in its material (else the ceiling's). The
	 * room's ceiling above them stays counted.
	 */
	private function addCeilingZones(RoomDto $room): void
	{
		if ($room->ceilingHeight === null) {
			return;
		}
		foreach ($room->ceilingZones as $zone) {
			$points = self::points($zone['polygon']);
			$sides = PlanMath::perimeter($points) * max(0, $room->ceilingHeight - $zone['height']);
			$this->add(
				QuantitySurfaceEnum::Ceiling,
				$zone['materialId'] ?? $room->ceilingMaterialId,
				abs(PlanMath::signedArea($points)) + $sides,
			);
		}
	}

	/**
	 * @param list<array{int, int}> $polygon
	 * @return list<array{float, float}>
	 */
	private static function points(array $polygon): array
	{
		return array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $polygon);
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
		foreach (RoomFace::stretches($room, $face, $a, $b) as [$stretch, $bands]) {
			$amounts = $cover === null
				? RoomFace::amounts($stretch, $bands, $wallTop)
				: RoomFaceUnderRoof::amounts($room, $stretch, $wallTop, $cover, $a, $b, $bands);
			foreach ($amounts as [$surface, $materialId, $amount]) {
				$this->add($surface, $materialId, $amount);
			}
		}
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
