<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Editor\Geometry;

use Izbik\Dto\WallFinishDto;
use Izbik\Service\Editor\Geometry\FaceBands;
use Izbik\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared fixture tests/fixtures/face-bands.json (also run by the frontend twin, `face-bands.spec.ts`). */
#[CoversClass(FaceBands::class)]
final class FaceBandsTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../../fixtures/face-bands.json';

	/** @return iterable<string, array{list<WallFinishDto>|null, float, float, list<array{from: float, to: float, bands: list<int>|null}>}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, bands: list<array{materialId: int, bottom: int, top: int, from?: int, to?: int}>|null, from: int, to: int, stretches: list<array{from: int, to: int, bands: list<int>|null}>}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $case) {
			yield $case['name'] => [
				$case['bands'] === null ? null : array_map(WallFinishDto::fromArray(...), $case['bands']),
				(float) $case['from'],
				(float) $case['to'],
				array_map(
					static fn (array $s): array => ['from' => (float) $s['from'], 'to' => (float) $s['to'], 'bands' => $s['bands']],
					$case['stretches'],
				),
			];
		}
	}

	/**
	 * @param list<WallFinishDto>|null $bands
	 * @param list<array{from: float, to: float, bands: list<int>|null}> $expected
	 */
	#[DataProvider('cases')]
	public function testStretches(?array $bands, float $from, float $to, array $expected): void
	{
		$indices = static fn (?array $lining): ?array => $lining === null
			? null
			: array_map(static fn (WallFinishDto $band): int => (int) array_search($band, $bands ?? [], true), $lining);
		$actual = array_map(
			static fn (array $s): array => ['from' => $s['from'], 'to' => $s['to'], 'bands' => $indices($s['bands'])],
			FaceBands::stretches($bands, $from, $to),
		);

		self::assertSame($expected, $actual);
	}
}
