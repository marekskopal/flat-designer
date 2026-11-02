<?php

declare(strict_types=1);

namespace Migrations;

use Closure;
use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Json\JsonDecoder;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use PDO;

/**
 * The demo house's furniture: a corner fireplace, a bench with an upholstered back, a pouf, an oak linear pendant,
 * square ceiling and linear wall lights, a vanity with vessel basins, a walk-in rain shower, a freestanding bath, a bin
 * store, a garden shed, stepping slabs and a roller blind in a cassette. The kitchen cabinets get an optional plinth
 * grille (`plinthVent`); their rows are rewritten from the files, which a fresh database already seeds so.
 */
final class SeedDemoHouseFurnitureMigration extends Migration
{
	public const array Files = [
		'corner-fireplace.json',
		'upholstered-bench.json',
		'pouf.json',
		'linear-pendant.json',
		'square-ceiling-light.json',
		'linear-wall-light.json',
		'vessel-vanity.json',
		'rain-shower.json',
		'freestanding-bath.json',
		'bin-store.json',
		'garden-shed.json',
		'stepping-slabs.json',
		'roller-blind-cassette.json',
	];

	/** Seeded earlier, changed here. */
	public const array Updated = ['kitchen-base-cabinet.json', 'kitchen-tall-cabinet.json'];

	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		FurnitureSeeds::insert($pdo, self::Files);
		FurnitureSeeds::update($pdo, self::Updated);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		FurnitureSeeds::delete($pdo, self::Files);
		self::removePlinthVents($pdo);
	}

	/**
	 * The list's entries but those `$drop` matches.
	 *
	 * @param Closure(array<mixed>): bool $drop
	 * @return list<mixed>
	 */
	private static function without(mixed $list, Closure $drop): array
	{
		return is_array($list)
			? array_values(
				array_filter($list, static fn (mixed $entry): bool => !is_array($entry) || !$drop($entry)),
			)
			: [];
	}

	/** Takes the plinth grille out of the cabinets' rows again: its parameter, slot, derived count and parts. */
	private static function removePlinthVents(PDO $pdo): void
	{
		$select = FurnitureSeeds::prepare(
			$pdo,
			"SELECT id, description, definition FROM furniture_definitions
			WHERE user_id IS NULL AND project_id IS NULL AND created_by_id IS NULL AND name IN ('Kitchen base cabinet', 'Kitchen tall cabinet')",
		);
		$select->execute();
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE furniture_definitions SET description = ?, definition = ? WHERE id = ?');
		foreach (FurnitureSeeds::rows($select) as $row) {
			$definition = JsonDecoder::decodeObject((string) $row['definition']);
			$definition['parameters'] = self::without(
				$definition['parameters'] ?? [],
				static fn (array $entry): bool => ($entry['key'] ?? null) === 'plinthVent',
			);
			$definition['materialSlots'] = self::without(
				$definition['materialSlots'] ?? [],
				static fn (array $entry): bool => ($entry['key'] ?? null) === 'grille',
			);
			$definition['parts'] = self::without(
				$definition['parts'] ?? [],
				static fn (array $part): bool => is_string($part['name'] ?? null) && str_starts_with($part['name'], 'Plinth grille'),
			);
			if (is_array($definition['derived'] ?? null)) {
				unset($definition['derived']['slats']);
			}
			$description = str_replace(
				' plinthVent puts a ventilation grille in the plinth (under an oven or a fridge).',
				'',
				(string) $row['description'],
			);
			$update->execute([$description, DefinitionValidator::encode($definition), $row['id']]);
		}
	}
}
