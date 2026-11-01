<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Editor\Geometry;

use FlatDesigner\Service\Editor\Geometry\Delaunay;
use FlatDesigner\Service\Editor\Geometry\Terrain;
use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared fixture tests/fixtures/terrain.json (also run by the frontend twin, `terrain.spec.ts`).
 *
 * @phpstan-type Expected array{vertices: int, triangles: int, heights: list<float|int>}
 */
#[CoversClass(Terrain::class)]
#[CoversClass(Delaunay::class)]
final class TerrainTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../../fixtures/terrain.json';

	private const float Tolerance = 0.01;

	/** @return iterable<string, array{list<array{int, int, int}>, list<array{int, int}>, int, list<array{int, int}>, Expected}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, points: list<array{int, int, int}>, polygon: list<array{int, int}>, groundLevel: int, queries: list<array{int, int}>, expected: Expected}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $case) {
			yield $case['name'] => [$case['points'], $case['polygon'], $case['groundLevel'], $case['queries'], $case['expected']];
		}
	}

	/**
	 * @param list<array{int, int, int}> $points
	 * @param list<array{int, int}> $polygon
	 * @param list<array{int, int}> $queries
	 * @param Expected $expected
	 */
	#[DataProvider('cases')]
	public function testFixture(array $points, array $polygon, int $groundLevel, array $queries, array $expected): void
	{
		$terrain = Terrain::of($points, $polygon, $groundLevel);

		self::assertCount($expected['vertices'], $terrain->vertices);
		self::assertCount($expected['triangles'], $terrain->triangles);
		foreach ($queries as $i => [$x, $y]) {
			self::assertEqualsWithDelta($expected['heights'][$i], $terrain->at($x, $y), self::Tolerance, sprintf('at (%d, %d)', $x, $y));
		}
	}

	public function testRange(): void
	{
		self::assertSame([-250.0, -250.0], Terrain::of([], [[0, 0], [1000, 0], [0, 1000]], -250)->range());
		self::assertSame(
			[-300.0, 1200.0],
			Terrain::of([[2000, 2000, -300], [8000, 8000, 1200]], [[0, 0], [10000, 0], [10000, 10000], [0, 10000]], 0)->range(),
		);
	}

	public function testCollinearPointsMakeNoTriangles(): void
	{
		self::assertSame([], Delaunay::triangulate([[0.0, 0.0, 0.0], [1000.0, 0.0, 0.0], [2000.0, 0.0, 0.0]]));
	}
}
