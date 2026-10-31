<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Editor\Geometry;

use FlatDesigner\Model\Entity\Enum\RoofShapeEnum;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use FlatDesigner\Service\Editor\Geometry\RoofGutters;
use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared fixture tests/fixtures/roof-gutters.json (also run by the frontend twin, `roof-details.spec.ts`).
 *
 * @phpstan-type RoofFields array{shape: string, x: int, y: int, rotation: int, width: int, depth: int, eaveHeight: int, pitch: int, overhang: int, vergeOverhang: int, thickness: int}
 * @phpstan-type Run array{edge: string, from: array{float, float}, to: array{float, float}, height: float}
 * @phpstan-type Pipe array{edge: string, at: array{float, float}, outlet: array{float, float}, top: float}
 */
#[CoversClass(RoofGutters::class)]
final class RoofGuttersTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../../fixtures/roof-gutters.json';

	private const float Tolerance = 0.01;

	/** @return iterable<string, array{list<RoofFields>, int, list<array{edge: string, along: int}>|null, list<Run>, list<Pipe>}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, roofs: list<RoofFields>, index: int, downpipes: list<array{edge: string, along: int}>|null, runs: list<Run>, pipes: list<Pipe>}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $case) {
			yield $case['name'] => [$case['roofs'], $case['index'], $case['downpipes'], $case['runs'], $case['pipes']];
		}
	}

	/**
	 * @param list<RoofFields> $roofs
	 * @param list<array{edge: string, along: int}>|null $downpipes
	 * @param list<Run> $runs
	 * @param list<Pipe> $pipes
	 */
	#[DataProvider('cases')]
	public function testFixture(array $roofs, int $index, ?array $downpipes, array $runs, array $pipes): void
	{
		$geometries = array_map(static fn (array $r): RoofGeometry => new RoofGeometry(
			RoofShapeEnum::from($r['shape']),
			$r['x'],
			$r['y'],
			$r['rotation'],
			$r['width'],
			$r['depth'],
			$r['eaveHeight'],
			$r['pitch'],
			$r['overhang'],
			$r['vergeOverhang'],
			$r['thickness'],
		), $roofs);
		$actualRuns = RoofGutters::runs($geometries, $index);
		self::assertCount(count($runs), $actualRuns);
		foreach ($runs as $i => $run) {
			self::assertSame($run['edge'], $actualRuns[$i]['edge']);
			self::assertPoint($run['from'], $actualRuns[$i]['from']);
			self::assertPoint($run['to'], $actualRuns[$i]['to']);
			self::assertEqualsWithDelta($run['height'], $actualRuns[$i]['height'], self::Tolerance);
		}
		$actualPipes = RoofGutters::downpipes($geometries[$index], $downpipes, $actualRuns);
		self::assertCount(count($pipes), $actualPipes);
		foreach ($pipes as $i => $pipe) {
			self::assertSame($pipe['edge'], $actualPipes[$i]['edge']);
			self::assertPoint($pipe['at'], $actualPipes[$i]['at']);
			self::assertPoint($pipe['outlet'], $actualPipes[$i]['outlet']);
			self::assertEqualsWithDelta($pipe['top'], $actualPipes[$i]['top'], self::Tolerance);
		}
	}

	/**
	 * @param array{float, float} $expected
	 * @param array{float, float} $actual
	 */
	private static function assertPoint(array $expected, array $actual): void
	{
		self::assertEqualsWithDelta($expected[0], $actual[0], self::Tolerance);
		self::assertEqualsWithDelta($expected[1], $actual[1], self::Tolerance);
	}
}
