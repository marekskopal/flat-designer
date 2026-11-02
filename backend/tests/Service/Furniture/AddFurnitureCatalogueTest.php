<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture;

use Izbik\Service\Dbal\ReconnectableDatabase;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderFactory;
use Migrations\AddFurnitureCatalogueMigration;
use Migrations\SeedDecorCatalogueMigration;
use Migrations\SeedLightingCatalogueMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Every seed file names its catalogue; the seeds lose it and get it back after `format`, `category` and `schedule`, the rest of the JSON
 * unchanged, a catalogue an admin chose stays, and a library copy gets none.
 */
#[CoversClass(AddFurnitureCatalogueMigration::class)]
final class AddFurnitureCatalogueTest extends IntegrationTestCase
{
	public function testEverySeedFileNamesItsCatalogue(): void
	{
		foreach (FurnitureSeeds::read(FurnitureSeeds::files()) as $seed) {
			self::assertIsString(JsonDecoder::decodeObject($seed['definition'])['catalogue'] ?? null, $seed['name']);
		}
	}

	public function testRemovesAndRestoresTheCatalogue(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, [...SeedLightingCatalogueMigration::Files, ...SeedDecorCatalogueMigration::Files]);
		$seeded = self::definitions($pdo, 'user_id IS NULL');
		self::assertSame('lighting', JsonDecoder::decodeObject($seeded['Pendant, bare bulb'])['catalogue'] ?? null);

		$copy = JsonDecoder::decodeObject($seeded['Rug']);
		unset($copy['catalogue']);
		FurnitureSeeds::prepare(
			$pdo,
			"INSERT INTO furniture_definitions (user_id, name, description, definition, created_at, updated_at)
			VALUES (?, 'My rug', '', ?, NOW(), NOW())",
		)->execute([Fixture::createUser()->id, json_encode($copy)]);

		$migration = self::migration();
		$migration->down();
		foreach (self::definitions($pdo, '1') as $json) {
			self::assertArrayNotHasKey('catalogue', JsonDecoder::decodeObject($json));
		}
		// An admin files the rug elsewhere before the migration runs: that choice stays.
		$rug = JsonDecoder::decodeObject(self::definitions($pdo, 'user_id IS NULL')['Rug']);
		$rug = [...$rug, 'catalogue' => 'bedroom'];
		FurnitureSeeds::prepare($pdo, "UPDATE furniture_definitions SET definition = ? WHERE name = 'Rug' AND user_id IS NULL")
			->execute([json_encode($rug)]);

		$migration->up();

		$restored = self::definitions($pdo, 'user_id IS NULL');
		self::assertSame('bedroom', JsonDecoder::decodeObject($restored['Rug'])['catalogue'] ?? null);
		unset($seeded['Rug'], $restored['Rug']);
		self::assertSame($seeded, $restored);
		self::assertSame(
			['format', 'category', 'schedule', 'catalogue', 'size'],
			array_slice(array_keys(JsonDecoder::decodeObject($restored['Pendant, bare bulb'])), 0, 5),
		);
		self::assertArrayNotHasKey('catalogue', JsonDecoder::decodeObject(self::definitions($pdo, 'user_id IS NOT NULL')['My rug']));
	}

	/** @return array<string, string> the stored JSON by name */
	private static function definitions(PDO $pdo, string $where): array
	{
		$statement = FurnitureSeeds::prepare(
			$pdo,
			sprintf('SELECT name, definition FROM furniture_definitions WHERE %s ORDER BY name', $where),
		);
		$statement->execute();
		$definitions = [];
		foreach (FurnitureSeeds::rows($statement) as $row) {
			$definitions[(string) $row['name']] = (string) $row['definition'];
		}

		return $definitions;
	}

	private static function migration(): AddFurnitureCatalogueMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddFurnitureCatalogueMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
