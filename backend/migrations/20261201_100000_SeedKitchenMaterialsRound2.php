<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Kitchen materials, round two: oak stained in the lacquer colours (fronts with a wood grain), painted brick, strip
 * tiles in white, greige and pink, long metro tiles, plain compact worktops, smoky grey glass, upholstery in brown,
 * teal and black, walnut and black fluted panels. The light oak fluted panel takes the colour of `wood-oak-light`, so
 * a fluted front style and a fluted panel of one piece match.
 */
final class SeedKitchenMaterialsRound2Migration extends Migration
{
	private const array Codes = [
		'wood-stained-sage',
		'wood-stained-olive',
		'wood-stained-blue-grey',
		'wood-stained-greige',
		'wood-stained-macchiato',
		'wood-stained-black',
		'brick-painted-white',
		'tile-strip-white',
		'tile-strip-greige',
		'tile-strip-pink',
		'tile-metro-white-long',
		'compact-grey-matt',
		'compact-light-grey-matt',
		'compact-sand-matt',
		'glass-smoked-grey',
		'fabric-velvet-brown',
		'fabric-velvet-teal',
		'fabric-linen-black',
		'panel-fluted-walnut',
		'panel-fluted-black',
	];

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');
		$material = static fn (
			string $code,
			string $name,
			string $pattern,
			string $color,
			float $roughness,
			float $metalness = 0.0,
			?int $width = null,
			?int $height = null,
		): array => [
			'code' => $code,
			'name' => $name,
			'pattern' => $pattern,
			'color' => $color,
			'roughness' => $roughness,
			'metalness' => $metalness,
			'tile_width' => $width,
			'tile_height' => $height,
			'created_at' => $now,
			'updated_at' => $now,
		];

		$this->table('materials')->insert([
			$material('wood-stained-sage', 'Oak stained light sage (wood-grain fronts)', 'Wood', '#a3ae96', 0.65, 0.0, null, null),
			$material('wood-stained-olive', 'Oak stained olive (wood-grain fronts)', 'Wood', '#7d7f5c', 0.65, 0.0, null, null),
			$material('wood-stained-blue-grey', 'Oak stained blue-grey (wood-grain fronts)', 'Wood', '#7f8d97', 0.65, 0.0, null, null),
			$material('wood-stained-greige', 'Oak stained weathered greige (wood-grain fronts)', 'Wood', '#b3a796', 0.65, 0.0, null, null),
			$material('wood-stained-macchiato', 'Oak stained macchiato (wood-grain fronts)', 'Wood', '#9a8572', 0.65, 0.0, null, null),
			$material('wood-stained-black', 'Oak stained black (wood-grain fronts)', 'Wood', '#3a3632', 0.65, 0.0, null, null),
			$material('brick-painted-white', 'Brick 215 × 65, painted white', 'Brick', '#ecebe6', 0.85, 0.0, 215, 65),
			$material('tile-strip-white', 'Strip tiles 75 × 300, white gloss, vertical (stack bond)', 'Tiles', '#f3f2ee', 0.15, 0.0, 75, 300),
			$material('tile-strip-greige', 'Strip tiles 75 × 300, greige gloss, vertical (stack bond)', 'Tiles', '#c9bfb2', 0.15, 0.0, 75, 300),
			$material('tile-strip-pink', 'Strip tiles 75 × 300, pink gloss, vertical (stack bond)', 'Tiles', '#e7c0b9', 0.15, 0.0, 75, 300),
			$material('tile-metro-white-long', 'Metro tiles 300 × 75, white gloss', 'Brick', '#f2f1ec', 0.15, 0.0, 300, 75),
			$material('compact-grey-matt', 'Compact worktop, grey matt', 'Plain', '#8f8d89', 0.7, 0.0, null, null),
			$material('compact-light-grey-matt', 'Compact worktop, light grey matt', 'Plain', '#c6c4c0', 0.7, 0.0, null, null),
			$material('compact-sand-matt', 'Compact worktop, sand matt', 'Plain', '#cbbfab', 0.7, 0.0, null, null),
			$material('glass-smoked-grey', 'Glass, smoked grey (pendants)', 'Glass', '#7a7c7e', 0.05, 0.0, null, null),
			$material('fabric-velvet-brown', 'Velvet, brown', 'Plain', '#6b4a36', 0.9, 0.0, null, null),
			$material('fabric-velvet-teal', 'Velvet, teal', 'Plain', '#2f6466', 0.9, 0.0, null, null),
			$material('fabric-linen-black', 'Linen fabric, black', 'Plain', '#2b2a29', 1.0, 0.0, null, null),
			$material('panel-fluted-walnut', 'Fluted panel, walnut (30 mm flutes)', 'Fluted', '#6b4a33', 0.6, 0.0, 30, 30),
			$material('panel-fluted-black', 'Fluted panel, black matt (30 mm flutes)', 'Fluted', '#2a2a2b', 0.6, 0.0, 30, 30),
		]);
		$this->databaseProvider->getDatabase()->getPdo()->exec("UPDATE materials SET color = '#d2b48a' WHERE code = 'panel-fluted-oak'");
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::Codes));
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
		$pdo->exec("UPDATE materials SET color = '#c9a77a' WHERE code = 'panel-fluted-oak'");
	}
}
