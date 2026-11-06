<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture;

use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use Migrations\AddPendantClustersMigration;
use Migrations\AddSeatingAndLightingRound2Migration;
use Migrations\SeedDecorCatalogueMigration;
use Migrations\SeedDemoHouseFurnitureMigration;
use Migrations\SeedFlat50FurnitureDefinitionsMigration;
use Migrations\SeedFurnitureCatalogueMigration;
use Migrations\SeedFurnitureDefinitionsMigration;
use Migrations\SeedHallStandFittingDefinitionsMigration;
use Migrations\SeedKitchenAppliancesMigration;
use Migrations\SeedKitchenLightingAndSeatingMigration;
use Migrations\SeedKitchenWorktopsMigration;
use Migrations\SeedLightingCatalogueMigration;
use Migrations\SeedRoofAccessoriesMigration;
use Migrations\SeedSlattedPanelMigration;
use Migrations\SeedTrapezoidWardrobeMigration;
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
			...SeedDecorCatalogueMigration::Files,
			...SeedLightingCatalogueMigration::Files,
			...SeedRoofAccessoriesMigration::Files,
			...SeedDemoHouseFurnitureMigration::Files,
			...SeedTrapezoidWardrobeMigration::Files,
			...SeedKitchenWorktopsMigration::Files,
			...SeedKitchenAppliancesMigration::Files,
			...SeedKitchenLightingAndSeatingMigration::Files,
			...SeedSlattedPanelMigration::Files,
			...AddSeatingAndLightingRound2Migration::Files,
			...AddPendantClustersMigration::Files,
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
