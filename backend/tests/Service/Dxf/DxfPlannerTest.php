<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Dxf;

use Izbik\Service\Dxf\DxfAnchorEnum;
use Izbik\Service\Dxf\DxfPlan;
use Izbik\Service\Dxf\DxfPlanner;
use Izbik\Service\Dxf\DxfPlanOptions;
use Izbik\Service\Dxf\DxfReader;
use Izbik\Service\Dxf\DxfUnits;
use Izbik\Tests\Support\Dxf;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function array_count_values;
use function array_map;
use function array_merge;
use function file_get_contents;
use function ksort;
use function usort;

#[CoversNamespace('Izbik\\Service\\Dxf')]
final class DxfPlannerTest extends TestCase
{
	private const string ThirdParty = __DIR__ . '/../../fixtures/dxf/third-party/';

	/**
	 * A room 4000 × 3000 inside 300 mm walls drawn as an outer and an inner ring, split by a 100 mm wall of two
	 * rectangles with a doorway between them (a swing arc on the door layer), and a window's glazing lines over the
	 * unbroken south wall.
	 *
	 * @return list<list<array{int, string|int|float}>>
	 */
	private static function room(): array
	{
		return [
			Dxf::rectangle('A-WALL', -300, -300, 4300, 3300),
			Dxf::rectangle('A-WALL', 0, 0, 4000, 3000),
			Dxf::rectangle('A-WALL', 1950, 0, 2050, 1000),
			Dxf::rectangle('A-WALL', 1950, 1800, 2050, 3000),
			Dxf::line('A-DOOR', 2050, 1000, 2850, 1000),
			Dxf::arc('A-DOOR', 2050, 1000, 800, 0, 90),
			Dxf::line('A-GLAZ', 500, -300, 1700, -300),
			Dxf::line('A-GLAZ', 500, -150, 1700, -150),
			Dxf::line('A-GLAZ', 500, 0, 1700, 0),
			Dxf::line('A-GLAZ', 500, -300, 500, 0),
			Dxf::line('A-GLAZ', 1700, -300, 1700, 0),
			Dxf::line('A-FURN', 100, 100, 900, 100),
		];
	}

	public function testWallsMeetAtTheirCentreLinesAndKeepTheirOpenings(): void
	{
		$plan = self::plan(self::room(), anchor: DxfAnchorEnum::Drawing);

		self::assertSame([
			[-150, -150, 4150, -150, 0, 300],
			[-150, 3150, 4150, 3150, 0, 300],
			[-150, -150, -150, 3150, 0, 300],
			[2000, -150, 2000, 3150, 0, 100],
			[4150, -150, 4150, 3150, 0, 300],
		], self::walls($plan));
		self::assertSame([
			['wall' => [-150, -150, 4150, -150], 'type' => 'Window', 'offset' => 650, 'width' => 1200, 'leafWidth' => null, 'hinge' => null, 'side' => null],
			['wall' => [2000, -150, 2000, 3150], 'type' => 'Door', 'offset' => 1150, 'width' => 800, 'leafWidth' => null, 'hinge' => 'Start', 'side' => 'Right'],
		], self::openings($plan));
		self::assertSame([], $plan->unpaired);
		self::assertSame([], $plan->warnings);
	}

	public function testTheWallsCornerLandsAtTheOriginThenTheOffset(): void
	{
		$plan = self::plan(self::room(), offsetX: 1000, offsetY: -500);

		self::assertSame([1150, -350, 5450, -350, 0, 300], self::walls($plan)[0]);
	}

	public function testATurnedDrawingTurnsItsOpeningsWithIt(): void
	{
		$plan = self::plan(self::room(), rotation: 90, anchor: DxfAnchorEnum::Drawing);

		// The south wall now runs up the east side; the door still opens to the same side of its wall.
		self::assertContains([150, -150, 150, 4150, 0, 300], self::walls($plan));
		$kinds = array_map(static fn (array $opening): array => [$opening['type'], $opening['hinge'], $opening['side']], $plan->openings);
		self::assertContains(['Door', 'Start', 'Right'], $kinds);
		self::assertContains(['Window', null, null], $kinds);
	}

