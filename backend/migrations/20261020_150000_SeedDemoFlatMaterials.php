<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Patterns `Terrazzo` (stone chips in a light base, with slab joints when a tile size is set), `Brick` (bricks in
 * stretcher bond) and `Herringbone` (tiles laid at 45° in a herringbone), and the finishes of the demo flat (FD-40):
 * polished terrazzo slabs, handmade brick for Catalan vaults, charcoal glazed herringbone wall tiles, oak veneer for
 * built-ins and matt black fronts with a black compact worktop. All are drawn procedurally, no photo textures. Ids
 * are left to auto-increment; definitions and items pick the rows up by code.
 */
final class SeedDemoFlatMaterialsMigration extends Migration
{
	private const array Patterns = ['Plain', 'Wood', 'Planks', 'Tiles', 'Glass', 'Grass', 'Gravel'];

	private const array Codes = ['terrazzo-white', 'brick-handmade', 'tile-herringbone-charcoal', 'oak-veneer', 'matt-black', 'compact-black'];

	public function up(): void
	{
		$this->table('materials')
			->alterColumn('pattern', Type::Enum, enum: [...self::Patterns, 'Terrazzo', 'Brick', 'Herringbone'], default: 'Plain')
			->alter();

		$now = date('Y-m-d H:i:s');
		$stamp = ['created_at' => $now, 'updated_at' => $now];
		$material = static fn (string $code, string $name, string $pattern, string $color, float $roughness, ?int $width, ?int $height): array => [
			'code' => $code,
			'name' => $name,
			'pattern' => $pattern,
			'color' => $color,
			'roughness' => $roughness,
			'metalness' => 0.0,
			'tile_width' => $width,
			'tile_height' => $height,
			...$stamp,
		];

		$this->table('materials')->insert([
			$material('terrazzo-white', 'Terrazzo, white with grey and black chips, polished (600 × 600 slabs)', 'Terrazzo', '#e8e4db', 0.22, 600, 600),
			$material('brick-handmade', 'Handmade brick, exposed (Catalan vault tiles 280 × 140)', 'Brick', '#a65b3c', 0.92, 280, 140),
			$material('tile-herringbone-charcoal', 'Wall tiles 240 × 60, charcoal glazed, herringbone', 'Herringbone', '#3b3d40', 0.28, 240, 60),
			$material('oak-veneer', 'Oak veneer, natural (built-ins, doors)', 'Wood', '#c49a6c', 0.6, null, null),
			$material('matt-black', 'Furniture front, black matt', 'Plain', '#1f1f20', 0.78, null, null),
			$material('compact-black', 'Compact laminate, black (worktop, backsplash)', 'Plain', '#161617', 0.45, null, null),
		]);
	}

	public function down(): void
	{
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::Codes));
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
		$this->table('materials')->alterColumn('pattern', Type::Enum, enum: self::Patterns, default: 'Plain')->alter();
	}
}
