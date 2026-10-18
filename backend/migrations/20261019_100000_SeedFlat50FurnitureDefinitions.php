<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Global furniture definitions of the custom-made furniture of flat 50, built like the built-in kinds they will replace
 * (kitchen cabinets with real carcass boards, worktop, backsplash, sliding wardrobe, dining chair and table, panel);
 * the viewer's `parity.spec.ts` compares them with the built-in builders.
 */
final class SeedFlat50FurnitureDefinitionsMigration extends Migration
{
	public const array Files = [
		'kitchen-base-cabinet.json',
		'kitchen-wall-cabinet.json',
		'kitchen-tall-cabinet.json',
		'worktop.json',
		'backsplash.json',
		'sliding-wardrobe.json',
		'dining-chair.json',
		'dining-table.json',
		'panel.json',
	];

	public function up(): void
	{
		FurnitureSeeds::insert($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}

	public function down(): void
	{
		FurnitureSeeds::delete($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}
}
