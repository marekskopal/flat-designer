<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Kitchen appliances and fittings as definitions: a mixer tap, a range hood (chimney, box or integrated), a hob
 * (induction, gas or with a downdraft vent), a sink (inset, undermount or farmhouse), a freestanding fridge (modern,
 * retro or American), a coffee machine, a range cooker and a wine cooler. Generic shapes, no branded models.
 */
final class SeedKitchenAppliancesMigration extends Migration
{
	public const array Files = [
		'kitchen-tap.json',
		'range-hood.json',
		'hob.json',
		'kitchen-sink.json',
		'freestanding-fridge.json',
		'coffee-machine.json',
		'range-cooker.json',
		'wine-cooler.json',
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
