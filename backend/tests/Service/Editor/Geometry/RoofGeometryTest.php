<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Editor\Geometry;

use FlatDesigner\Model\Entity\Enum\RoofShapeEnum;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared fixture tests/fixtures/roof-geometry.json (also run by the frontend twin, `roof-geometry.spec.ts`).
 *
 * @phpstan-type Probe array{x: int, y: int, underside: float|int|null, top: float|int|null, plane: int|null}
 * @phpstan-type Plane array{polygon: list<array{x: float|int, y: float|int}>, gradient: array{x: float|int, y: float|int}, height: float|int}
 * @phpstan-type Expected array{ridgeHeight: float|int, planes: list<Plane>, probes: list<Probe>}
 */
#[CoversClass(RoofGeometry::class)]
final class RoofGeometryTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../../fixtures/roof-geometry.json';

	private const float Tolerance = 0.01;

	/** @return iterable<string, array{RoofGeometry, Expected}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, roof: array{shape: string, x: int, y: int, rotation: int, width: int, depth: int, eaveHeight: int, pitch: int, overhang: int, vergeOverhang: int, thickness: int}, expected: Expected}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $case) {
			$roof = $case['roof'];

			yield $case['name'] => [
				new RoofGeometry(
					RoofShapeEnum::from($roof['shape']),
					$roof['x'],
					$roof['y'],
					$roof['rotation'],
					$roof['width'],
					$roof['depth'],
					$roof['eaveHeight'],
					$roof['pitch'],
					$roof['overhang'],
					$roof['vergeOverhang'],
					$roof['thickness'],
				),
				$case['expected'],
			];
		}
	}

	/** @param Expected $expected */
	#[DataProvider('cases')]
	public function testGeometry(RoofGeometry $roof, array $expected): void
	{
		self::assertEqualsWithDelta($expected['ridgeHeight'], $roof->ridgeHeight(), self::Tolerance);

		$planes = $roof->planes();
		self::assertCount(count($expected['planes']), $planes);
		foreach ($expected['planes'] as $i => $plane) {
			self::assertCount(count($plane['polygon']), $planes[$i]['polygon'], sprintf('plane %d', $i));
			foreach ($plane['polygon'] as $j => $point) {
				self::assertEqualsWithDelta(
					[$point['x'], $point['y']],
					$planes[$i]['polygon'][$j],
					self::Tolerance,
					sprintf('plane %d, point %d', $i, $j),
				);
			}
			self::assertEqualsWithDelta([$plane['gradient']['x'], $plane['gradient']['y']], $planes[$i]['gradient'], 1e-5);
			self::assertEqualsWithDelta($plane['height'], $planes[$i]['height'], self::Tolerance);
			// The plane's height and gradient give the roof's underside at the plane's own points.
			foreach ($planes[$i]['polygon'] as [$x, $y]) {
				$height = $planes[$i]['height'] + $planes[$i]['gradient'][0] * ($x - $roof->x) + $planes[$i]['gradient'][1] * ($y - $roof->y);
				self::assertEqualsWithDelta($roof->undersideAt($x, $y), $height, self::Tolerance, sprintf('plane %d', $i));
			}
		}

		foreach ($expected['probes'] as $probe) {
			$at = sprintf('at (%d, %d)', $probe['x'], $probe['y']);
			self::assertEqualsWithDelta($probe['underside'], $roof->undersideAt($probe['x'], $probe['y']), self::Tolerance, $at);
			self::assertEqualsWithDelta($probe['top'], $roof->topAt($probe['x'], $probe['y']), self::Tolerance, $at);
			self::assertSame($probe['plane'], $roof->planeAt($probe['x'], $probe['y']), $at);
		}
	}

	public function testTheHighestRoofWinsWhereRoofsMeet(): void
	{
		$main = new RoofGeometry(RoofShapeEnum::Gable, 0, 0, 0, 10000, 8000, 2600, 45, 0, 0, 250);
		$wing = new RoofGeometry(RoofShapeEnum::Gable, 0, -6000, 90, 6000, 4000, 2600, 45, 0, 0, 250);
		// At (0, −3500) the main roof's front plane is 500 mm up its slope; the wing's ridge passes there.
		self::assertEqualsWithDelta(4600.0, RoofGeometry::undersideOf([$main, $wing], 0, -3500), self::Tolerance);
		self::assertEqualsWithDelta(3100.0, RoofGeometry::undersideOf([$main], 0, -3500), self::Tolerance);
		self::assertNull(RoofGeometry::undersideOf([$main, $wing], 9000, 9000));
	}
}
