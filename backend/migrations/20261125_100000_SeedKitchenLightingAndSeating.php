<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Kitchen lighting and seating: a pendant with a glass shade (globe, dome, drum), a ceiling track with spotlights
 * (both tagged lights for the electrical schedule) and a bar stool with an optional back.
 */
final class SeedKitchenLightingAndSeatingMigration extends Migration
{
	public const array Files = ['glass-pendant.json', 'track-light.json', 'bar-stool.json'];

	public function up(): void
	{
		FurnitureSeeds::insert($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}

	public function down(): void
	{
		FurnitureSeeds::delete($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}
}
