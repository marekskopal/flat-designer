<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Project;

use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Project\UnderlayService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared fixture tests/fixtures/underlay-placement.json (also run by the frontend twin, `underlay.spec.ts`). */
#[CoversClass(UnderlayService::class)]
final class UnderlayPlacementTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../fixtures/underlay-placement.json';

	/** @return iterable<string, array{list<array{px: float, py: float, x: float, y: float}>, array{x: int, y: int, scale: float, rotation: float}|null, bool, float}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, points: list<array{px: int|float, py: int|float, x: int|float, y: int|float}>, expected?: array{x: int, y: int, scale: int|float, rotation: int|float}|null, tolerance?: int|float}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $case) {
			// `expected: null` means the points are refused; no `expected` checks the mapping only.
			$expected = $case['expected'] ?? null;

			yield $case['name'] => [
				array_map(
					static fn (array $point): array => ['px' => (float) $point['px'], 'py' => (float) $point['py'], 'x' => (float) $point['x'], 'y' => (float) $point['y']],
					$case['points'],
				),
				$expected === null ? null :
				[
					'x' => $expected['x'],
					'y' => $expected['y'],
					'scale' => (float) $expected['scale'],
					'rotation' => (float) $expected['rotation'],
				],
				array_key_exists('expected', $case) && $expected === null,
				(float) ($case['tolerance'] ?? 0.5),
			];
		}
	}

	/**
	 * @param list<array{px: float, py: float, x: float, y: float}> $points
	 * @param array{x: int, y: int, scale: float, rotation: float}|null $expected
	 */
	#[DataProvider('cases')]
	public function testPlacement(array $points, ?array $expected, bool $refused, float $tolerance): void
	{
		if ($refused) {
			$this->expectException(ProjectException::class);
			UnderlayService::placementFromPoints($points);

			return;
		}

		$placement = UnderlayService::placementFromPoints($points);
		if ($expected !== null) {
			self::assertSame($expected['x'], $placement['x']);
			self::assertSame($expected['y'], $placement['y']);
			self::assertEqualsWithDelta($expected['scale'], $placement['scale'], 1e-9);
			self::assertEqualsWithDelta($expected['rotation'], $placement['rotation'], 1e-6);
		}
		self::assertGreaterThanOrEqual(0.0, $placement['rotation']);
		self::assertLessThan(360.0, $placement['rotation']);
		foreach ($points as $point) {
			[$x, $y] = self::toPlan($placement, $point['px'], $point['py']);
			self::assertEqualsWithDelta($point['x'], $x, $tolerance);
			self::assertEqualsWithDelta($point['y'], $y, $tolerance);
		}
	}

	/**
	 * The mapping documented on `UnderlayDto`.
	 *
	 * @param array{x: int, y: int, scale: float, rotation: float} $placement
	 * @return array{float, float}
	 */
	private static function toPlan(array $placement, float $px, float $py): array
	{
		$angle = deg2rad($placement['rotation']);

		return [
			$placement['x'] + $placement['scale'] * ($px * cos($angle) + $py * sin($angle)),
			$placement['y'] + $placement['scale'] * ($px * sin($angle) - $py * cos($angle)),
		];
	}
}
