<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Soft furnishings and decor for a lived-in flat (FD-42): a framed picture showing a project image, rugs, a row of
 * books, curtains, roller and venetian blinds and a scatter cushion, with the fabrics, rug wools, mount paper and book
 * cloths they use. Curtains and blinds are furniture placed at a window, not fields of the window.
 */
final class SeedDecorCatalogueMigration extends Migration
{
	public const array Files = [
		'framed-picture.json',
		'rug.json',
		'books.json',
		'curtains.json',
		'roller-blind.json',
		'venetian-blind.json',
		'scatter-cushion.json',
	];

	/** code => [name, colour, roughness] */
	private const array Materials = [
		'fabric-velvet-green' => ['Upholstery fabric, green velvet', '#3f5a45', 0.88],
		'fabric-cotton-terracotta' => ['Cotton fabric, terracotta', '#b0603f', 0.95],
		'fabric-felt-grey' => ['Wool felt, grey', '#7c7c7a', 1.0],
		'rug-wool-cream' => ['Rug, cream wool', '#e3dccd', 1.0],
		'rug-jute' => ['Rug, natural jute', '#b79a6a', 1.0],
		'paper-white' => ['Paper, white (mounts, prints)', '#f3f1ea', 0.9],
		'book-cloth-navy' => ['Book cloth, navy', '#24324a', 0.85],
		'book-cloth-oxblood' => ['Book cloth, oxblood', '#6a2a2a', 0.85],
		'book-cloth-ochre' => ['Book cloth, ochre', '#c49a45', 0.85],
		'book-cloth-cream' => ['Book cloth, cream', '#e2d8c0', 0.85],
		'book-cloth-green' => ['Book cloth, green', '#3d5b4a', 0.85],
		'book-cloth-grey' => ['Book cloth, grey', '#8a8a86', 0.85],
	];

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');
		$rows = [];
		foreach (self::Materials as $code => [$name, $color, $roughness]) {
			$rows[] = [
				'code' => $code,
				'name' => $name,
				'pattern' => 'Plain',
				'color' => $color,
				'roughness' => $roughness,
				'metalness' => 0.0,
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
