<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Editor\Geometry;

use FlatDesigner\Dto\OpeningDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Enum\DoorFrameStyleEnum;
use FlatDesigner\Model\Entity\Enum\DoorLeafStyleEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Service\Editor\Geometry\Spans;
use FlatDesigner\Service\Editor\Geometry\WallFaceSpans;
use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared fixture tests/fixtures/wall-spans.json (also run by the frontend twin, `ceilings.spec.ts`). */
#[CoversClass(WallFaceSpans::class)]
#[CoversClass(Spans::class)]
final class WallFaceSpansTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../../fixtures/wall-spans.json';

	private const float Delta = 0.01;

	/** @return iterable<string, array{array{float, float}, array{float, float}, list<WallDto>, list<array{float, float}>, list<array{from: float, to: float, bottom: int, top: int}>, list<array{float, float}>}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, edge: array{array{int|float, int|float}, array{int|float, int|float}}, walls: list<array{startX: int, startY: int, endX: int, endY: int, thickness: int, openings: list<array{offset: int, width: int, sillHeight: int, height: int}>}>, spans: list<array{int|float, int|float}>, gaps: list<array{from: int|float, to: int|float, bottom: int, top: int}>, skirting: list<array{int|float, int|float}>}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $case) {
			$walls = [];
			foreach ($case['walls'] as $i => $wall) {
				$walls[] = self::wall($i + 1, $wall);
			}
			$pair = static fn (array $p): array => [(float) $p[0], (float) $p[1]];

			yield $case['name'] => [
				$pair($case['edge'][0]),
				$pair($case['edge'][1]),
				$walls,
				array_map($pair, $case['spans']),
				array_map(
					static fn (array $g): array => ['from' => (float) $g['from'], 'to' => (float) $g['to'], 'bottom' => $g['bottom'], 'top' => $g['top']],
					$case['gaps'],
				),
				array_map($pair, $case['skirting']),
			];
		}
	}

	/**
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @param list<WallDto> $walls
	 * @param list<array{float, float}> $spans
	 * @param list<array{from: float, to: float, bottom: int, top: int}> $gaps
	 * @param list<array{float, float}> $skirting
	 */
	#[DataProvider('cases')]
	public function testWallFaces(array $a, array $b, array $walls, array $spans, array $gaps, array $skirting): void
	{
		self::assertPairs($spans, WallFaceSpans::spans($a, $b, $walls));
		self::assertPairs($skirting, WallFaceSpans::skirting($a, $b, $walls));

		$actualGaps = WallFaceSpans::gaps($a, $b, $walls);
		self::assertCount(count($gaps), $actualGaps);
		foreach ($gaps as $i => $gap) {
			self::assertEqualsWithDelta($gap['from'], $actualGaps[$i]['from'], self::Delta);
			self::assertEqualsWithDelta($gap['to'], $actualGaps[$i]['to'], self::Delta);
			self::assertSame([$gap['bottom'], $gap['top']], [$actualGaps[$i]['bottom'], $actualGaps[$i]['top']]);
		}

		// The faces one by one cover the merged spans exactly once.
		$faces = WallFaceSpans::faces($a, $b, $walls);
		$covered = array_sum(array_map(static fn (array $face): float => $face['to'] - $face['from'], $faces));
		self::assertEqualsWithDelta(array_sum(array_map(static fn (array $s): float => $s[1] - $s[0], $spans)), $covered, self::Delta);
	}

	public function testFacesKeepTheirWallAndOpenings(): void
	{
		$a = self::wall(1, [
			'startX' => 0,
			'startY' => -50,
			'endX' => 2000,
			'endY' => -50,
			'thickness' => 100,
			'openings' => [
				['offset' => 500, 'width' => 800, 'sillHeight' => 0, 'height' => 2000],
			]]);
		$b = self::wall(2, ['startX' => 2000, 'startY' => -50, 'endX' => 4000, 'endY' => -50, 'thickness' => 100, 'openings' => []]);

		$faces = WallFaceSpans::faces([0.0, 0.0], [4000.0, 0.0], [$a, $b]);

		self::assertSame([[1, 0.0, 2050.0], [2, 2050.0, 4000.0]], array_map(
			static fn (array $face): array => [$face['wall']->id, $face['from'], $face['to']],
			$faces,
		));
		self::assertSame([['from' => 500.0, 'to' => 1300.0, 'bottom' => 0, 'top' => 2000]], $faces[0]['gaps']);
		self::assertSame([], $faces[1]['gaps']);
	}

	/**
	 * @param list<array{float, float}> $expected
	 * @param list<array{float, float}> $actual
	 */
	private static function assertPairs(array $expected, array $actual): void
	{
		self::assertCount(count($expected), $actual);
		foreach ($expected as $i => [$from, $to]) {
			self::assertEqualsWithDelta($from, $actual[$i][0], self::Delta);
			self::assertEqualsWithDelta($to, $actual[$i][1], self::Delta);
		}
	}

	/** @param array{startX: int, startY: int, endX: int, endY: int, thickness: int, openings: list<array{offset: int, width: int, sillHeight: int, height: int}>} $wall */
	private static function wall(int $id, array $wall): WallDto
	{
		$openings = [];
		foreach ($wall['openings'] as $j => $o) {
			$openings[] = new OpeningDto(
				id: $id * 100 + $j,
				name: 'Opening',
				type: $o['sillHeight'] > 0 ? OpeningTypeEnum::Window : OpeningTypeEnum::Door,
				offset: $o['offset'],
				width: $o['width'],
				height: $o['height'],
				sillHeight: $o['sillHeight'],
				leafWidth: null,
				hinge: null,
				side: null,
				entrance: false,
				walkable: false,
				frameStyle: DoorFrameStyleEnum::Lining,
				handle: null,
				mullions: 1,
				transoms: 0,
				sillBoard: null,
				leafStyle: DoorLeafStyleEnum::Flush,
				leafPanels: 1,
				panelHeight: null,
				shutters: null,
				shutterMaterialId: null,
				shuttersClosed: false,
			);
		}

		return new WallDto(
			id: $id,
			name: 'W' . $id,
			type: WallTypeEnum::Interior,
			startX: $wall['startX'],
			startY: $wall['startY'],
			endX: $wall['endX'],
			endY: $wall['endY'],
			thickness: $wall['thickness'],
			height: 2700,
			materialId: null,
			openings: $openings,
		);
	}
}
