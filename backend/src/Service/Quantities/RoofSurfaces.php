<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use Closure;
use FlatDesigner\Dto\MaterialDto;
use FlatDesigner\Dto\RoofDto;
use FlatDesigner\Model\Entity\Enum\GutterProfileEnum;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Editor\Geometry\RoofCover;
use FlatDesigner\Service\Editor\Geometry\RoofGutters;
use FlatDesigner\Service\Editor\Geometry\RoofWindowGeometry;

/**
 * The surfaces of a floor's roofs as the viewer draws them (FD-38), in mm²: each roof's covering and soffit over the parts
 * of its planes where it is the top (`RoofCover`), along the slope, without its windows. A roof without a covering
 * material has the viewer's default (clay tiles, a flat roof's membrane). Its gutters (`RoofGutters`, FD-91) count by the
 * running metre along the runs, its downpipes from the gutter down to the ground under them, both in the gutter
 * material (titanium zinc without one).
 */
final readonly class RoofSurfaces
{
	/** The viewer's default coverings (`roofs.ts`). */
	private const string TilesCode = 'roof-tiles-clay';

	private const string MembraneCode = 'roof-membrane';

	private const string GutterCode = 'zinc-titanium';

	/**
	 * @param list<RoofDto> $roofs the floor's
	 * @param array<int, MaterialDto> $materials by id
	 * @param Closure(float, float): float $ground mm against the floor's level under a plan point, where downpipes end
	 * @return list<array{roof: RoofDto, windows: int, surfaces: list<array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float}>}>
	 */
	public static function of(array $roofs, array $materials, RoofCover $cover, Closure $ground): array
	{
		$result = [];
		foreach ($roofs as $r => $roof) {
			$area = 0.0;
			foreach ($cover->pieces as $piece) {
				if ($piece['roof'] === $r) {
					$area += PlanMath::signedArea($piece['polygon']) * sqrt(1 + $piece['gx'] ** 2 + $piece['gy'] ** 2);
				}
			}
			$windows = self::windows($roof, $cover, $r);
			$area = max($area - $windows['area'], 0.0);
			$covering = $roof->coveringMaterialId ?? self::byCode(
				$materials,
				$roof->shape === 'Flat' ? self::MembraneCode : self::TilesCode,
			);
			$result[] = [
				'roof' => $roof,
				'windows' => $windows['count'],
				'surfaces' => [
					['surface' => QuantitySurfaceEnum::RoofCovering, 'materialId' => $covering, 'amount' => $area],
					['surface' => QuantitySurfaceEnum::Soffit, 'materialId' => $roof->soffitMaterialId, 'amount' => $area],
					...self::rainwater($roof, $cover, $r, $materials, $ground),
				],
			];
		}

		return $result;
	}

	/**
	 * The gutters' and the downpipes' running mm; none without gutters.
	 *
	 * @param array<int, MaterialDto> $materials
	 * @param Closure(float, float): float $ground
	 * @return list<array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float}>
	 */
	private static function rainwater(RoofDto $roof, RoofCover $cover, int $index, array $materials, Closure $ground): array
	{
		if ($roof->gutters === GutterProfileEnum::None->value || !$cover->roofs[$index]->shape->isPitched()) {
			return [];
		}
		$runs = RoofGutters::runs($cover->roofs, $index);
		$gutters = 0.0;
		foreach ($runs as $run) {
			$gutters += hypot($run['to'][0] - $run['from'][0], $run['to'][1] - $run['from'][1]);
		}
		$downpipes = 0.0;
		foreach (RoofGutters::downpipes($cover->roofs[$index], $roof->downpipes, $runs) as $downpipe) {
			$downpipes += max(0.0, $downpipe['top'] - $ground($downpipe['at'][0], $downpipe['at'][1]));
		}
		$material = $roof->gutterMaterialId ?? self::byCode($materials, self::GutterCode);

		return [
			['surface' => QuantitySurfaceEnum::Gutter, 'materialId' => $material, 'amount' => $gutters],
			['surface' => QuantitySurfaceEnum::Downpipe, 'materialId' => $material, 'amount' => $downpipes],
		];
	}

	/**
	 * The roof's windows the viewer draws (every corner on the window's plane, and the roof the top at its centre: one
	 * the roof left behind, or lying under another roof, is not), and the area they take out of its slope.
	 *
	 * @return array{count: int, area: float}
	 */
	private static function windows(RoofDto $roof, RoofCover $cover, int $index): array
	{
		$geometry = $cover->roofs[$index];
		$count = 0;
		$area = 0.0;
		foreach ($roof->windows as $window) {
			$placed = RoofWindowGeometry::corners($geometry, $window->along, $window->across, $window->width, $window->length);
			if ($placed === null || !self::onTop($cover, $index, $placed['plane'], $geometry->toPlan($window->along, $window->across))) {
				continue;
			}
			foreach ($placed['corners'] as [$s, $t]) {
				if ($geometry->localPlaneAt($s, $t) !== $placed['plane']) {
					continue 2;
				}
			}
			$count++;
			$area += $window->width * $window->length;
		}

		return ['count' => $count, 'area' => $area];
	}

	/**
	 * Whether a plane of a roof is the top at a plan point.
	 *
	 * @param array{float, float} $point
	 */
	private static function onTop(RoofCover $cover, int $roof, int $plane, array $point): bool
	{
		foreach ($cover->pieces as $piece) {
			if ($piece['roof'] === $roof && $piece['plane'] === $plane && PlanMath::contains($piece['polygon'], $point)) {
				return true;
			}
		}

		return false;
	}

	/** @param array<int, MaterialDto> $materials */
	private static function byCode(array $materials, string $code): ?int
	{
		foreach ($materials as $material) {
			if ($material->code === $code) {
				return $material->id;
			}
		}

		return null;
	}
}
