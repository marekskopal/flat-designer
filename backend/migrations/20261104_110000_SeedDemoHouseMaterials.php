<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * The patterns `Stone` (random coursed dry-stone walling) and `Formwork` (cast concrete showing its formwork panels or
 * boards, not laid in pieces) and the finishes of the demo house (FD-92): its façade (white
 * silicate render, a grey plinth, horizontal larch cladding, exposed concrete, painted steel), interior (wide oak planks,
 * solid oak, white solid surface, quartzite tiles, black granite, oak-stained frames) and garden (larch decking, stepping
 * slabs, clay pavers, dry-stone walling, a lavender bed). All are drawn procedurally: the reference photos have no
 * licence, so no texture is cut from them. Ids are left to auto-increment; the demo picks the rows up by code.
 */
final class SeedDemoHouseMaterialsMigration extends Migration
{
	private const array Patterns = ['Plain', 'Wood', 'Planks', 'Tiles', 'Glass', 'Grass', 'Gravel', 'Terrazzo', 'Brick', 'Herringbone', 'BeaverTail'];

	private const array Codes = [
		'render-silicate-white',
		'render-plinth-grey',
		'larch-cladding',
		'concrete-formwork',
		'concrete-board-marked',
		'steel-painted-grey',
		'oak-planks-wide',
		'oak-solid',
		'solid-surface-white',
		'quartzite-tiles',
		'granite-black-honed',
		'oak-stained-frame',
		'larch-decking',
		'concrete-stepping-slabs',
		'clay-pavers',
		'drystone-grey',
		'lavender-bed',
	];

	public function up(): void
	{
		$this->table('materials')
			->alterColumn('pattern', Type::Enum, enum: [...self::Patterns, 'Stone', 'Formwork'], default: 'Plain')
			->alter();

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
			$material('render-silicate-white', 'Silicate render, white, fine grain (façades)', 'Plain', '#f1f0eb', 0.95),
			$material('render-plinth-grey', 'Render, grey (plinth)', 'Plain', '#8f8e8a', 0.95),
			$material('larch-cladding', 'Larch cladding boards, oiled (120 × 4000, horizontal on walls)', 'Planks', '#b47a45', 0.7, 0.0, 120, 4000),
			$material('concrete-formwork', 'Exposed concrete, smooth formwork (2500 × 1250 panels)', 'Formwork', '#a9a8a3', 0.85, 0.0, 2500, 1250),
			$material('concrete-board-marked', 'Exposed concrete, board-marked (3000 × 150 boards)', 'Formwork', '#a3a29d', 0.9, 0.0, 3000, 150),
			$material('steel-painted-grey', 'Steel, painted grey (doors, gates)', 'Plain', '#6d7073', 0.5, 0.4),
			$material('oak-planks-wide', 'Oak planks, wide, natural oiled (220 × 2200)', 'Planks', '#ad7f4a', 0.55, 0.0, 220, 2200),
			$material('oak-solid', 'Solid oak, natural oiled (stairs, balusters, linings)', 'Wood', '#c08e55', 0.55),
			$material('solid-surface-white', 'Solid surface, white matt (worktops, counters)', 'Plain', '#f2f1ed', 0.35),
			$material('quartzite-tiles', 'Quartzite tiles 600 × 300, beige-grey, honed', 'Tiles', '#b9b2a5', 0.5, 0.0, 600, 300),
			$material('granite-black-honed', 'Granite, black, honed (fireplace plinth)', 'Plain', '#242425', 0.4),
			$material('oak-stained-frame', 'Timber window frames, oak stained', 'Wood', '#a26a38', 0.5),
			$material('larch-decking', 'Larch decking, weathered (140 × 4000)', 'Planks', '#8a6444', 0.85, 0.0, 140, 4000),
			$material('concrete-stepping-slabs', 'Concrete stepping slabs 900 × 300, light grey', 'Tiles', '#c9c6bf', 0.85, 0.0, 900, 300),
			$material('clay-pavers', 'Clay pavers 200 × 100, red', 'Brick', '#8e4a31', 0.85, 0.0, 200, 100),
			$material('drystone-grey', 'Dry-stone walling, grey limestone (random coursed)', 'Stone', '#77756f', 0.95, 0.0, 400, 130),
			$material('lavender-bed', 'Lavender bed', 'Grass', '#84829a', 1.0),
		]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::Codes));
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
		$pdo->exec("UPDATE materials SET pattern = 'Brick' WHERE pattern = 'Stone'");
		$pdo->exec("UPDATE materials SET pattern = 'Tiles' WHERE pattern = 'Formwork'");
		$this->table('materials')->alterColumn('pattern', Type::Enum, enum: self::Patterns, default: 'Plain')->alter();
	}
}
