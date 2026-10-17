<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use const JSON_THROW_ON_ERROR;

/**
 * Real finishes from `source_files/`:
 * - laminate "Dub Erica" (photo of the actual plank) in the living room and both bedrooms, laid along
 *   the north–south axis, i.e. towards the windows;
 * - bathroom, WC and hallway floor: 600 × 600 taupe concrete-look tiles (bathroom/WC renders);
 * - wall tiles 1200 × 600 landscape: bathroom with a brown band up to 1200 mm and beige above up to
 *   2100 mm, WC beige up to the ceiling.
 *
 * Plank size is an estimate from the aspect ratio of the photographed sample (≈ 1 : 4.6); confirm it
 * with the supplier's datasheet.
 */
final class SeedFinishesMigration extends Migration
{
	public const int TileBeige = 14;

	public const int TileBrown = 15;

	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$now = date('Y-m-d H:i:s');

		$floor = $pdo->prepare(
			'UPDATE materials SET code = :code, name = :name, color = :color, roughness = :roughness, texture = :texture,'
			. ' tile_width = :tile_width, tile_height = :tile_height, updated_at = :now WHERE id = :id',
		);
		if ($floor === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$floor->execute([
			'id' => 2,
			'code' => 'floor-laminate-dub-erica',
			'name' => 'Laminate floor, Dub Erica',
			'color' => '#a38664',
			'roughness' => 0.55,
			'texture' => 'textures/floor-dub-erica.jpg',
			'tile_width' => 265,
			'tile_height' => 1220,
			'now' => $now,
		]);
		$floor->execute([
			'id' => 3,
			'code' => 'floor-tiles-taupe',
			'name' => 'Floor tiles 600 × 600, taupe concrete look',
			'color' => '#9d978c',
			'roughness' => 0.5,
			'texture' => 'textures/tile-floor-taupe.jpg',
			'tile_width' => 600,
			'tile_height' => 600,
			'now' => $now,
		]);

		$this->table('materials')->insert([
			$this->material(
				self::TileBeige,
				'tile-wall-beige',
				'Wall tiles 1200 × 600, beige',
				'Tiles',
				'#b0a28c',
				0.4,
				'textures/tile-wall-beige.jpg',
				1200,
				600,
				$now,
			),
			$this->material(
				self::TileBrown,
				'tile-wall-brown',
				'Wall tiles 1200 × 600, brown',
				'Tiles',
				'#7b6650',
				0.4,
				'textures/tile-wall-brown.jpg',
				1200,
				600,
				$now,
			),
			$this->material(16, 'ceramic-white', 'Sanitary ceramics, white', 'Plain', '#f4f4f2', 0.12, null, null, null, $now),
			$this->material(17, 'chrome', 'Chrome', 'Plain', '#eeeeee', 0.1, null, null, null, $now, 1.0),
			$this->material(18, 'acrylic-white', 'Bathtub acrylic, white', 'Plain', '#f6f6f4', 0.18, null, null, null, $now),
			$this->material(19, 'mirror', 'Mirror', 'Plain', '#e9eced', 0.02, null, null, null, $now, 1.0),
			$this->material(20, 'appliance-white', 'Appliance, white', 'Plain', '#f1f1ef', 0.35, null, null, null, $now),
		]);

		$wallFinishes = $pdo->prepare('UPDATE rooms SET wall_finishes = :finishes WHERE id = :id');
		if ($wallFinishes === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$wallFinishes->execute([
			'id' => 3,
			'finishes' => json_encode([
				['materialId' => self::TileBrown, 'bottom' => 0, 'top' => 1200],
				['materialId' => self::TileBeige, 'bottom' => 1200, 'top' => 2100],
			], JSON_THROW_ON_ERROR)]);
		$wallFinishes->execute([
			'id' => 2,
			'finishes' => json_encode([
				['materialId' => self::TileBeige, 'bottom' => 0, 'top' => 2400],
			], JSON_THROW_ON_ERROR)]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('UPDATE rooms SET wall_finishes = NULL WHERE id IN (2, 3)');
		$pdo->exec('DELETE FROM materials WHERE id BETWEEN 14 AND 20');
		$pdo->exec(
			"UPDATE materials SET code = 'floor-laminate-oak', name = 'Laminate floor, natural oak', color = '#c09566', roughness = 0.62,"
			. ' texture = NULL, tile_width = NULL, tile_height = NULL WHERE id = 2',
		);
		$pdo->exec(
			"UPDATE materials SET code = 'floor-tiles-grey', name = 'Ceramic floor tiles, light grey', color = '#bdbab4', roughness = 0.45,"
			. ' texture = NULL, tile_width = NULL, tile_height = NULL WHERE id = 3',
		);
	}

	/** @return array<string, string|int|float|null> */
	private function material(
		int $id,
		string $code,
		string $name,
		string $pattern,
		string $color,
		float $roughness,
		?string $texture,
		?int $tileWidth,
		?int $tileHeight,
		string $now,
		float $metalness = 0.0,
	): array {
		return [
			'id' => $id,
			'code' => $code,
			'name' => $name,
			'pattern' => $pattern,
			'color' => $color,
			'roughness' => $roughness,
			'metalness' => $metalness,
			'texture' => $texture,
			'tile_width' => $tileWidth,
			'tile_height' => $tileHeight,
			'created_at' => $now,
			'updated_at' => $now,
		];
	}
}
