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
use Migrations\AddSeatingAndLightingRound2Migration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The stool, pendant, chair and tables get their new options and the shell chair is seeded, and `down()` takes them
 * away again; both versions validate.
 */
#[CoversClass(AddSeatingAndLightingRound2Migration::class)]
final class AddSeatingAndLightingRound2Test extends IntegrationTestCase
{
	public function testGivesSeatingAndLightingTheirNewOptionsAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddSeatingAndLightingRound2Migration::Updated);
		$migration = self::migration();

		$migration->down();
		self::assertStringNotContainsString('shadeHeight', self::definition($pdo, 'Pendant with a glass shade'));
		self::assertSame([], self::rows($pdo, 'Shell chair'));

		$migration->up();
		self::assertStringContainsString('"ring"', self::definition($pdo, 'Bar stool'));
		self::assertStringContainsString('"legs"', self::definition($pdo, 'Dining chair'));
		self::assertCount(1, self::rows($pdo, 'Shell chair'));

		$evaluator = Fixture::service(DefinitionEvaluator::class);
		$names = static fn (array $parts): array => array_map(static fn (ResolvedPart $part): string => $part->name, $parts);

		// A stool without a footrest; an oval table: a middle block and two round ends.
		$stool = JsonDecoder::decodeObject(self::definition($pdo, 'Bar stool'));
		self::assertNotContains('Footrest', $names($evaluator->evaluate($stool, 400, 400, 900, ['footrest' => 0])));
		// By default the backrest keeps the seat's material and the footrest the frame's, as before.
		$materials = [];
		foreach ($evaluator->evaluate($stool, 400, 400, 900) as $part) {
			$materials[$part->name] = $part->material;
		}
		self::assertSame(['seat', 'frame'], [$materials['Backrest'], $materials['Footrest']]);
		$table = JsonDecoder::decodeObject(self::definition($pdo, 'Round table'));
		self::assertSame(
			['Top', 'Top end', 'Top end', 'Pedestal', 'Foot disc'],
			$names($evaluator->evaluate($table, 1600, 900, 750, ['shape' => 'oval', 'pedestal' => 300])),
		);

		// A flat disc shade 60 mm high, 500 wide.
		$pendant = JsonDecoder::decodeObject(self::definition($pdo, 'Pendant with a glass shade'));
		$shade = $evaluator->evaluate($pendant, 500, 500, 1000, ['shape' => 'disc', 'shadeHeight' => 60])[0];
		self::assertSame([500, 60], [$shade->w, $shade->h]);

		$chair = JsonDecoder::decodeObject(self::definition($pdo, 'Shell chair'));
		self::assertContains('Foot disc', $names($evaluator->evaluate($chair, 520, 560, 800, ['base' => 'swivel', 'arms' => true])));

		foreach (FurnitureSeeds::read(
			AddSeatingAndLightingRound2Migration::Updated,
			AddSeatingAndLightingRound2Migration::Before,
		) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
		foreach (FurnitureSeeds::read(
			[...AddSeatingAndLightingRound2Migration::Updated, ...AddSeatingAndLightingRound2Migration::Files],
		) as $seed) {
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

	private static function migration(): AddSeatingAndLightingRound2Migration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddSeatingAndLightingRound2Migration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
