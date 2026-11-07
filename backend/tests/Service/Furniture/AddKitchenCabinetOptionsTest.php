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
use Migrations\AddKitchenCabinetOptionsMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/** The kitchen cabinets get the sink unit, larder, plinth and lighting options from their files, and lose them with `down()`; both versions validate. */
#[CoversClass(AddKitchenCabinetOptionsMigration::class)]
final class AddKitchenCabinetOptionsTest extends IntegrationTestCase
{
	public function testGivesTheKitchenCabinetsTheirOptionsAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddKitchenCabinetOptionsMigration::Updated);
		$migration = self::migration();

		$migration->down();
		self::assertStringNotContainsString('sinkUnit', self::definition($pdo, 'Kitchen base cabinet'));

		$migration->up();
		self::assertStringContainsString('"sinkUnit"', self::definition($pdo, 'Kitchen base cabinet'));
		self::assertStringContainsString('"Internal drawer front"', self::definition($pdo, 'Kitchen tall cabinet'));
		self::assertStringContainsString('"shelfLight"', self::definition($pdo, 'Kitchen wall cabinet'));

		foreach (FurnitureSeeds::read(AddKitchenCabinetOptionsMigration::Updated, AddKitchenCabinetOptionsMigration::Before) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
		foreach (FurnitureSeeds::read(AddKitchenCabinetOptionsMigration::Updated) as $seed) {
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

	private static function migration(): AddKitchenCabinetOptionsMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddKitchenCabinetOptionsMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
