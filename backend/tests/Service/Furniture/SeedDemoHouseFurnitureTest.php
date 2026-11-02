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
use Migrations\SeedDemoHouseFurnitureMigration;
use PHPUnit\Framework\Attributes\CoversClass;

/** The demo house's furniture migration adds its definitions and the cabinets' plinth grille, and takes both back. */
#[CoversClass(FurnitureSeeds::class)]
final class SeedDemoHouseFurnitureTest extends IntegrationTestCase
{
	public function testDownTakesTheGrilleOutOfTheCabinetsAndUpPutsItBack(): void
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);
		$migration = new SeedDemoHouseFurnitureMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));

		// The suite empties the definitions: seed the cabinets (from today's files), take the grille out, put it back.
		FurnitureSeeds::insert($database->getPdo(), SeedDemoHouseFurnitureMigration::Updated);
		self::assertSame([true, true], $this->cabinetsVented());
		$migration->down();
		self::assertSame([false, false], $this->cabinetsVented());
		self::assertSame(0, $this->seeded('Corner fireplace'));

		$migration->up();
		self::assertSame([true, true], $this->cabinetsVented());
		self::assertSame(1, $this->seeded('Corner fireplace'));
	}

	/** @return list<bool> whether each seeded kitchen cabinet has the plinthVent parameter, its slot and its parts */
	private function cabinetsVented(): array
	{
		$select = FurnitureSeeds::prepare(
			Fixture::service(DatabaseInterface::class)->getPdo(),
			"SELECT definition FROM furniture_definitions WHERE created_by_id IS NULL AND name IN ('Kitchen base cabinet', 'Kitchen tall cabinet') ORDER BY name",
		);
		$select->execute();

		return array_map(static function (array $row): bool {
			$json = (string) $row['definition'];
			$definition = JsonDecoder::decodeObject($json);
			self::assertIsArray($definition['parameters']);
			$keys = array_map(static fn (mixed $p): mixed => is_array($p) ? $p['key'] ?? null : null, $definition['parameters']);
			$vented = in_array('plinthVent', $keys, true);
			self::assertSame($vented, str_contains($json, 'Plinth grille slat'));
			self::assertSame($vented, str_contains($json, '"grille"'));

			return $vented;
		}, FurnitureSeeds::rows($select));
	}

	private function seeded(string $name): int
	{
		$select = FurnitureSeeds::prepare(
			Fixture::service(DatabaseInterface::class)->getPdo(),
			'SELECT COUNT(*) FROM furniture_definitions WHERE created_by_id IS NULL AND user_id IS NULL AND name = ?',
		);
		$select->execute([$name]);

		return (int) $select->fetchColumn();
	}
}
