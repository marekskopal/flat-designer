<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Editor\Geometry;

use FlatDesigner\Service\Editor\Geometry\PlanMath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanMath::class)]
final class PlanMathTest extends TestCase
{
	public function testAreaAndPerimeterOfAnOutline(): void
	{
		$triangle = [[0.0, 0.0], [3000.0, 0.0], [0.0, 4000.0]];

		self::assertEqualsWithDelta(6000000.0, PlanMath::signedArea($triangle), 0.001);
		self::assertEqualsWithDelta(-6000000.0, PlanMath::signedArea(array_reverse($triangle)), 0.001);
		self::assertEqualsWithDelta(12000.0, PlanMath::perimeter($triangle), 0.001);
		self::assertSame(0.0, PlanMath::perimeter([]));
	}
}
