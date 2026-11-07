<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Kitchen appliances, round two: the cabinets' fronts may be a steam oven or a coffee machine and their appliance fronts
 * take a finish (`applianceFinish`: black, white, steel, retro); the sink's drainer goes right, left or behind the bowls
 * (`drainerSide`); the hood may be round (a cylinder on a rod) and the chimney hood's canopy a pyramid (`canopy`). The
 * rows are rewritten from the files; `down()` writes back the files as they were before (`furniture/before-appliances-2`).
 */
final class AddKitchenAppliancesRound2Migration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = [
		'kitchen-base-cabinet.json',
		'kitchen-wall-cabinet.json',
		'kitchen-tall-cabinet.json',
		'kitchen-sink.json',
		'range-hood.json',
	];

	public const string Before = __DIR__ . '/furniture/before-appliances-2';

	public function up(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated);
	}

	public function down(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated, self::Before);
	}
}
