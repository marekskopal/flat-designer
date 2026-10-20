<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Furniture;

use FlatDesigner\Service\Furniture\DefinitionValidator;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use Migrations\SeedFlat50FurnitureDefinitionsMigration;
use Migrations\SeedFurnitureCatalogueMigration;
use Migrations\SeedFurnitureDefinitionsMigration;
use Migrations\SeedHallStandFittingDefinitionsMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/** The global furniture definitions seeded by `SeedFurnitureDefinitionsMigration` pass the validator (material codes included). */
#[CoversClass(DefinitionValidator::class)]
#[CoversClass(FurnitureSeeds::class)]
final class FurnitureSeedTest extends IntegrationTestCase
{
	/** @return iterable<string, array{string}> */
	public static function seeds(): iterable
	{
		$files = glob(__DIR__ . '/../../../migrations/furniture/*.json');
		self::assertNotFalse($files);
		foreach ($files as $file) {
			yield basename($file) => [$file];
		}
	}

	/** Every file in the directory is seeded by exactly one migration, and every listed file exists. */
	public function testEveryFileBelongsToOneSeedMigration(): void
	{
		$listed = [
			...SeedFurnitureDefinitionsMigration::Files,
			...SeedFlat50FurnitureDefinitionsMigration::Files,
			...SeedHallStandFittingDefinitionsMigration::Files,
			...SeedFurnitureCatalogueMigration::Files,
		];
		sort($listed);
		self::assertSame(array_unique($listed), $listed, 'A file is listed by more than one migration.');
		self::assertSame(FurnitureSeeds::files(), $listed);
	}

	#[DataProvider('seeds')]
	public function testSeedValidates(string $file): void
	{
		$seed = JsonDecoder::decodeObject((string) file_get_contents($file));
		self::assertIsString($seed['name'] ?? null);
		self::assertIsString($seed['description'] ?? null);
		$definition = $seed['definition'] ?? null;
		self::assertIsArray($definition);

		$parts = Fixture::service(DefinitionValidator::class)->validate($definition)->parts;

		self::assertGreaterThan(0, count($parts));
	}
}
