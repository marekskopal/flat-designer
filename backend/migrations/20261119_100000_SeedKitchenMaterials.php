<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Materials of modern kitchens and the patterns they need: `Marble` (veined stone in slabs or tiles, the veins in the
 * new `accent` colour), `Chevron` (parquet), `Zellige` (handmade glazed tiles), `Encaustic` (cement tiles with a motif
 * in `accent`) and `Fluted` (panels of rounded flutes). The catalogue: matt lacquered fronts in the colours modern
 * kitchens use, oak and walnut, fluted panels, veined stone looks for worktops and panels, concrete looks, parquet and
 * large-format floor tiles, metro, zellige, strip and encaustic wall tiles, brass and other metals, wall paints, and a
 * few upholstery and glass finishes. All drawn procedurally. Ids are left to auto-increment.
 */
final class SeedKitchenMaterialsMigration extends Migration
{
	private const array Patterns = [
		'Plain',
		'Wood',
		'Planks',
		'Tiles',
		'Glass',
		'Grass',
		'Gravel',
		'Terrazzo',
		'Brick',
		'Herringbone',
		'BeaverTail',
		'Stone',
		'Formwork',
	];

	private const array Codes = [
		'lacquer-warm-white-matt',
		'lacquer-cashmere-matt',
		'lacquer-sand-matt',
		'lacquer-greige-matt',
		'lacquer-stone-grey-matt',
		'lacquer-macchiato-matt',
		'lacquer-taupe-matt',
		'lacquer-coffee-matt',
		'lacquer-coral-matt',
		'lacquer-powder-pink-matt',
		'lacquer-sage-light-matt',
		'lacquer-olive-matt',
		'lacquer-pastel-blue-matt',
		'lacquer-blue-grey-matt',
		'lacquer-slate-grey-matt',
		'lacquer-anthracite-matt',
		'wood-oak-light',
		'wood-oak-honey',
		'wood-oak-rustic',
		'wood-oak-smoked',
		'wood-walnut',
		'wood-walnut-dark',
		'panel-fluted-oak',
		'panel-fluted-white',
		'panel-fluted-marble-white',
		'panel-fluted-sand',
		'marble-white-veined',
		'marble-calacatta-gold',
		'marble-black-veined',
		'quartzite-green',
		'travertine-cream',
		'stone-sand-veined',
		'stone-greige-veined',
		'stone-dark-veined',
		'concrete-dark',
		'microcement-grey',
		'compact-slate',
		'oak-chevron-natural',
		'oak-herringbone-natural',
		'oak-planks-light',
		'marble-tiles-white',
		'travertine-tiles',
		'stone-tiles-grey',
		'stone-tiles-beige',
		'terrazzo-beige',
		'tile-metro-white',
		'tile-metro-cream',
		'tile-zellige-white',
		'tile-zellige-burgundy',
		'tile-zellige-green',
		'tile-zellige-terracotta',
		'tile-strip-green',
		'tile-strip-cream',
		'tile-square-pink',
		'tile-encaustic-black',
		'tile-encaustic-blue',
		'tile-encaustic-terracotta',
		'brass-brushed',
		'gold-polished',
		'rose-gold',
		'steel-stainless',
		'metal-black-matt',
		'paint-limewash-white',
		'paint-cream',
		'paint-sand',
		'paint-greige',
		'paint-sage',
		'paint-khaki',
		'paint-terracotta',
		'paint-charcoal',
		'fabric-boucle-cream',
		'fabric-velvet-pink',
		'leather-cognac',
		'glass-smoked',
		'glass-satin',
	];

	public function up(): void
	{
		$this->table('materials')
			->alterColumn(
				'pattern',
				Type::Enum,
				enum: [...self::Patterns, 'Marble', 'Chevron', 'Zellige', 'Encaustic', 'Fluted'],
				default: 'Plain',
			)
			->addColumn('accent', Type::String, nullable: true, size: 7)
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
			?string $accent = null,
		): array => [
			'code' => $code,
			'name' => $name,
			'pattern' => $pattern,
			'color' => $color,
			'roughness' => $roughness,
			'metalness' => $metalness,
			'tile_width' => $width,
			'tile_height' => $height,
			'accent' => $accent,
			'created_at' => $now,
			'updated_at' => $now,
		];

