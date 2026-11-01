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
use Migrations\AddElectricalScheduleSettingsMigration;
use Migrations\SeedDemoHouseFurnitureMigration;
use Migrations\SeedLightingCatalogueMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The tagged seeds lose their schedule settings and get them back, the rest of the JSON unchanged; a library copy of
 * the socket gets its count, an untagged one nothing; a seed an admin has changed gets only the settings its parameters
 * still allow.
 */
#[CoversClass(AddElectricalScheduleSettingsMigration::class)]
final class AddElectricalScheduleSettingsTest extends IntegrationTestCase
{
	public function testRemovesAndRestoresTheSettings(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, [...SeedLightingCatalogueMigration::Files, ...SeedDemoHouseFurnitureMigration::Files]);
		$seeded = self::definitions($pdo, 'user_id IS NULL');
		self::assertSame(
			['count' => 'gangs', 'values' => ['gangs']],
			JsonDecoder::decodeObject($seeded['Socket outlet (Schuko)'])['schedule'] ?? null,
		);
		self::assertCount(10, array_filter($seeded, static fn (string $json): bool => str_contains($json, '"schedule"')));

		$user = Fixture::createUser();
		$socket = JsonDecoder::decodeObject($seeded['Socket outlet (Schuko)']);
		unset($socket['schedule']);
		$untagged = $socket;
		unset($untagged['category']);
		$insert = FurnitureSeeds::prepare(
			$pdo,
			"INSERT INTO furniture_definitions (user_id, name, description, definition, created_at, updated_at)
			VALUES (?, ?, '', ?, NOW(), NOW())",
		);
		$insert->execute([$user->id, 'My socket', json_encode($socket)]);
		$insert->execute([$user->id, 'My untagged socket', json_encode($untagged)]);

		$migration = self::migration();
		$migration->down();
		foreach (self::definitions($pdo, '1') as $json) {
			self::assertArrayNotHasKey('schedule', JsonDecoder::decodeObject($json));
		}
		$migration->up();

		self::assertSame($seeded, self::definitions($pdo, 'user_id IS NULL'));
		$copies = self::definitions($pdo, 'user_id IS NOT NULL');
		self::assertSame(['count' => 'gangs'], JsonDecoder::decodeObject($copies['My socket'])['schedule'] ?? null);
		self::assertArrayNotHasKey('schedule', JsonDecoder::decodeObject($copies['My untagged socket']));
	}

	public function testLeavesOutTheParametersAnAdminRemoved(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, SeedLightingCatalogueMigration::Files);
		$migration = self::migration();
		$migration->down();

		$downlight = JsonDecoder::decodeObject(self::definitions($pdo, 'user_id IS NULL')['Downlight, recessed round']);
		self::assertIsArray($downlight['parameters']);
		$downlight['parameters'] = array_values(array_filter(
			$downlight['parameters'],
			static fn (mixed $p): bool => !is_array($p) || ($p['key'] ?? null) !== 'angle',
		));
		$socket = JsonDecoder::decodeObject(self::definitions($pdo, 'user_id IS NULL')['Socket outlet (Schuko)']);
		self::assertIsArray($socket['parameters']);
		$socket['parameters'] = array_values(array_filter(
			$socket['parameters'],
			static fn (mixed $p): bool => !is_array($p) || ($p['key'] ?? null) !== 'gangs',
		));
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE furniture_definitions SET definition = ? WHERE name = ? AND user_id IS NULL');
		$update->execute([json_encode($downlight), 'Downlight, recessed round']);
		$update->execute([json_encode($socket), 'Socket outlet (Schuko)']);

		$migration->up();

		$seeded = self::definitions($pdo, 'user_id IS NULL');
		self::assertSame(
			['values' => ['lumens', 'kelvin']],
			JsonDecoder::decodeObject($seeded['Downlight, recessed round'])['schedule'] ?? null,
		);
		self::assertArrayNotHasKey('schedule', JsonDecoder::decodeObject($seeded['Socket outlet (Schuko)']));
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

	private static function migration(): AddElectricalScheduleSettingsMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddElectricalScheduleSettingsMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