	public function testUnitsScaleTheDrawing(): void
	{
		$plan = self::plan([Dxf::rectangle('A-WALL', 0, 0, 400, 30)], units: DxfUnits::Centimetres);

		self::assertSame([[0, 150, 4000, 150, 0, 300]], self::walls($plan));
		self::assertSame(DxfUnits::Centimetres, $plan->units);
	}

	public function testADrawingWithoutUnitsIsReadInMillimetresWithAWarning(): void
	{
		$plan = new DxfPlanner()->plan(
			DxfReader::read(Dxf::document([Dxf::rectangle('A-WALL', 0, 0, 4000, 300)], units: null)),
			new DxfPlanOptions(['A-WALL']),
		);

		self::assertSame(DxfUnits::Millimetres, $plan->units);
		self::assertStringContainsString('does not say its units', $plan->warnings[0]);
	}

	public function testCollinearPiecesWithOneFaceRunningOnAreOneWall(): void
	{
		// The north face of a wall stops where another wall meets it from the south: no opening there.
		$entities = [
			Dxf::line('A-WALL', 0, 150, 6000, 150),
			Dxf::line('A-WALL', 0, -150, 2950, -150),
			Dxf::line('A-WALL', 3050, -150, 6000, -150),
			Dxf::line('A-WALL', 2950, -150, 2950, -3000),
			Dxf::line('A-WALL', 3050, -150, 3050, -3000),
		];
		$plan = self::plan($entities, anchor: DxfAnchorEnum::Drawing);

		self::assertSame([[0, 0, 6000, 0, 0, 300], [3000, -3000, 3000, 0, 0, 100]], self::walls($plan));
		self::assertSame([], $plan->openings);
	}

	public function testAGapWithNothingDrawnInItIsAPassage(): void
	{
		$entities = array_merge(
			Dxf::wallFaces('A-WALL', 0, 0, 2000, 0, 200),
			Dxf::wallFaces('A-WALL', 3000, 0, 6000, 0, 200),
			[Dxf::line('A-WALL', 2000, -100, 2000, 100), Dxf::line('A-WALL', 3000, -100, 3000, 100)],
		);
		$plan = self::plan($entities, anchor: DxfAnchorEnum::Drawing);

		self::assertSame([[0, 0, 6000, 0, 0, 200]], self::walls($plan));
		self::assertSame('Passage', $plan->openings[0]['type']);
		self::assertSame([2000, 1000], [$plan->openings[0]['offset'], $plan->openings[0]['width']]);
		// The jambs lie in the wall: they are no leftovers.
		self::assertSame([], $plan->unpaired);
	}

	public function testConcentricArcsAreACurvedWall(): void
	{
		$entities = [
			Dxf::arc('A-WALL', 0, 0, 3000, 0, 90),
			Dxf::arc('A-WALL', 0, 0, 3200, 0, 90),
		];
		$plan = self::plan($entities, anchor: DxfAnchorEnum::Drawing);

		// Counter-clockwise round the centre: the arc bulges to the right of start → end.
		self::assertSame([[3100, 0, 0, 3100, -908, 200]], self::walls($plan));
	}

	public function testLeftoverLinesAreReported(): void
	{
		$plan = self::plan([Dxf::rectangle('A-WALL', 0, 0, 4000, 300), Dxf::line('A-WALL', 0, 2000, 3000, 2000)]);

		self::assertSame([[0, 2000, 3000, 2000]], $plan->unpaired);
		self::assertStringContainsString('1 line(s)', $plan->warnings[0]);
	}

