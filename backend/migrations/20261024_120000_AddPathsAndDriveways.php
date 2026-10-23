<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Outdoor surfaces (FD-39): room types `Path` and `Driveway`, open-air like terraces and gardens, and their usual
 * finishes, concrete paving blocks and asphalt. Ids are left to auto-increment; they are picked up by code.
 */
final class AddPathsAndDrivewaysMigration extends Migration
{
	private const array RoomTypes = ['Living', 'Bedroom', 'Bathroom', 'Toilet', 'Hallway', 'Terrace', 'Garden'];

	private const array Codes = ['paving-concrete-grey', 'asphalt'];

	public function up(): void
	{
		$this->table('rooms')
			->alterColumn('type', Type::Enum, enum: [...self::RoomTypes, 'Path', 'Driveway'])
			->alter();

		$now = date('Y-m-d H:i:s');
		$material = static fn (string $code, string $name, string $pattern, string $color, float $roughness, ?int $width, ?int $height): array => [
			'code' => $code,
			'name' => $name,
			'pattern' => $pattern,
			'color' => $color,
			'roughness' => $roughness,
			'metalness' => 0.0,
			'tile_width' => $width,
			'tile_height' => $height,
			'created_at' => $now,
			'updated_at' => $now,
		];
		$this->table('materials')->insert([
			$material('paving-concrete-grey', 'Concrete paving blocks, grey (200 × 100)', 'Tiles', '#9c9a95', 0.9, 200, 100),
			$material('asphalt', 'Asphalt, dark grey', 'Plain', '#3c3d3f', 0.95, null, null),
		]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::Codes));
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
		$pdo->exec("UPDATE rooms SET type = 'Garden' WHERE type IN ('Path', 'Driveway')");
		$this->table('rooms')->alterColumn('type', Type::Enum, enum: self::RoomTypes)->alter();
	}
}
