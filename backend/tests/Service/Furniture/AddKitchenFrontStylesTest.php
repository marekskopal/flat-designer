<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture;

use Izbik\Service\Dbal\ReconnectableDatabase;
use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderFactory;
use Migrations\AddKitchenFrontStylesMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/** The kitchen cabinets' rows get front styles and handle kinds from their files, and lose them with `down()`; both versions validate. */
#[CoversClass(AddKitchenFrontStylesMigration::class)]
final class AddKitchenFrontStylesTest extends IntegrationTestCase
{
	public function testGivesTheKitchenCabinetsFrontStylesAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddKitchenFrontStylesMigration::Updated);
		$migration = self::migration();

		$migration->down();
		self::assertStringNotContainsString('frontStyle', self::definition($pdo, 'Kitchen base cabinet'));

		$migration->up();
		foreach (['Kitchen base cabinet', 'Kitchen wall cabinet', 'Kitchen tall cabinet'] as $name) {
			$definition = self::definition($pdo, $name);
			self::assertStringContainsString('"style":{"expr":"f.style ?? frontStyle"}', $definition);
			self::assertStringContainsString('"handleMaterial":"handles"', $definition);
		}

		foreach (FurnitureSeeds::read(AddKitchenFrontStylesMigration::Updated, AddKitchenFrontStylesMigration::Before) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
		foreach (FurnitureSeeds::read(AddKitchenFrontStylesMigration::Updated) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
	}

	private static function definition(PDO $pdo, string $name): string
	{
		$statement = FurnitureSeeds::prepare(
			$pdo,
			'SELECT definition FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL AND name = ? ORDER BY id DESC LIMIT 1',
		);
		$statement->execute([$name]);
		$rows = FurnitureSeeds::rows($statement);
		self::assertNotSame([], $rows);

		return (string) $rows[0]['definition'];
	}

	private static function migration(): AddKitchenFrontStylesMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddKitchenFrontStylesMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