	/**
	 * Public sample plans (see the README beside them): the number of walls, their thicknesses and the openings by kind,
	 * as checked against a drawing of each.
	 *
	 * @return array<string, array{string, list<string>, list<string>, int, array<int, int>, array<string, int>}>
	 */
	public static function samples(): array
	{
		return [
			'outline rings and rectangles' => [
				'cadcraft-apartment.dxf',
				['A-WALL'],
				['A-DOOR', 'A-GLAZ'],
				6,
				[100 => 2, 200 => 4],
				['Door/Start/Right' => 2, 'Window' => 5],
			],
			'inches, door and window blocks over unbroken walls' => [
				'bimcraft-house.dxf',
				['A-WALL'],
				['A-DOOR', 'A-GLAZ'],
				8,
				[102 => 4, 152 => 4],
				['Door/End/Left' => 1, 'Door/Start/Left' => 1, 'Door/Start/Right' => 3, 'Window' => 6],
			],
			'a rectangle per piece, overlapping at corners' => [
				'kestrel-courtyard.dxf',
				['A-WALL'],
				['A-OPENING'],
				10,
				[180 => 10],
				['Door/Start/Left' => 3, 'Door/Start/Right' => 1, 'Passage' => 1, 'Window' => 8],
			],
			'line pairs' => [
				'kjdraw-plan.dxf',
				['A-WALL'],
				['A-DOOR', 'A-WINDOW'],
				11,
				[130 => 7, 180 => 4],
				['Door/Start/Left' => 6, 'Door/Start/Right' => 6, 'Window' => 6],
			],
			'R12 line pairs' => [
				'villa-gf.dxf',
				['A-WALL'],
				['A-DOOR'],
				12,
				[100 => 8, 200 => 4],
				['Door/Start/Left' => 9, 'Door/Start/Right' => 2],
			],
		];
	}

	/**
	 * @param list<string> $wallLayers
	 * @param list<string> $openingLayers
	 * @param array<int, int> $thicknesses
	 * @param array<string, int> $openings
	 */
	#[DataProvider('samples')]
	public function testSamplePlans(
		string $file,
		array $wallLayers,
		array $openingLayers,
		int $walls,
		array $thicknesses,
		array $openings,
	): void
	{
		$body = file_get_contents(self::ThirdParty . $file);
		self::assertIsString($body);
		$plan = new DxfPlanner()->plan(DxfReader::read($body), new DxfPlanOptions($wallLayers, $openingLayers));

		self::assertCount($walls, $plan->walls);
		$actualThicknesses = array_count_values(array_map(static fn (array $wall): int => $wall['thickness'], $plan->walls));
		ksort($actualThicknesses);
		self::assertSame($thicknesses, $actualThicknesses);
		$kinds = array_count_values(array_map(
			static fn (array $opening): string => $opening['hinge'] === null ? $opening['type'] : $opening['type'] . '/' . $opening['hinge'] . '/' . ($opening['side'] ?? ''),
			$plan->openings,
		));
		ksort($kinds);
		self::assertSame($openings, $kinds);
		self::assertSame([], $plan->unpaired);
	}

	/** @param list<list<array{int, string|int|float}>> $entities */
	private static function plan(
		array $entities,
		DxfAnchorEnum $anchor = DxfAnchorEnum::Walls,
		float $rotation = 0.0,
		int $offsetX = 0,
		int $offsetY = 0,
		int $units = DxfUnits::Millimetres,
	): DxfPlan {
		return new DxfPlanner()->plan(
			DxfReader::read(Dxf::document($entities, units: $units)),
			new DxfPlanOptions(
				['A-WALL'],
				['A-DOOR', 'A-GLAZ'],
				rotation: $rotation,
				anchor: $anchor,
				offsetX: $offsetX,
				offsetY: $offsetY,
			),
		);
	}

	/** @return list<array{int, int, int, int, int, int}> sorted */
	private static function walls(DxfPlan $plan): array
	{
		$walls = array_map(
			static fn (array $wall): array => [$wall['startX'], $wall['startY'], $wall['endX'], $wall['endY'], $wall['sagitta'], $wall['thickness']],
			$plan->walls,
		);
		usort($walls, static fn (array $a, array $b): int => [$a[0] === $a[2] ? 1 : 0, $a] <=> [$b[0] === $b[2] ? 1 : 0, $b]);

		return $walls;
	}

	/** @return list<array<string, mixed>> each with its wall's ends, sorted by them */
	private static function openings(DxfPlan $plan): array
	{
		$openings = array_map(static function (array $opening) use ($plan): array {
			$wall = $plan->walls[$opening['wall']];

			return ['wall' => [$wall['startX'], $wall['startY'], $wall['endX'], $wall['endY']]] + $opening;
		}, $plan->openings);
		usort(
			$openings,
			static fn (array $a, array $b): int => [$a['wall'][0] === $a['wall'][2] ? 1 : 0, $a['wall']] <=> [$b['wall'][0] === $b['wall'][2] ? 1 : 0, $b['wall']],
		);

		return $openings;
	}
}
