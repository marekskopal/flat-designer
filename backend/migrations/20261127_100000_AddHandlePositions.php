<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * The kitchen cabinets' fronts take a handle position (`fronts[].handlePosition`: auto, top, bottom, side), e.g. a pull
 * at the split of a tall unit's two doors. The rows are rewritten from the files; `down()` writes back the files as they
 * were before (`furniture/before-handle-positions`).
 */
final class AddHandlePositionsMigration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = ['kitchen-base-cabinet.json', 'kitchen-wall-cabinet.json', 'kitchen-tall-cabinet.json'];

	public const string Before = __DIR__ . '/furniture/before-handle-positions';

	public function up(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated);
	}

	public function down(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated, self::Before);
	}
}
