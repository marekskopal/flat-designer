<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * A fuller global furniture catalogue for a furnished flat (FD-28): a sofa, a bed with bedding, a bedside table, a
 * round table, a walk-in shower screen, a round mirror and a balcony railing, with the fabrics and the wrought iron
 * they use. Upholstery is drawn with the `cushion` part.
 */
final class SeedFurnitureCatalogueMigration extends Migration
{
	public const array Files = [
		'sofa.json',
		'bed.json',
		'bedside-table.json',
		'round-table.json',
		'shower-screen.json',
		'round-mirror.json',
		'balcony-railing.json',
	];

	/** code => [name, colour, roughness, metalness] */
	private const array Materials = [
		'fabric-linen-grey' => ['Upholstery fabric, grey linen', '#9a958d', 0.97, 0.0],
		'fabric-linen-white' => ['Bedding, white linen', '#efece6', 0.95, 0.0],
		'fabric-wool-ochre' => ['Throw, ochre wool', '#b98b4a', 1.0, 0.0],
		'iron-wrought' => ['Wrought iron, painted black (railings)', '#262626', 0.55, 0.6],
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
