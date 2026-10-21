<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Furniture;

use FlatDesigner\Service\Dbal\ReconnectableDatabase;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderFactory;
use Migrations\SeedLightingCatalogueMigration;
use Migrations\TagElectricalDefinitionsMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/** The seeded lighting definitions lose their category and get it back, the rest of the JSON unchanged. */
#[CoversClass(TagElectricalDefinitionsMigration::class)]
final class TagElectricalDefinitionsTest extends IntegrationTestCase
{
	public function testRemovesAndRestoresTheCategories(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, SeedLightingCatalogueMigration::Files);
		$seeded = self::definitions($pdo);
		self::assertSame(
			[
				'Data socket (RJ45)' => 'data',
				'Downlight, recessed round' => 'light',
				'Light source' => 'light',
				'Light switch (rocker)' => 'switch',
				'Pendant, bare bulb' => 'light',
				'Plumbing point' => 'plumbing',
				'Socket outlet (Schuko)' => 'socket',
			],
			array_map(static fn (string $json): mixed => JsonDecoder::decodeObject($json)['category'] ?? null, $seeded),
		);

		$migration = self::migration();
		$migration->down();
		foreach (self::definitions($pdo) as $json) {
			self::assertArrayNotHasKey('category', JsonDecoder::decodeObject($json));
		}
		$migration->up();

		self::assertSame($seeded, self::definitions($pdo));
	}

	/** @return array<string, string> the stored JSON by name */
	private static function definitions(PDO $pdo): array
	{
		$statement = FurnitureSeeds::prepare($pdo, 'SELECT name, definition FROM furniture_definitions ORDER BY name');
		$statement->execute();
		$definitions = [];
		foreach (FurnitureSeeds::rows($statement) as $row) {
			$definitions[(string) $row['name']] = (string) $row['definition'];
		}

		return $definitions;
	}

	private static function migration(): TagElectricalDefinitionsMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new TagElectricalDefinitionsMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
