<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use FlatDesigner\Dto\MaterialDto;
use FlatDesigner\Dto\RoofDto;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Editor\Geometry\RoofCover;
use FlatDesigner\Service\Editor\Geometry\RoofWindowGeometry;

/**
 * The surfaces of a floor's roofs as the viewer draws them (FD-38), in mm²: each roof's covering and soffit over the parts
 * of its planes where it is the top (`RoofCover`), along the slope, without its windows. A roof without a covering
 * material has the viewer's default (clay tiles, a flat roof's membrane).
 */
final readonly class RoofSurfaces
{
	/** The viewer's default coverings (`roofs.ts`). */
	private const string TilesCode = 'roof-tiles-clay';

	private const string MembraneCode = 'roof-membrane';

	/**
	 * @param list<RoofDto> $roofs the floor's
	 * @param array<int, MaterialDto> $materials by id
	 * @return list<array{roof: RoofDto, windows: int, surfaces: list<array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float}>}>
	 */
	public static function of(array $roofs, array $materials, RoofCover $cover): array
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
				],
			];
		}

		return $result;
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
