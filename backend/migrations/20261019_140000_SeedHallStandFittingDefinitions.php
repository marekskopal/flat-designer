<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Global furniture definitions of the hall stand's fittings, which were Box items: a hanging rail (between two sides,
 * hung from the board above or held out from the wall) and a coat peg.
 */
final class SeedHallStandFittingDefinitionsMigration extends Migration
{
	public const array Files = ['hanging-rail.json', 'coat-peg.json'];

	public function up(): void
	{
		FurnitureSeeds::insert($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}

	public function down(): void
	{
		FurnitureSeeds::delete($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}
}
