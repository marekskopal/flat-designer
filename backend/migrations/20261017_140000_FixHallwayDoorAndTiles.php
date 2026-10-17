<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Corrections after review:
 * - the hallway (H1/B_01) gets the same Dub Erica laminate as the rooms, tiles stay in bathroom and WC;
 * - the living-room door swings into the living room (into the hallway its leaf collided with the WC door);
 * - wall tiles are 600 × 300 mm (laid landscape), floor tiles stay 600 × 600 mm;
 * - the bathroom mirror sits on the tiles (8 mm lining), not inside them.
 */
final class FixHallwayDoorAndTilesMigration extends Migration
{
	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('UPDATE rooms SET floor_material_id = 2 WHERE id = 1');
		$pdo->exec("UPDATE items SET y = 8057 WHERE kind = 'Mirror' AND room_id = 3");
		$pdo->exec("UPDATE openings SET side = 'Right' WHERE id = 6");
		$pdo->exec(
			"UPDATE materials SET tile_width = 600, tile_height = 300, name = REPLACE(name, '1200 × 600', '600 × 300') WHERE id IN (14, 15)",
		);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('UPDATE rooms SET floor_material_id = 3 WHERE id = 1');
		$pdo->exec("UPDATE items SET y = 8049 WHERE kind = 'Mirror' AND room_id = 3");
		$pdo->exec("UPDATE openings SET side = 'Left' WHERE id = 6");
		$pdo->exec(
			"UPDATE materials SET tile_width = 1200, tile_height = 600, name = REPLACE(name, '600 × 300', '1200 × 600') WHERE id IN (14, 15)",
		);
	}
}
