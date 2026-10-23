<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Editor\Geometry;

use FlatDesigner\Service\Editor\Geometry\FaceGraph;
use FlatDesigner\Service\Editor\Geometry\OutlineCleaner;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Editor\Geometry\RoomDetectErrorEnum;
use FlatDesigner\Service\Editor\Geometry\RoomDetector;
use FlatDesigner\Service\Editor\Geometry\WallJoints;
use FlatDesigner\Service\Editor\Geometry\WallLine;
use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared fixture tests/fixtures/room-detection.json (also run by the frontend twin). */
#[CoversClass(RoomDetector::class)]
#[CoversClass(WallJoints::class)]
#[CoversClass(WallLine::class)]
#[CoversClass(RoomDetectErrorEnum::class)]
#[CoversClass(FaceGraph::class)]
#[CoversClass(OutlineCleaner::class)]
#[CoversClass(PlanMath::class)]
final class RoomDetectorTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../../fixtures/room-detection.json';

	/** @return iterable<string, array{list<WallLine>, array{int, int}, list<array{int, int}>|null, string|null}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var array<string, list<array{id: int, startX: int, startY: int, endX: int, endY: int, thickness: int, sagitta?: int}>> $layouts */
		$layouts = $fixture['layouts'];
		/** @var list<array{name: string, layout: string, point: array{int, int}, polygon?: list<array{int, int}>, error?: string}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $case) {
			$walls = array_map(
				static fn (array $wall): WallLine => new WallLine(
					$wall['id'],
					$wall['startX'],
					$wall['startY'],
					$wall['endX'],
					$wall['endY'],
					$wall['thickness'],
					$wall['sagitta'] ?? 0,
				),
				$layouts[$case['layout']],
			);

			yield $case['name'] => [$walls, $case['point'], $case['polygon'] ?? null, $case['error'] ?? null];
		}
	}

	/**
	 * @param list<WallLine> $walls
	 * @param array{int, int} $point
	 * @param list<array{int, int}>|null $polygon
	 */
	#[DataProvider('cases')]
	public function testFixture(array $walls, array $point, ?array $polygon, ?string $error): void
	{
		$result = RoomDetector::detect($walls, $point[0], $point[1]);

		if ($error !== null) {
			self::assertSame($error, $result instanceof RoomDetectErrorEnum ? $result->value : null);
			return;
		}
		self::assertSame($polygon, $result);
	}
}
