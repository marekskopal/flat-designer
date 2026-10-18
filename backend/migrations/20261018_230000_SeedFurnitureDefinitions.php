<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\DefinitionValidator;
use FlatDesigner\Service\Json\JsonDecoder;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use RuntimeException;

/**
 * Global furniture definitions to start from (and examples of the format for people and agents): the JSON files in
 * `migrations/furniture`, each `{name, description, definition}`. `FurnitureSeedTest` (and the viewer's `custom.spec.ts`)
 * check that they validate and build.
 */
final class SeedFurnitureDefinitionsMigration extends Migration
{
	private const string Directory = __DIR__ . '/furniture';

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');
		foreach (self::seeds() as $seed) {
			$this->table('furniture_definitions')->insert([
				[
					'user_id' => null,
					'project_id' => null,
					'name' => $seed['name'],
					'description' => $seed['description'],
					'definition' => $seed['definition'],
					'created_by_id' => null,
					'created_at' => $now,
					'updated_at' => $now,
				],
			]);
		}
	}

	public function down(): void
	{
		$statement = $this->databaseProvider->getDatabase()->getPdo()->prepare(
			'DELETE FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL AND name = ?',
		);
		if ($statement === false) {
			throw new RuntimeException('Cannot prepare the delete of the seeded furniture definitions.');
		}
		foreach (self::seeds() as $seed) {
			$statement->execute([$seed['name']]);
		}
	}

	/** @return list<array{name: string, description: string, definition: string}> the definition as stored JSON */
	private static function seeds(): array
	{
		$files = glob(self::Directory . '/*.json');
		$seeds = [];
		foreach ($files === false ? [] : $files as $file) {
			$seed = JsonDecoder::decodeObject((string) file_get_contents($file));
			$name = $seed['name'] ?? null;
			$description = $seed['description'] ?? null;
			$definition = $seed['definition'] ?? null;
			if (!is_string($name) || !is_string($description) || !is_array($definition)) {
				throw new RuntimeException(sprintf('%s must be {name, description, definition}.', basename($file)));
			}
			$seeds[] = ['name' => $name, 'description' => $description, 'definition' => DefinitionValidator::encode($definition)];
		}

		return $seeds;
	}
}
