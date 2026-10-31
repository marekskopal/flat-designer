<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Roof accessories (FD-91): a vent tile and a roof step to lay on the roof (items with `onRoof`), a flat-plate solar
 * collector and a satellite dish, with the absorber and white powder-coated metal they use.
 */
final class SeedRoofAccessoriesMigration extends Migration
{
	public const array Files = [
		'roof-vent-tile.json',
		'roof-step.json',
		'solar-collector.json',
		'satellite-dish.json',
	];

	/** code => [name, colour, roughness, metalness] */
	private const array Materials = [
		'solar-absorber' => ['Solar absorber, blue-black', '#1c2430', 0.2, 0.4],
		'metal-white' => ['Metal, white powder-coated', '#ecebe6', 0.45, 0.2],
	];

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');
		$rows = [];
		foreach (self::Materials as $code => [$name, $color, $roughness, $metalness]) {
			$rows[] = [
				'code' => $code,
				'name' => $name,
				'pattern' => 'Plain',
				'color' => $color,
				'roughness' => $roughness,
				'metalness' => $metalness,
				'created_at' => $now,
				'updated_at' => $now,
			];
		}
		$this->table('materials')->insert($rows);
		FurnitureSeeds::insert($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		FurnitureSeeds::delete($pdo, self::Files);
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", array_keys(self::Materials)));
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
	}
}
