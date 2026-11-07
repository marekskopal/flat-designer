<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Waterfall ends on the straight worktop (`waterfall`: left, right, both) and on the L-shaped worktop (`waterfall`: the
 * run's free end, the return's, both), turning down `drop` mm below the item in the same material. The rows are rewritten from the files; `down()` writes back the
 * files as they were before (`furniture/before-worktop-ends`).
 */
final class AddWorktopWaterfallEndsMigration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = ['worktop.json', 'l-worktop.json'];

	public const string Before = __DIR__ . '/furniture/before-worktop-ends';

	public function up(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated);
	}

	public function down(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated, self::Before);
	}
}
