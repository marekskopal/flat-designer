<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture;

use Izbik\Service\Dbal\ReconnectableDatabase;
use Izbik\Service\Furniture\DefinitionEvaluator;
use Izbik\Service\Furniture\DefinitionSchedule;
use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Furniture\ResolvedPart;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderFactory;
use Migrations\AddPendantClustersMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The glass pendant gets its cone, teardrop and wire cage shades and the pendant cluster is seeded, and `down()` takes
 * them away again; both versions validate.
 */
#[CoversClass(AddPendantClustersMigration::class)]
final class AddPendantClustersTest extends IntegrationTestCase
{
	public function testGivesThePendantNewShadesAndSeedsTheClusterAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddPendantClustersMigration::Updated);
		$migration = self::migration();

		$migration->down();
		self::assertStringNotContainsString('teardrop', self::definition($pdo, 'Pendant with a glass shade'));
		self::assertSame([], self::rows($pdo, 'Pendant cluster'));

		$migration->up();
		self::assertStringContainsString('teardrop', self::definition($pdo, 'Pendant with a glass shade'));
		self::assertCount(1, self::rows($pdo, 'Pendant cluster'));

		$evaluator = Fixture::service(DefinitionEvaluator::class);

		// A cone shade narrows to the top; a wire cage is drawn in the holder's material.
		$pendant = JsonDecoder::decodeObject(self::definition($pdo, 'Pendant with a glass shade'));
		$cone = $evaluator->evaluate($pendant, 300, 300, 1000, ['shape' => 'cone'])[0];
		self::assertSame(['cylinder', 'cone', 0, 210], [$cone->type, $cone->props['profile'], $cone->props['wire'], $cone->h]);
		$cage = $evaluator->evaluate($pendant, 300, 300, 1000, ['shape' => 'wire'])[0];
		self::assertSame(['straight', 8, 'holder'], [$cage->props['profile'], $cage->props['wire'], $cage->material]);

		// Four drops: four shades, four bulbs, four cables; every other one hangs the stagger higher.
		$cluster = JsonDecoder::decodeObject(self::definition($pdo, 'Pendant cluster'));
		$parts = $evaluator->evaluate($cluster, 1200, 200, 1200, ['drops' => 4, 'shape' => 'teardrop', 'stagger' => 200]);
		$named = static fn (string $name): array => array_values(
			array_filter($parts, static fn (ResolvedPart $p): bool => $p->name === $name),
		);
		self::assertCount(1, $named('Canopy'));
		self::assertCount(4, $named('Shade'));
		self::assertCount(4, $named('Bulb'));
		self::assertCount(4, $named('Cable'));
		self::assertSame([0, 200, 0, 200], array_map(static fn (ResolvedPart $p): int => $p->y, $named('Shade')));
		self::assertSame([150 - 90, 450 - 90, 750 - 90, 1050 - 90], array_map(static fn (ResolvedPart $p): int => $p->x, $named('Shade')));
		// Each cable reaches the canopy's underside.
		foreach ($named('Cable') as $cable) {
			self::assertSame(1200 - 40, $cable->y + $cable->h);
			self::assertFalse($cable->props['ceiling']);
		}
		// A round canopy: the drops stand round its centre.
		$round = $evaluator->evaluate($cluster, 500, 500, 1000, ['drops' => 3, 'canopy' => 'round']);
		$shades = array_values(array_filter($round, static fn (ResolvedPart $p): bool => $p->name === 'Shade'));
		self::assertSame([250 + 160 - 90, 250 - 90], [$shades[0]->x, $shades[0]->z]);
		// The schedule counts the drops.
		self::assertSame(4, DefinitionSchedule::of($cluster)->units($cluster, ['drops' => 4]));

		foreach (FurnitureSeeds::read(AddPendantClustersMigration::Updated, AddPendantClustersMigration::Before) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
		foreach (FurnitureSeeds::read([...AddPendantClustersMigration::Updated, ...AddPendantClustersMigration::Files]) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
	}

	/** @return list<array<string, mixed>> the global rows of that name */
	private static function rows(PDO $pdo, string $name): array
	{
		$statement = FurnitureSeeds::prepare(
			$pdo,
			'SELECT definition FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL AND name = ? AND deleted_at IS NULL',
		);
		$statement->execute([$name]);

		return FurnitureSeeds::rows($statement);
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

	private static function migration(): AddPendantClustersMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddPendantClustersMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
