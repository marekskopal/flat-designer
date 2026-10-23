<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Project;

use FlatDesigner\Service\Project\FloorStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FloorStack::class)]
final class FloorStackTest extends TestCase
{
	public function testANewFloorStandsOnTheTallestWallOfTheFloorBelow(): void
	{
		$ground = ['level' => 0, 'elevation' => 0, 'slabThickness' => 300, 'tallestWall' => 2700];
		$first = ['level' => 1, 'elevation' => 2950, 'slabThickness' => 250, 'tallestWall' => 2600];

		self::assertSame(2950, FloorStack::elevation(1, 250, [$ground]));
		self::assertSame(5800, FloorStack::elevation(2, 250, [$ground, $first]));
		// A floor of a level already taken lies level with it; one below the others hangs under the lowest one's slab.
		self::assertSame(2950, FloorStack::elevation(1, 200, [$ground, $first]));
		self::assertSame(-2900, FloorStack::elevation(-1, 300, [$ground, $first]));
		self::assertSame(-2900, FloorStack::elevation(-1, 300, [$ground, $first], 2600));
		self::assertSame(0, FloorStack::elevation(3, 250, []));
	}

	public function testStackingStartsAtTheGroundFloor(): void
	{
		$stacked = FloorStack::stack([
			10 => ['level' => 1, 'tallestWall' => 2500],
			11 => ['level' => 0, 'tallestWall' => 2800],
			12 => ['level' => -1, 'tallestWall' => 2400],
			13 => ['level' => 2, 'tallestWall' => 2600],
		]);

		self::assertSame([
			10 => ['elevation' => 3050, 'slabThickness' => 250],
			11 => ['elevation' => 0, 'slabThickness' => 300],
			12 => ['elevation' => -2700, 'slabThickness' => 300],
			13 => ['elevation' => 5800, 'slabThickness' => 250],
		], self::byId($stacked));
	}

	public function testWithoutAGroundFloorTheNearestOneIsAStoreyPerLevelUp(): void
	{
		self::assertSame([
			5 => ['elevation' => 2850, 'slabThickness' => 250],
			6 => ['elevation' => 5700, 'slabThickness' => 250],
		], self::byId(FloorStack::stack([5 => ['level' => 1, 'tallestWall' => 2600], 6 => ['level' => 2, 'tallestWall' => 2600]])));
	}

	/**
	 * @param array<int, array{elevation: int, slabThickness: int}> $stacked
	 * @return array<int, array{elevation: int, slabThickness: int}>
	 */
	private static function byId(array $stacked): array
	{
		ksort($stacked);

		return $stacked;
	}
}
