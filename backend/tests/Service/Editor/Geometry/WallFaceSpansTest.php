<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Editor\Geometry;

use FlatDesigner\Dto\OpeningDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Enum\DoorFrameStyleEnum;
use FlatDesigner\Model\Entity\Enum\DoorLeafStyleEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Service\Editor\Geometry\FacadeSpans;
use FlatDesigner\Service\Editor\Geometry\Spans;
use FlatDesigner\Service\Editor\Geometry\WallFaceSpans;
use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared fixture tests/fixtures/wall-spans.json (also run by the frontend twin, `ceilings.spec.ts`). */
#[CoversClass(WallFaceSpans::class)]
#[CoversClass(FacadeSpans::class)]
#[CoversClass(Spans::class)]
final class WallFaceSpansTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../../fixtures/wall-spans.json';

	private const float Delta = 0.01;

	/** @return iterable<string, array{array{float, float}, array{float, float}, list<WallDto>, list<array{float, float}>, list<array{from: float, to: float, bottom: int, top: int, niche: bool}>, list<array{float, float}>, list<array{wall: int, side: string, from: float, to: float}>}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, edge: array{array{int|float, int|float}, array{int|float, int|float}}, walls: list<array{startX: int, startY: int, endX: int, endY: int, sagitta?: int, thickness: int, openings: list<array{offset: int, width: int, sillHeight: int, height: int, type?: string, side?: string}>}>, spans: list<array{int|float, int|float}>, gaps: list<array{from: int|float, to: int|float, bottom: int, top: int, niche?: bool}>, skirting: list<array{int|float, int|float}>, faces: list<array{wall: int, side: string, from: int|float, to: int|float}>}> $cases */
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
					static fn (array $g): array => [
						'from' => (float) $g['from'],
						'to' => (float) $g['to'],
						'bottom' => $g['bottom'],
						'top' => $g['top'],
						'niche' => $g['niche'] ?? false,
					],
					$case['gaps'],
				),
				array_map($pair, $case['skirting']),
				array_map(
					static fn (array $f): array => ['wall' => $f['wall'], 'side' => $f['side'], 'from' => (float) $f['from'], 'to' => (float) $f['to']],
					$case['faces'],
				),
			];
		}
	}

	/**
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @param list<WallDto> $walls
	 * @param list<array{float, float}> $spans
	 * @param list<array{from: float, to: float, bottom: int, top: int, niche: bool}> $gaps
	 * @param list<array{float, float}> $skirting
	 * @param list<array{wall: int, side: string, from: float, to: float}> $faces
	 */
	#[DataProvider('cases')]
	public function testWallFaces(array $a, array $b, array $walls, array $spans, array $gaps, array $skirting, array $faces): void
	{
		self::assertPairs($spans, WallFaceSpans::spans($a, $b, $walls));
		self::assertPairs($skirting, WallFaceSpans::skirting($a, $b, $walls));

		$actualGaps = WallFaceSpans::gaps($a, $b, $walls);
		self::assertCount(count($gaps), $actualGaps);
		foreach ($gaps as $i => $gap) {
			self::assertEqualsWithDelta($gap['from'], $actualGaps[$i]['from'], self::Delta);
			self::assertEqualsWithDelta($gap['to'], $actualGaps[$i]['to'], self::Delta);
			self::assertSame(
				[$gap['bottom'], $gap['top'], $gap['niche']],
				[$actualGaps[$i]['bottom'], $actualGaps[$i]['top'], $actualGaps[$i]['niche']],
			);
		}

		$actualFaces = WallFaceSpans::faces($a, $b, $walls);
		self::assertCount(count($faces), $actualFaces);
		foreach ($faces as $i => $face) {
			self::assertSame([$walls[$face['wall']]->id, $face['side']], [$actualFaces[$i]['wall']->id, $actualFaces[$i]['side']->value]);
			self::assertEqualsWithDelta($face['from'], $actualFaces[$i]['from'], self::Delta);
			self::assertEqualsWithDelta($face['to'], $actualFaces[$i]['to'], self::Delta);
		}
	}

	/** @return iterable<string, array{list<WallDto>, list<array{polygon: list<array{float, float}>, outdoor: bool}>, list<array{wall: int, side: string, spans: list<array{float, float}>}>}> */
	public static function facadeCases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, walls: list<array{startX: int, startY: int, endX: int, endY: int, sagitta?: int, thickness: int, openings: list<array{offset: int, width: int, sillHeight: int, height: int, type?: string, side?: string}>}>, rooms: list<array{polygon: list<array{int|float, int|float}>, outdoor?: bool}>, facades: list<array{wall: int, side: string, spans: list<array{int|float, int|float}>}>}> $cases */
		$cases = $fixture['facades'];
		$pair = static fn (array $p): array => [(float) $p[0], (float) $p[1]];
		foreach ($cases as $case) {
			$walls = [];
			foreach ($case['walls'] as $i => $wall) {
				$walls[] = self::wall($i + 1, $wall);
			}

			yield $case['name'] => [
				$walls,
				array_map(
					static fn (array $room): array => [
						'polygon' => array_map(
							$pair,
							$room['polygon'],
						),
						'outdoor' => $room['outdoor'] ?? false],
					$case['rooms'],
				),
				array_map(
					static fn (array $f): array => ['wall' => $f['wall'], 'side' => $f['side'], 'spans' => array_map($pair, $f['spans'])],
					$case['facades'],
				),
			];
		}
	}

	/**
	 * @param list<WallDto> $walls
	 * @param list<array{polygon: list<array{float, float}>, outdoor: bool}> $rooms
	 * @param list<array{wall: int, side: string, spans: list<array{float, float}>}> $facades
	 */
	#[DataProvider('facadeCases')]
	public function testFacades(array $walls, array $rooms, array $facades): void
	{
		$actual = FacadeSpans::of($walls, $rooms);

		self::assertCount(count($facades), $actual);
		foreach ($facades as $i => $facade) {
			self::assertSame([$walls[$facade['wall']]->id, $facade['side']], [$actual[$i]['wall']->id, $actual[$i]['side']->value]);
			self::assertPairs($facade['spans'], $actual[$i]['spans']);
		}
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
		self::assertSame([['from' => 500.0, 'to' => 1300.0, 'bottom' => 0, 'top' => 2000, 'niche' => false]], $faces[0]['gaps']);
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

	/** @param array{startX: int, startY: int, endX: int, endY: int, sagitta?: int, thickness: int, openings: list<array{offset: int, width: int, sillHeight: int, height: int, type?: string, side?: string}>} $wall */
	private static function wall(int $id, array $wall): WallDto
	{
		$openings = [];
		foreach ($wall['openings'] as $j => $o) {
			$openings[] = new OpeningDto(
				id: $id * 100 + $j,
				name: 'Opening',
				type: isset($o['type'])
					? OpeningTypeEnum::from($o['type'])
					: ($o['sillHeight'] > 0 ? OpeningTypeEnum::Window : OpeningTypeEnum::Door),
				offset: $o['offset'],
				width: $o['width'],
				height: $o['height'],
				sillHeight: $o['sillHeight'],
				leafWidth: null,
				hinge: null,
				side: isset($o['side']) ? OpeningSideEnum::from($o['side']) : null,
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
				depth: isset($o['type']) && $o['type'] === 'Niche' ? 100 : null,
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
			sagitta: $wall['sagitta'] ?? 0,
			thickness: $wall['thickness'],
			height: 2700,
			materialId: null,
			leftFinishes: null,
			rightFinishes: null,
			openings: $openings,
		);
	}
}
