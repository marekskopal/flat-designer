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
use Migrations\AddKitchenAppliancesRound2Migration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The kitchen cabinets get the steam oven and coffee machine fronts and the appliance finish, the sink the drainer side
 * and the hood the round style and pyramid canopy from their files, and lose them with `down()`; both versions validate.
 */
#[CoversClass(AddKitchenAppliancesRound2Migration::class)]
final class AddKitchenAppliancesRound2Test extends IntegrationTestCase
{
	public function testGivesTheAppliancesTheirNewOptionsAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddKitchenAppliancesRound2Migration::Updated);
		$migration = self::migration();

		$migration->down();
		self::assertStringNotContainsString('applianceFinish', self::definition($pdo, 'Kitchen tall cabinet'));
		self::assertStringNotContainsString('drainerSide', self::definition($pdo, 'Kitchen sink'));

		$migration->up();
		foreach (['Kitchen base cabinet', 'Kitchen wall cabinet', 'Kitchen tall cabinet'] as $name) {
			self::assertStringContainsString('"applianceFinish"', self::definition($pdo, $name));
		}
		self::assertStringContainsString('"drainerSide"', self::definition($pdo, 'Kitchen sink'));
		self::assertStringContainsString('"Round body"', self::definition($pdo, 'Range hood'));

		// A left drainer: the ribs on the left, the bowl moved right of them.
		$evaluator = Fixture::service(DefinitionEvaluator::class);
		$sink = JsonDecoder::decodeObject(self::definition($pdo, 'Kitchen sink'));
		$parts = $evaluator->evaluate($sink, 1000, 500, 6, ['drainer' => true, 'drainerSide' => 'left']);
		$ribs = array_values(array_filter($parts, static fn (ResolvedPart $part): bool => $part->name === 'Drainer rib'));
		$bowl = array_values(array_filter($parts, static fn (ResolvedPart $part): bool => $part->name === 'Bowl'))[0];
		self::assertCount(8, $ribs);
		self::assertLessThan(450, max(array_map(static fn (ResolvedPart $part): int => $part->x, $ribs)));
		self::assertGreaterThanOrEqual(450, $bowl->x);

		// A round hood: a cylinder body and a rod up to the item's top.
		$hood = JsonDecoder::decodeObject(self::definition($pdo, 'Range hood'));
		$parts = $evaluator->evaluate($hood, 400, 400, 1200, ['style' => 'round']);
		self::assertSame(['Round body', 'Rod', 'Light'], array_map(static fn (ResolvedPart $part): string => $part->name, $parts));

		// The tall cabinet's fronts: a coffee machine in white.
		$tall = JsonDecoder::decodeObject(self::definition($pdo, 'Kitchen tall cabinet'));
		$parts = $evaluator->evaluate(
			$tall,
			600,
			560,
			2100,
			['applianceFinish' => 'white', 'fronts' => [['type' => 'door', 'height' => 800], ['type' => 'coffee', 'height' => 450], ['type' => 'door']]],
		);
		$appliances = array_values(array_filter($parts, static fn (ResolvedPart $part): bool => $part->type === 'appliance'));
		self::assertCount(1, $appliances);
		self::assertSame(['coffee', 'white'], [$appliances[0]->props['kind'], $appliances[0]->props['finish']]);

		foreach (FurnitureSeeds::read(AddKitchenAppliancesRound2Migration::Updated, AddKitchenAppliancesRound2Migration::Before) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
		foreach (FurnitureSeeds::read(AddKitchenAppliancesRound2Migration::Updated) as $seed) {
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

	private static function migration(): AddKitchenAppliancesRound2Migration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddKitchenAppliancesRound2Migration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
