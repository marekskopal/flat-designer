<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Furniture hinges: cupboard doors hang on concealed cup hinges by default, so only the outdoor doors (the bin store's
 * and the garden shed's) get plain butt hinges. Their rows are rewritten from the files, which a fresh database already
 * seeds so; `down()` writes back the files as they were before (`furniture/before-furniture-hinges`).
 */
final class AddFurnitureHingesMigration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = ['bin-store.json', 'garden-shed.json'];

	public const string Before = __DIR__ . '/furniture/before-furniture-hinges';

	public function up(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated);
	}

	public function down(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated, self::Before);
	}
}
