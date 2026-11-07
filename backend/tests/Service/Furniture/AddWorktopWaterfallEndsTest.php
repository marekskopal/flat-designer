<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture;

use Izbik\Service\Dbal\ReconnectableDatabase;
use Izbik\Service\Furniture\DefinitionEvaluator;
use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Furniture\ResolvedPart;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderFactory;
use Migrations\AddWorktopWaterfallEndsMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/** The straight and L-shaped worktops get waterfall ends from their files, and lose them with `down()`; both versions validate. */
#[CoversClass(AddWorktopWaterfallEndsMigration::class)]
final class AddWorktopWaterfallEndsTest extends IntegrationTestCase
{
	public function testGivesTheWorktopsWaterfallEndsAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddWorktopWaterfallEndsMigration::Updated);
		$migration = self::migration();

		$migration->down();
		self::assertStringNotContainsString('waterfall', self::definition($pdo, 'Worktop'));

		$migration->up();
		self::assertStringContainsString('"Waterfall end"', self::definition($pdo, 'Worktop'));
		self::assertStringContainsString('"Waterfall end"', self::definition($pdo, 'L-shaped worktop'));

		// A 38 mm top 2000 wide with a waterfall on the right, 872 down: the slab ends where the end piece starts.
		$evaluator = Fixture::service(DefinitionEvaluator::class);
		$worktop = JsonDecoder::decodeObject(self::definition($pdo, 'Worktop'));
		$parts = $evaluator->evaluate($worktop, 2000, 620, 38, ['waterfall' => 'right']);
		self::assertSame(
			[['Slab', 0, 0, 1962, 38], ['Waterfall end', 1962, -872, 38, 910]],
			array_map(static fn (ResolvedPart $part): array => [$part->name, $part->x, $part->y, $part->w, $part->h], $parts),
		);

		// The L's return turning down at its free end (the front): the return stops short of it.
		$l = JsonDecoder::decodeObject(self::definition($pdo, 'L-shaped worktop'));
		$parts = $evaluator->evaluate($l, 2100, 1580, 20, ['returnSide' => 'left', 'waterfall' => 'return']);
		self::assertSame(
			[['Run', 0, 620], ['Return', 620, 940], ['Waterfall end', 1560, 20]],
			array_map(static fn (ResolvedPart $part): array => [$part->name, $part->z, $part->d], $parts),
		);

		foreach (FurnitureSeeds::read(AddWorktopWaterfallEndsMigration::Updated, AddWorktopWaterfallEndsMigration::Before) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
		foreach (FurnitureSeeds::read(AddWorktopWaterfallEndsMigration::Updated) as $seed) {
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

	private static function migration(): AddWorktopWaterfallEndsMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddWorktopWaterfallEndsMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
