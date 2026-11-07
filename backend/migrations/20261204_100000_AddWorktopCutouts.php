<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Sink and hob cut-outs: the worktops (straight, L-shaped, island) take `cutout: worktop`, the sink and the hob
 * `cutout: sink` / `hob`, so the viewer cuts their holes out of the worktop under them; the sink's bowls sink into the
 * cut-out (an inset sink's rim a frame round them). The rows are rewritten from the files; `down()` writes back the
 * files as they were before (`furniture/before-worktop-cutouts`).
 */
final class AddWorktopCutoutsMigration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = ['worktop.json', 'l-worktop.json', 'island-worktop.json', 'kitchen-sink.json', 'hob.json'];

	public const string Before = __DIR__ . '/furniture/before-worktop-cutouts';

	public function up(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated);
	}

	public function down(): void
	{
		FurnitureSeeds::update($this->databaseProvider->getDatabase()->getPdo(), self::Updated, self::Before);
	}
}
