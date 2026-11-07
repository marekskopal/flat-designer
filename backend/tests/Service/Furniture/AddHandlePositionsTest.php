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
use Migrations\AddHandlePositionsMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/** The kitchen cabinets' fronts get a handle position from their files, and lose it with `down()`; both versions validate. */
#[CoversClass(AddHandlePositionsMigration::class)]
final class AddHandlePositionsTest extends IntegrationTestCase
{
	public function testGivesTheFrontsAHandlePositionAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddHandlePositionsMigration::Updated);
		$migration = self::migration();

		$migration->down();
		self::assertStringNotContainsString('handlePosition', self::definition($pdo, 'Kitchen tall cabinet'));

		$migration->up();
		foreach (['Kitchen base cabinet', 'Kitchen wall cabinet', 'Kitchen tall cabinet'] as $name) {
			self::assertStringContainsString('"handlePosition"', self::definition($pdo, $name));
		}

		foreach (FurnitureSeeds::read(AddHandlePositionsMigration::Updated, AddHandlePositionsMigration::Before) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
		foreach (FurnitureSeeds::read(AddHandlePositionsMigration::Updated) as $seed) {
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

	private static function migration(): AddHandlePositionsMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddHandlePositionsMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
