<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Kitchen front styles and handles: the kitchen cabinets get `frontStyle` (flat, shaker, fluted, glass, reeded; per
 * front too), the frame width `frame`, `handleLength`, the handle kinds knob, edge pull, gola and push-to-open, and the
 * material slots `frames` and `handles` (without defaults, so an item keeps its look until it fills them). Their rows
 * are rewritten from the files; `down()` writes back the files as they were before (`furniture/before-kitchen-fronts`).
 */
final class AddKitchenFrontStylesMigration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = ['kitchen-base-cabinet.json', 'kitchen-wall-cabinet.json', 'kitchen-tall-cabinet.json'];

	public const string Before = __DIR__ . '/furniture/before-kitchen-fronts';

	public function up(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated);
	}

	public function down(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated, self::Before);
	}
}