		$this->table('materials')->insert([
			$material('lacquer-warm-white-matt', 'Furniture front, warm white matt', 'Plain', '#ece6db', 0.75),
			$material('lacquer-cashmere-matt', 'Furniture front, cashmere matt', 'Plain', '#d8cfc2', 0.75),
			$material('lacquer-sand-matt', 'Furniture front, sand matt', 'Plain', '#cdbfa8', 0.75),
			$material('lacquer-greige-matt', 'Furniture front, greige matt', 'Plain', '#b5aa9c', 0.75),
			$material('lacquer-stone-grey-matt', 'Furniture front, stone grey matt', 'Plain', '#bdb7b2', 0.75),
			$material('lacquer-macchiato-matt', 'Furniture front, macchiato matt', 'Plain', '#a28f7c', 0.75),
			$material('lacquer-taupe-matt', 'Furniture front, taupe matt', 'Plain', '#8e8276', 0.75),
			$material('lacquer-coffee-matt', 'Furniture front, coffee brown matt', 'Plain', '#5a4436', 0.75),
			$material('lacquer-coral-matt', 'Furniture front, coral matt', 'Plain', '#d98b70', 0.75),
			$material('lacquer-powder-pink-matt', 'Furniture front, powder pink matt', 'Plain', '#e2c1b8', 0.75),
			$material('lacquer-sage-light-matt', 'Furniture front, light sage matt', 'Plain', '#a9b5a0', 0.75),
			$material('lacquer-olive-matt', 'Furniture front, olive matt', 'Plain', '#6b6e4e', 0.75),
			$material('lacquer-pastel-blue-matt', 'Furniture front, pastel blue matt', 'Plain', '#b9c9d3', 0.75),
			$material('lacquer-blue-grey-matt', 'Furniture front, blue-grey matt', 'Plain', '#7d8b95', 0.75),
			$material('lacquer-slate-grey-matt', 'Furniture front, slate grey matt', 'Plain', '#4f5459', 0.75),
			$material('lacquer-anthracite-matt', 'Furniture front, anthracite matt', 'Plain', '#33363a', 0.78),
			$material('wood-oak-light', 'Oak, light natural (fronts, panels, worktops)', 'Wood', '#d2b48a', 0.6),
			$material('wood-oak-honey', 'Oak, honey (fronts, worktops)', 'Wood', '#c08a4e', 0.55),
			$material('wood-oak-rustic', 'Oak, rustic with knots (fronts, panels)', 'Wood', '#a77b4f', 0.65),
			$material('wood-oak-smoked', 'Oak, smoked (fronts, panels)', 'Wood', '#6e5038', 0.6),
			$material('wood-walnut', 'Walnut, natural (fronts, panels, worktops)', 'Wood', '#6b4a33', 0.55),
			$material('wood-walnut-dark', 'Walnut, dark (fronts, panels)', 'Wood', '#4a3224', 0.55),
			$material('panel-fluted-oak', 'Fluted panel, light oak (30 mm flutes)', 'Fluted', '#c9a77a', 0.6, 0.0, 30, 30),
			$material('panel-fluted-white', 'Fluted panel, white matt (30 mm flutes)', 'Fluted', '#ece8e0', 0.7, 0.0, 30, 30),
			$material('panel-fluted-marble-white', 'Fluted panel, white marble look (40 mm flutes)', 'Fluted', '#e9e6df', 0.3, 0.0, 40, 40),
			$material('panel-fluted-sand', 'Fluted panel, sand matt (30 mm flutes)', 'Fluted', '#cdbfa8', 0.75, 0.0, 30, 30),
			$material('marble-white-veined', 'Marble look, white with grey veins (worktops, panels)', 'Marble', '#f1efea', 0.2, 0.0, 3200, 1600, '#8f9196'),
			$material('marble-calacatta-gold', 'Marble look, white with gold veins (worktops, panels)', 'Marble', '#f3efe6', 0.2, 0.0, 3200, 1600, '#b8975e'),
			$material('marble-black-veined', 'Marble look, black with white veins (worktops, panels)', 'Marble', '#1f1f21', 0.25, 0.0, 3200, 1600, '#d9d6cf'),
			$material('quartzite-green', 'Quartzite look, green with light veins (worktops, panels)', 'Marble', '#3f5a49', 0.3, 0.0, 3200, 1600, '#c8d2c5'),
			$material('travertine-cream', 'Travertine look, cream (worktops, panels)', 'Marble', '#dccbb0', 0.5, 0.0, 3200, 1600, '#bfa98a'),
			$material('stone-sand-veined', 'Stone look, sand with soft veins (worktops)', 'Marble', '#d9cdb8', 0.35, 0.0, 3200, 1600, '#b3a48c'),
			$material('stone-greige-veined', 'Stone look, greige with soft veins (worktops)', 'Marble', '#b9b0a4', 0.35, 0.0, 3200, 1600, '#8b8378'),
			$material('stone-dark-veined', 'Stone look, dark brown-grey with veins (worktops)', 'Marble', '#3b3735', 0.3, 0.0, 3200, 1600, '#8a837b'),
			$material('concrete-dark', 'Concrete look, dark grey (worktops)', 'Plain', '#5d5b58', 0.8),
			$material('microcement-grey', 'Microcement, light grey (worktops, walls)', 'Plain', '#a8a49d', 0.8),
			$material('compact-slate', 'Compact laminate, slate grey (worktops)', 'Plain', '#45484b', 0.55),
			$material('oak-chevron-natural', 'Oak parquet, chevron 600 × 90, natural', 'Chevron', '#c49a68', 0.55, 0.0, 600, 90),
			$material('oak-herringbone-natural', 'Oak parquet, herringbone 540 × 90, natural', 'Herringbone', '#c39a6a', 0.55, 0.0, 540, 90),
			$material('oak-planks-light', 'Oak planks, light, matt lacquered (190 × 2000)', 'Planks', '#cfae82', 0.6, 0.0, 190, 2000),
			$material('marble-tiles-white', 'Marble-look tiles 1200 × 600, white', 'Marble', '#ecebe6', 0.25, 0.0, 1200, 600, '#a4a6aa'),
			$material('travertine-tiles', 'Travertine tiles 800 × 400, cream', 'Marble', '#d8c7a8', 0.55, 0.0, 800, 400, '#bba88a'),
			$material('stone-tiles-grey', 'Stone-look tiles 1200 × 600, grey', 'Tiles', '#9e9a93', 0.6, 0.0, 1200, 600),
			$material('stone-tiles-beige', 'Stone-look tiles 1200 × 600, beige', 'Tiles', '#cbbfa9', 0.6, 0.0, 1200, 600),
			$material('terrazzo-beige', 'Terrazzo, beige with warm chips (600 × 600 slabs)', 'Terrazzo', '#dcd0bc', 0.25, 0.0, 600, 600),
			$material('tile-metro-white', 'Metro tiles 150 × 75, white gloss', 'Brick', '#f2f1ec', 0.15, 0.0, 150, 75),
			$material('tile-metro-cream', 'Metro tiles 150 × 75, cream gloss', 'Brick', '#ece3cf', 0.15, 0.0, 150, 75),
			$material('tile-zellige-white', 'Zellige tiles 100 × 100, white', 'Zellige', '#ebe7dc', 0.12, 0.0, 100, 100),
			$material('tile-zellige-burgundy', 'Zellige tiles 100 × 100, burgundy', 'Zellige', '#6e2430', 0.12, 0.0, 100, 100),
			$material('tile-zellige-green', 'Zellige tiles 100 × 100, green', 'Zellige', '#3d5e4f', 0.12, 0.0, 100, 100),
			$material('tile-zellige-terracotta', 'Zellige tiles 100 × 100, terracotta', 'Zellige', '#b4613f', 0.15, 0.0, 100, 100),
			$material('tile-strip-green', 'Strip tiles 75 × 300, green gloss, vertical (stack bond)', 'Tiles', '#5d7a65', 0.15, 0.0, 75, 300),
			$material('tile-strip-cream', 'Strip tiles 75 × 300, cream gloss, vertical (stack bond)', 'Tiles', '#eae2cf', 0.15, 0.0, 75, 300),
			$material('tile-square-pink', 'Wall tiles 100 × 100, pink gloss', 'Tiles', '#e3b8ae', 0.2, 0.0, 100, 100),
			$material('tile-encaustic-black', 'Encaustic tiles 200 × 200, black on white', 'Encaustic', '#ece8df', 0.6, 0.0, 200, 200, '#2a2a2b'),
			$material('tile-encaustic-blue', 'Encaustic tiles 200 × 200, blue on cream', 'Encaustic', '#e9e4d8', 0.6, 0.0, 200, 200, '#4f6c86'),
			$material('tile-encaustic-terracotta', 'Encaustic tiles 200 × 200, terracotta on cream', 'Encaustic', '#e7dccb', 0.6, 0.0, 200, 200, '#a95b3c'),
			$material('brass-brushed', 'Brass, brushed (handles, taps, lights)', 'Plain', '#b5975a', 0.35, 1.0),
			$material('gold-polished', 'Gold, polished (handles, lights)', 'Plain', '#d4af64', 0.2, 1.0),
			$material('rose-gold', 'Rose gold, brushed (handles, taps, sinks)', 'Plain', '#c9967d', 0.3, 1.0),
			$material('steel-stainless', 'Stainless steel, brushed (sinks, appliances)', 'Plain', '#c3c5c7', 0.35, 0.9),
			$material('metal-black-matt', 'Metal, black matt (handles, frames, taps)', 'Plain', '#232325', 0.55, 0.6),
			$material('paint-limewash-white', 'Limewash, warm white', 'Plain', '#e9e3d7', 0.97),
			$material('paint-cream', 'Wall paint, cream', 'Plain', '#efe7d6', 0.92),
			$material('paint-sand', 'Wall paint, sand', 'Plain', '#d8cbb4', 0.92),
			$material('paint-greige', 'Wall paint, greige', 'Plain', '#cfc6b8', 0.92),
			$material('paint-sage', 'Wall paint, sage', 'Plain', '#b7bfa9', 0.92),
			$material('paint-khaki', 'Wall paint, khaki', 'Plain', '#a59a7c', 0.92),
			$material('paint-terracotta', 'Wall paint, terracotta', 'Plain', '#b77457', 0.92),
			$material('paint-charcoal', 'Wall paint, charcoal', 'Plain', '#3e3f41', 0.92),
			$material('fabric-boucle-cream', 'Bouclé fabric, cream', 'Plain', '#e6dfd2', 1.0),
			$material('fabric-velvet-pink', 'Upholstery fabric, pink velvet', 'Plain', '#d4a1a0', 0.88),
			$material('leather-cognac', 'Leather, cognac (seats)', 'Plain', '#8a5a35', 0.55),
			$material('glass-smoked', 'Glass, smoked amber (pendants)', 'Glass', '#c29a6b', 0.05),
			$material('glass-satin', 'Glass, satin white (pendants, shades)', 'Glass', '#f4f2ec', 0.4),
		]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::Codes));
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
		$pdo->exec("UPDATE materials SET pattern = 'Tiles' WHERE pattern IN ('Marble', 'Chevron', 'Zellige', 'Encaustic')");
		$pdo->exec("UPDATE materials SET pattern = 'Plain' WHERE pattern = 'Fluted'");
		$this->table('materials')
			->dropColumn('accent')
			->alterColumn('pattern', Type::Enum, enum: self::Patterns, default: 'Plain')
			->alter();
	}
}
