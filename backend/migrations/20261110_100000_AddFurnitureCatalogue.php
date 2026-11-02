<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\DefinitionEncoder;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Json\JsonDecoder;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * The global seeds get the `catalogue` their files now give them (where the editor's Add library files them),
 * patched in place after `format` and the electrical `category` and `schedule`, as in the files, so an admin's later
 * edits stay; a fresh database gets the files already, and a seed that has a catalogue keeps it. Copies in libraries and
 * projects get none (they are listed apart).
 */
final class AddFurnitureCatalogueMigration extends Migration
{
	public function up(): void
	{
		$catalogues = self::seedCatalogues();
		$this->patch(static function (array $definition, string $name) use ($catalogues): ?array {
			$catalogue = $catalogues[$name] ?? null;
			if ($catalogue === null || isset($definition['catalogue'])) {
				return null;
			}
			$head = array_intersect_key($definition, array_flip(['format', 'category', 'schedule']));

			return [...$head, 'catalogue' => $catalogue, ...array_diff_key($definition, $head)];
		});
	}

	public function down(): void
	{
		$this->patch(
			static fn (array $definition): ?array => isset($definition['catalogue'])
				? array_diff_key($definition, ['catalogue' => true])
				: null,
		);
	}

	/** @return array<string, string> the seed files' catalogues by seed name */
	private static function seedCatalogues(): array
	{
		$catalogues = [];
		foreach (FurnitureSeeds::read(FurnitureSeeds::files()) as $seed) {
			$catalogue = JsonDecoder::decodeObject($seed['definition'])['catalogue'] ?? null;
			if (is_string($catalogue)) {
				$catalogues[$seed['name']] = $catalogue;
			}
		}

		return $catalogues;
	}

	/** @param callable(array<mixed>, string): ?array<mixed> $change null leaves the row as it is */
	private function patch(callable $change): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$select = FurnitureSeeds::prepare(
			$pdo,
			'SELECT id, name, definition FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL AND created_by_id IS NULL',
		);
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE furniture_definitions SET definition = ? WHERE id = ?');
		$select->execute();
		foreach (FurnitureSeeds::rows($select) as $row) {
			$patched = $change(JsonDecoder::decodeObject((string) $row['definition']), (string) $row['name']);
			if ($patched !== null) {
				$update->execute([DefinitionEncoder::encode($patched), $row['id']]);
			}
		}
	}
}
