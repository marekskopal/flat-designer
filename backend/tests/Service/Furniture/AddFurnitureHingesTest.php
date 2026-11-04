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
use Migrations\AddFurnitureHingesMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/** The outdoor doors' rows get butt hinges from their files, and lose them again with `down()`; both versions validate. */
#[CoversClass(AddFurnitureHingesMigration::class)]
final class AddFurnitureHingesTest extends IntegrationTestCase
{
	public function testGivesTheOutdoorDoorsButtHingesAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddFurnitureHingesMigration::Updated);
		$migration = self::migration();

		$migration->down();
		self::assertStringNotContainsString('hingeType', self::definition($pdo, 'Garden shed'));

		$migration->up();
		self::assertStringContainsString('"hingeType":"butt"', self::definition($pdo, 'Garden shed'));
		self::assertStringContainsString('"hingeType":"butt"', self::definition($pdo, 'Bin store'));

		foreach (FurnitureSeeds::read(AddFurnitureHingesMigration::Updated, AddFurnitureHingesMigration::Before) as $seed) {
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

	private static function migration(): AddFurnitureHingesMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddFurnitureHingesMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
