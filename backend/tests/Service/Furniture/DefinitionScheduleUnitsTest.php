<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture;

use Izbik\Service\Furniture\DefinitionSchedule;
use Izbik\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared fixture tests/fixtures/electrical-units.json (also run by the frontend twin, `electrical.spec.ts`). */
#[CoversClass(DefinitionSchedule::class)]
final class DefinitionScheduleUnitsTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../fixtures/electrical-units.json';

	/** @return iterable<string, array{array<mixed>, array<mixed>, int}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, definition: array<mixed>, given: array<mixed>, units: int}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $case) {
			yield $case['name'] => [$case['definition'], $case['given'], $case['units']];
		}
	}

	/**
	 * @param array<mixed> $definition
	 * @param array<mixed> $given
	 */
	#[DataProvider('cases')]
	public function testWhatAnItemCountsAs(array $definition, array $given, int $units): void
	{
		self::assertSame($units, DefinitionSchedule::of($definition)->units($definition, $given));
	}
}
