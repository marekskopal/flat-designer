<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Finishes of the hallway built-ins (hall stand, sliding wardrobe): white matt furniture fronts and the
 * brushed aluminium of sliding-door profiles and rails. Ids are left to auto-increment, so the rows do not
 * collide with materials added in the app; the items pick them up by code.
 */
final class SeedFurnitureMaterialsMigration extends Migration
{
	private const array Codes = ['furniture-white-matt', 'aluminium-brushed'];

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');
		$stamp = ['created_at' => $now, 'updated_at' => $now];

		$this->table('materials')->insert([
			[
				'code' => 'furniture-white-matt',
				'name' => 'Furniture front, white matt',
				'pattern' => 'Plain',
				'color' => '#f2f1ed',
				'roughness' => 0.7,
				'metalness' => 0.0,
				...$stamp,
			],
			[
				'code' => 'aluminium-brushed',
				'name' => 'Aluminium, brushed (profiles, rails)',
				'pattern' => 'Plain',
				'color' => '#b9bcbf',
				'roughness' => 0.35,
				'metalness' => 0.85,
				...$stamp,
			],
		]);
	}

	public function down(): void
	{
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::Codes));
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('UPDATE items SET material_id = NULL WHERE material_id IN (SELECT id FROM materials WHERE code IN (' . $codes . '))');
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
	}
}
