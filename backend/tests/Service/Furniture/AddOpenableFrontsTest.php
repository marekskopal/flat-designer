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
use Migrations\AddOpenableFrontsMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/** The cabinets' rows get the openable fronts of their files, and lose them again with `down()`; both versions validate. */
#[CoversClass(AddOpenableFrontsMigration::class)]
final class AddOpenableFrontsTest extends IntegrationTestCase
{
	public function testRewritesTheCabinetsAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddOpenableFrontsMigration::Updated);
		$migration = self::migration();

		$migration->down();
		$before = self::definition($pdo, 'Kitchen base cabinet');
		self::assertNotContains('softClose', array_column(self::list($before['parameters'] ?? null), 'key'));

		$migration->up();
		$after = self::definition($pdo, 'Kitchen base cabinet');
		self::assertContains('softClose', array_column(self::list($after['parameters'] ?? null), 'key'));
		self::assertStringContainsString('"opens":"slide"', (string) json_encode($after));

		foreach (FurnitureSeeds::read(AddOpenableFrontsMigration::Updated, AddOpenableFrontsMigration::Before) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
	}

	/** @return list<array<string, mixed>> */
	private static function list(mixed $value): array
	{
		self::assertIsList($value);
		$entries = [];
		foreach ($value as $entry) {
			self::assertIsArray($entry);
			$fields = [];
			foreach ($entry as $key => $field) {
				$fields[(string) $key] = $field;
			}
			$entries[] = $fields;
		}

		return $entries;
	}

	/** @return array<string, mixed> */
	private static function definition(PDO $pdo, string $name): array
	{
		$statement = FurnitureSeeds::prepare(
			$pdo,
			'SELECT definition FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL AND name = ? ORDER BY id DESC LIMIT 1',
		);
		$statement->execute([$name]);
		$rows = FurnitureSeeds::rows($statement);
		self::assertNotSame([], $rows);

		return JsonDecoder::decodeObject((string) $rows[0]['definition']);
	}

	private static function migration(): AddOpenableFrontsMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddOpenableFrontsMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
