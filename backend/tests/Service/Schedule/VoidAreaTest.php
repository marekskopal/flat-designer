<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Schedule;

use FlatDesigner\Dto\FloorVoidDto;
use FlatDesigner\Service\Editor\Geometry\PolygonOverlap;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\Schedule\VoidArea;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared fixture tests/fixtures/void-area.json (also run by the frontend twin, `voids-railings.spec.ts`). */
#[CoversClass(VoidArea::class)]
#[CoversClass(PolygonOverlap::class)]
final class VoidAreaTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../fixtures/void-area.json';

	/** @return iterable<string, array{list<array{int, int}>, list<FloorVoidDto>, float}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, room: list<array{int, int}>, voids: list<list<array{int, int}>>, area: int|float}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $i => $case) {
			$voids = [];
			foreach ($case['voids'] as $j => $polygon) {
				$voids[] = new FloorVoidDto($i * 10 + $j, 'Void', $polygon, 0.0, null, null);
			}

			yield $case['name'] => [$case['room'], $voids, (float) $case['area']];
		}
	}

	/**
	 * @param list<array{int, int}> $room
	 * @param list<FloorVoidDto> $voids
	 */
	#[DataProvider('cases')]
	public function testTheAreaTheVoidsTake(array $room, array $voids, float $area): void
	{
		self::assertEqualsWithDelta($area, VoidArea::of($room, $voids), 0.01);
	}
}
