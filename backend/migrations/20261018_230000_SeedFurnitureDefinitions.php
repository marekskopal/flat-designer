<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Global furniture definitions to start from (and examples of the format for people and agents), from
 * `migrations/furniture`. `FurnitureSeedTest` (and the viewer's `custom.spec.ts`) check that every seed validates and
 * builds, and that every file in the directory belongs to exactly one seed migration. (This migration first read the
 * whole directory; it lists its files since `SeedFlat50FurnitureDefinitions` added more, which is the same for every
 * database it had run on, as those files arrived together with the split.)
 */
final class SeedFurnitureDefinitionsMigration extends Migration
{
	public const array Files = ['bed-frame.json', 'desk.json', 'open-shelf.json', 'sideboard.json', 'wall-shelf.json'];

	public function up(): void
	{
		FurnitureSeeds::insert($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}

	public function down(): void
	{
		FurnitureSeeds::delete($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}
}
