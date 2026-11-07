<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Seating and lighting, round two: the bar stool's footrest may be left out (0) and its backrest and footrest take
 * materials of their own when asked (`backrestMaterial` / `footrestMaterial` own); the dining chair's seat and legs and the dining table's legs take materials of their own; the
 * round table may be oval with a wider pedestal; the glass pendant gets a flat disc shade, a shade height apart from its
 * width and, when asked, a holder material of its own; and a new upholstered shell chair on legs, a sled or a swivel pedestal. The changed rows
 * are rewritten from the files; `down()` writes back the files as they were before (`furniture/before-seating-2`) and
 * deletes the new one.
 */
final class AddSeatingAndLightingRound2Migration extends Migration
{
	/** Seeded earlier, changed here. */
	public const array Updated = ['bar-stool.json', 'glass-pendant.json', 'dining-chair.json', 'dining-table.json', 'round-table.json'];

	/** Seeded here. */
	public const array Files = ['shell-chair.json'];

	public const string Before = __DIR__ . '/furniture/before-seating-2';

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
