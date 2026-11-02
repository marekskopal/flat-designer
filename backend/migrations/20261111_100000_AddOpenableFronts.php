<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Openable fronts: the cabinets' drawers become assemblies that slide out with their drawer boxes, their doors
 * get shelves behind them, and every front a `softClose` parameter; the wall and tall kitchen cabinets get lift-up,
 * bi-fold and parallel lift flaps. Their rows are rewritten from the files, which a fresh database already seeds so;
 * `down()` writes back the files as they were before (`furniture/before-openable-fronts`).
 */
final class AddOpenableFrontsMigration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = [
		'kitchen-base-cabinet.json',
		'kitchen-tall-cabinet.json',
		'kitchen-wall-cabinet.json',
		'sideboard.json',
		'bedside-table.json',
		'vessel-vanity.json',
	];

	public const string Before = __DIR__ . '/furniture/before-openable-fronts';

	public function up(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated);
	}

	public function down(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated, self::Before);
	}
}
