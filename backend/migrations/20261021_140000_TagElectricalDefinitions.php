<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\DefinitionEncoder;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Service\Json\JsonDecoder;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * The global definitions of `SeedLightingCatalogue` get the `category` the electrical schedule counts them by (FD-50).
 * Patched in place, so an admin's later edits stay; on a fresh database the seed files already carry it. Copies in
 * libraries and projects are left as they are.
 */
final class TagElectricalDefinitionsMigration extends Migration
{
	/** seed name => category */
	private const array Categories = [
		'Pendant, bare bulb' => 'light',
		'Downlight, recessed round' => 'light',
		'Light source' => 'light',
		'Socket outlet (Schuko)' => 'socket',
		'Light switch (rocker)' => 'switch',
		'Data socket (RJ45)' => 'data',
		'Plumbing point' => 'plumbing',
	];

	public function up(): void
	{
		// After `format`, as in the seed files; a category already there stays.
		$this->patch(
			static fn (array $definition, string $category): array => ['format' => 1, 'category' => $category, ...$definition],
		);
	}

	public function down(): void
	{
		$this->patch(static fn (array $definition): array => array_diff_key($definition, ['category' => true]));
	}

	/** @param callable(array<mixed>, string): array<mixed> $change */
	private function patch(callable $change): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$select = FurnitureSeeds::prepare(
			$pdo,
			'SELECT id, definition FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL AND name = ?',
		);
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE furniture_definitions SET definition = ? WHERE id = ?');
		foreach (self::Categories as $name => $category) {
			$select->execute([$name]);
			foreach (FurnitureSeeds::rows($select) as $row) {
				$definition = JsonDecoder::decodeObject((string) $row['definition']);
				$update->execute([DefinitionEncoder::encode($change($definition, $category)), $row['id']]);
			}
		}
	}
}
