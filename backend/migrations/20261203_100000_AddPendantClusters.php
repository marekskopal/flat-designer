<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Pendant clusters and shade shapes: the glass pendant's shade may be a cone, a teardrop or an open wire cage (the
 * cylinder part's `profile` and `wire`), and a new pendant cluster hangs several such shades from one linear or round
 * canopy. The changed row is rewritten from its file; `down()` writes back the file as it was before
 * (`furniture/before-pendant-clusters`) and deletes the new one.
 */
final class AddPendantClustersMigration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = ['glass-pendant.json'];

	/** Seeded here. */
	public const array Files = ['pendant-cluster.json'];

	public const string Before = __DIR__ . '/furniture/before-pendant-clusters';

	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		FurnitureSeeds::update($pdo, self::Updated);
		FurnitureSeeds::insert($pdo, self::Files);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		FurnitureSeeds::delete($pdo, self::Files);
		FurnitureSeeds::update($pdo, self::Updated, self::Before);
	}
}
