<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture;

use Izbik\Service\Dbal\ReconnectableDatabase;
use Izbik\Service\Furniture\DefinitionEvaluator;
use Izbik\Service\Furniture\DefinitionException;
use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Furniture\ResolvedPart;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderFactory;
use Migrations\AddWorktopCutoutsMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The worktops, the sink and the hob get their `cutout` roles from their files, and the sink its bowls sunk into the
 * cut-out, and lose them with `down()`; both versions validate, and a definition with an unknown role does not.
 */
#[CoversClass(AddWorktopCutoutsMigration::class)]
#[CoversClass(DefinitionValidator::class)]
final class AddWorktopCutoutsTest extends IntegrationTestCase
{
	public function testGivesTheWorktopsSinkAndHobTheirCutoutRolesAndBack(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, AddWorktopCutoutsMigration::Updated);
		$migration = self::migration();

		$migration->down();
		foreach (['Worktop', 'L-shaped worktop', 'Island worktop with waterfall ends', 'Kitchen sink', 'Hob'] as $name) {
			self::assertStringNotContainsString('"cutout"', self::definition($pdo, $name));
		}

		$migration->up();
		foreach (['Worktop', 'L-shaped worktop', 'Island worktop with waterfall ends'] as $name) {
			self::assertStringContainsString('"cutout":"worktop"', self::definition($pdo, $name));
		}
		self::assertStringContainsString('"cutout":"sink"', self::definition($pdo, 'Kitchen sink'));
		self::assertStringContainsString('"cutout":"hob"', self::definition($pdo, 'Hob'));

		// An inset sink of one bowl: its rim a frame round the bowl, the bowl 180 mm down with its four sides.
		$evaluator = Fixture::service(DefinitionEvaluator::class);
		$sink = JsonDecoder::decodeObject(self::definition($pdo, 'Kitchen sink'));
		$parts = $evaluator->evaluate($sink, 800, 500, 6, []);
		self::assertSame(
			['Rim', 'Rim', 'Rim', 'Rim', 'Bowl', 'Bowl side', 'Bowl side', 'Bowl side', 'Bowl side'],
			array_map(static fn (ResolvedPart $part): string => $part->name, $parts),
		);
		$bowl = self::part($parts, 'Bowl');
		self::assertSame([40, -180, 40, 720, 420], [$bowl->x, $bowl->y, $bowl->z, $bowl->w, $bowl->d]);

		// A farmhouse sink keeps its bowl on top, with no sides and no rim.
		$parts = $evaluator->evaluate($sink, 800, 500, 220, ['style' => 'farmhouse']);
		self::assertSame(['Apron', 'Bowl'], array_map(static fn (ResolvedPart $part): string => $part->name, $parts));
		self::assertSame(220, self::part($parts, 'Bowl')->y);

		foreach (FurnitureSeeds::read(AddWorktopCutoutsMigration::Updated, AddWorktopCutoutsMigration::Before) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
		foreach (FurnitureSeeds::read(AddWorktopCutoutsMigration::Updated) as $seed) {
			Fixture::service(DefinitionValidator::class)->validate(JsonDecoder::decodeObject($seed['definition']));
		}
	}

	public function testRejectsAnUnknownCutoutRole(): void
	{
		$worktop = JsonDecoder::decodeObject(FurnitureSeeds::read(['worktop.json'])[0]['definition']);
		$worktop['cutout'] = 'tap';

		try {
			Fixture::service(DefinitionValidator::class)->validate($worktop);
			self::fail('An unknown cut-out role was accepted.');
		} catch (DefinitionException $exception) {
			self::assertSame('cutout', $exception->path);
			self::assertStringContainsString('Must be one of worktop, sink, hob.', $exception->getMessage());
		}
	}

	/** @param list<ResolvedPart> $parts */
	private static function part(array $parts, string $name): ResolvedPart
	{
		$found = array_values(array_filter($parts, static fn (ResolvedPart $part): bool => $part->name === $name));
		self::assertNotSame([], $found);

		return $found[0];
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

	private static function migration(): AddWorktopCutoutsMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new AddWorktopCutoutsMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}
