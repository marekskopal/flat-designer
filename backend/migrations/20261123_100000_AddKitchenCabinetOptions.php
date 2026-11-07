<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Kitchen cabinet options: a sink base unit without its top and shelves (`sinkUnit`), a larder with internal drawers
 * behind its doors (`interior`), a plinth in its own material (`plinthFinish`, slot `plinth`), LED shelf lighting in
 * the wall cabinets (`shelfLight`) and the open shelf (`light`). The rows are rewritten from the files; `down()` writes
 * back the files as they were before (`furniture/before-kitchen-cabinets`).
 */
final class AddKitchenCabinetOptionsMigration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = ['kitchen-base-cabinet.json', 'kitchen-wall-cabinet.json', 'kitchen-tall-cabinet.json', 'open-shelf.json'];

	public const string Before = __DIR__ . '/furniture/before-kitchen-cabinets';

	public function up(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated);
	}

	public function down(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated, self::Before);
	}
}
