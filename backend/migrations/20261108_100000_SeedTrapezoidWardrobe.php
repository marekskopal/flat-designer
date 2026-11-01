<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** A hinged wardrobe with one end slanted along a diagonal wall, its top a trapezoid (FD-57). */
final class SeedTrapezoidWardrobeMigration extends Migration
{
	public const array Files = ['trapezoid-wardrobe.json'];

	public function up(): void
	{
		FurnitureSeeds::insert($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}

	public function down(): void
	{
		FurnitureSeeds::delete($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}
}
