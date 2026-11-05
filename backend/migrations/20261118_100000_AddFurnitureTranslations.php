<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * The seeded furniture's texts in Czech: every seed file got a `translations` block (`DefinitionTranslations`), written
 * over the seeded rows here (a fresh database seeds the files so already); `down()` writes them back without it.
 */
final class AddFurnitureTranslationsMigration extends Migration
{
	public function up(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), FurnitureSeeds::files());
	}

	public function down(): void
	{
		FurnitureSeeds::update(
			$this->databaseProvider->getDatabase()->getPdo(),
			FurnitureSeeds::files(),
			change: static function (array $definition): array {
				unset($definition['translations']);

				return $definition;
			},
		);
	}
}
