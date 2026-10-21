<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Editor\Geometry\Spans;
use FlatDesigner\Service\Editor\Geometry\WallFaceSpans;
use const M_PI;

/**
 * The surfaces of one room as the viewer draws them, in mm² (or mm for skirting), per surface and material:
 *
 * - the floor in the floor material;
 * - wall finish bands along the wall faces on the room's edges (`WallFaceSpans`), up to the wall's top, without openings;
 * - the rest of those wall faces up to the ceiling in the wall's material (paint), without bands and openings; open-air
 *   rooms have no ceiling and paint none (their walls are façades);
 * - the ceiling, on a vault its arched surface, and the beams' underside and sides;
 * - skirting along the walls, except doorways.
 *
 * Wall faces outside every room (the façade) are not counted. A wall between two rooms counts once for each.
 */
final class RoomSurfaces
{
	/** Steps of the vault's arc length integration (Simpson's rule). */
	private const int ArcSteps = 64;

	/** @var array<string, array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float}> */
	private array $lines = [];

	/**
	 * @param list<WallDto> $walls
	 * @return list<array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float}> amount in mm², or mm for skirting
	 */
	public static function of(RoomDto $room, array $walls): array
	{
		$surfaces = new self();
		$area = abs(PlanMath::signedArea(array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $room->polygon)));
		if ($room->floorMaterialId !== null) {
			$surfaces->add(QuantitySurfaceEnum::Floor, $room->floorMaterialId, $area);
		}
		$surfaces->addCeiling($room, $area);

		$wallTop = self::wallTop($room);
		$polygon = $room->polygon;
		foreach ($polygon as $i => $point) {
			$a = [(float) $point[0], (float) $point[1]];
			$next = $polygon[($i + 1) % count($polygon)];
			$b = [(float) $next[0], (float) $next[1]];
			$faces = WallFaceSpans::faces($a, $b, $walls);
			foreach ($faces as $face) {
				$surfaces->addWallFace($room, $face, $wallTop);
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
	 * @param list<array{wall: WallDto, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int}>}> $faces
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

	/** @param array{wall: WallDto, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int}>} $face */
	private function addWallFace(RoomDto $room, array $face, ?float $wallTop): void
	{
		$top = $wallTop === null ? (float) $face['wall']->height : min((float) $face['wall']->height, $wallTop);
		$bands = [];
		foreach ($room->wallFinishes as $band) {
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

		foreach (self::uncovered($top, $bands) as [$bottom, $paintTop]) {
			$this->add(QuantitySurfaceEnum::Wall, $face['wall']->materialId, self::faceArea($face, $bottom, $paintTop));
		}
	}

	/**
	 * Area of the face between two heights, without its openings.
	 *
	 * @param array{wall: WallDto, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int}>} $face
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

	/**
	 * Height ranges from the floor up to `top` that no band covers.
	 *
	 * @param list<array{float, float}> $bands
	 * @return list<array{float, float}>
	 */
	private static function uncovered(float $top, array $bands): array
	{
		usort($bands, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
		$ranges = [];
		$from = 0.0;
		foreach ($bands as [$bottom, $bandTop]) {
			if ($bottom > $from) {
				$ranges[] = [$from, $bottom];
			}
			$from = max($from, $bandTop);
		}
		if ($top > $from) {
			$ranges[] = [$from, $top];
		}

		return $ranges;
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
