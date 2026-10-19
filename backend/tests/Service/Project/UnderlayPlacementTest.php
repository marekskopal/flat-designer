<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Project;

use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Project\UnderlayService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnderlayService::class)]
final class UnderlayPlacementTest extends TestCase
{
	public function testAxisAlignedPlanKeepsRotationZero(): void
	{
		// A scale bar from pixel (100, 500) to (200, 500) is 1 m; its left end belongs at the plan origin.
		$placement = UnderlayService::placementFromPoints([
			['px' => 100.0, 'py' => 500.0, 'x' => 0.0, 'y' => 0.0],
			['px' => 200.0, 'py' => 500.0, 'x' => 1000.0, 'y' => 0.0],
		]);

		self::assertSame(['x' => -1000, 'y' => 5000, 'scale' => 10.0, 'rotation' => 0.0], $placement);
	}

	public function testRotatedPlanMapsBothPointsBack(): void
	{
		$points = [
			['px' => 300.0, 'py' => 120.0, 'x' => 2500.0, 'y' => 4000.0],
			['px' => 420.0, 'py' => 410.0, 'x' => 6100.0, 'y' => 3100.0],
		];
		$placement = UnderlayService::placementFromPoints($points);

		foreach ($points as $point) {
			[$x, $y] = self::toPlan($placement, $point['px'], $point['py']);
			self::assertEqualsWithDelta($point['x'], $x, 1.0);
			self::assertEqualsWithDelta($point['y'], $y, 1.0);
		}
		self::assertGreaterThanOrEqual(0.0, $placement['rotation']);
		self::assertLessThan(360.0, $placement['rotation']);
	}

	public function testPointsMustLieApart(): void
	{
		$this->expectException(ProjectException::class);

		UnderlayService::placementFromPoints([
			['px' => 10.0, 'py' => 10.0, 'x' => 0.0, 'y' => 0.0],
			['px' => 10.0, 'py' => 10.0, 'x' => 1000.0, 'y' => 0.0],
		]);
	}

	/**
	 * The mapping documented on `UnderlayDto`.
	 *
	 * @param array{x: int, y: int, scale: float, rotation: float} $placement
	 * @return array{float, float}
	 */
	private static function toPlan(array $placement, float $px, float $py): array
	{
		$angle = deg2rad($placement['rotation']);

		return [
			$placement['x'] + $placement['scale'] * ($px * cos($angle) + $py * sin($angle)),
			$placement['y'] + $placement['scale'] * ($px * sin($angle) - $py * cos($angle)),
		];
	}
}
