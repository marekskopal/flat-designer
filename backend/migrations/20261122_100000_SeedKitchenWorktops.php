<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Kitchen worktops: an island worktop whose ends may turn down to the floor (waterfall ends) and an L-shaped worktop in
 * two pieces; the worktop gets rounded ends (`roundEnd`) and the backsplash a ledge. The changed rows are rewritten
 * from their files; `down()` writes back the files as they were before (`furniture/before-kitchen-worktops`).
 */
final class SeedKitchenWorktopsMigration extends Migration
{
	public const array Files = ['island-worktop.json', 'l-worktop.json'];

	/** Seeded earlier, changed here. */
	public const array Updated = ['worktop.json', 'backsplash.json'];

	public const string Before = __DIR__ . '/furniture/before-kitchen-worktops';

	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		FurnitureSeeds::insert($pdo, self::Files);
		FurnitureSeeds::update($pdo, self::Updated);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		FurnitureSeeds::delete($pdo, self::Files);
		FurnitureSeeds::update($pdo, self::Updated, self::Before);
	}
}
