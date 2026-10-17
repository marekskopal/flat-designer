<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use const JSON_THROW_ON_ERROR;

/**
 * Gardens on both sides of the flat.
 *
 * North (bedroom side), from the floor plan: garden H1/B_12 of 58.28 m², 7200 mm wide between the
 * boundary walls (dimension chain 300 | 7200 | 300), with the 6000 × 2000 terrace paved in 400 × 400
 * slabs and the gravel eaves strip (SO07) in front of the façade. The sheet is cut before the garden
 * ends, so its depth (9891 mm from the façade) is derived from the area. The west side has the panel
 * fence with base boards (SO14, panels 2.25 m); the north and east sides are fenced the same way.
 *
 * South (living-room side): not dimensioned on the sheet beyond the 430 mm gravel strip along the
 * façade. Modelled as 3000 mm deep between the boundary axes — an assumption to replace with the
 * real size.
 */
final class SeedGardensMigration extends Migration
{
	private const array RoomTypes = ['Living', 'Bedroom', 'Bathroom', 'Toilet', 'Hallway', 'Terrace'];

	private const array Patterns = ['Plain', 'Wood', 'Planks', 'Tiles', 'Glass'];

	private const array ItemKinds = [
		'BaseCabinet',
		'WallCabinet',
		'TallCabinet',
		'Worktop',
		'Backsplash',
		'Sink',
		'Hob',
		'LedStrip',
		'Box',
		'Bathtub',
		'Washbasin',
		'Toilet',
		'WashingMachine',
		'Mirror',
		'TowelRadiator',
		'FlushPlate',
		'Fixture',
	];

	private const int Paving = 4;

	private const int Lawn = 21;

	private const int Gravel = 22;

	private const int FenceAnthracite = 23;

	/** North façade outer face and the far end of the north garden. */
	private const int NorthFacade = 14422;

	private const int NorthEnd = 24313;

	/** South façade outer face and the assumed end of the south garden. */
	private const int SouthFacade = -300;

	private const int SouthEnd = -3300;

	public function up(): void
	{
		$this->table('rooms')
			->alterColumn('type', Type::Enum, enum: [...self::RoomTypes, 'Garden'])
			->alter();
		$this->table('materials')
			->alterColumn('pattern', Type::Enum, enum: [...self::Patterns, 'Grass', 'Gravel'], default: 'Plain')
			->alter();
		$this->table('items')
			->alterColumn('kind', Type::Enum, enum: [...self::ItemKinds, 'Fence'])
			->alter();

		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$now = date('Y-m-d H:i:s');
		$stamp = ['created_at' => $now, 'updated_at' => $now];

		// Terrace: concrete paving 400 × 400 as drawn, outline from the paving grid (6000 × 2000).
		$pdo->exec(
			"UPDATE materials SET code = 'terrace-paving', name = 'Terrace paving 400 × 400, concrete', pattern = 'Tiles',"
			. " color = '#b3aea5', roughness = 0.85, tile_width = 400, tile_height = 400 WHERE id = " . self::Paving,
		);
		$terrace = $pdo->prepare('UPDATE rooms SET polygon = :polygon WHERE id = 7');
		if ($terrace === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$terrace->execute(['polygon' => json_encode([[0, 14580], [6000, 14580], [6000, 16580], [0, 16580]], JSON_THROW_ON_ERROR)]);

		$this->table('materials')->insert([
			[...$this->material(self::Lawn, 'lawn', 'Lawn', 'Grass', '#5b7a38', 1.0), ...$stamp],
			[...$this->material(self::Gravel, 'gravel', 'Gravel, washed pebbles', 'Gravel', '#a39d94', 1.0), ...$stamp],
			[
				...$this->material(
					self::FenceAnthracite,
					'fence-anthracite',
					'Fence panels and posts, anthracite',
					'Plain',
					'#383b3e',
					0.55,
					0.3,
				), ...$stamp],
		]);

		$this->table('rooms')->insert([
			[
				'id' => 8,
				'flat_id' => 1,
				'code' => 'H1/B_12',
				'name' => 'Zahrada',
				'type' => 'Garden',
				// Around the terrace and the gravel strip in front of it.
				'polygon' => json_encode([
					[-1050, self::NorthFacade],
					[0, self::NorthFacade],
					[0, 16580],
					[6000, 16580],
					[6000, self::NorthFacade],
					[6151, self::NorthFacade],
					[6151, self::NorthEnd],
					[-1050, self::NorthEnd],
				], JSON_THROW_ON_ERROR),
				'ceiling_height' => null,
				'floor_material_id' => self::Lawn,
				'wall_finishes' => null, ...$stamp,
			],
			[
				'id' => 9,
				'flat_id' => 1,
				'code' => 'H1/B_ZJ',
				'name' => 'Předzahrádka',
				'type' => 'Garden',
				'polygon' => json_encode([
					[-150, self::SouthEnd],
					[6301, self::SouthEnd],
					[6301, self::SouthFacade],
					[-150, self::SouthFacade],
				], JSON_THROW_ON_ERROR),
				'ceiling_height' => null,
				'floor_material_id' => self::Lawn,
				'wall_finishes' => null, ...$stamp,
			],
		]);

		$northLength = self::NorthEnd - self::NorthFacade;
		$southLength = self::SouthFacade - self::SouthEnd;
		$items = [
			// Gravel eaves strips (SO07) along the façades.
			$this->item(8, 'Box', 'Gravel strip in front of the terrace', 3000, 14501, 0, 6000, 158, 20, 0, self::Gravel),
			$this->item(9, 'Box', 'Gravel strip along the south façade', 3076, -515, 0, 6451, 430, 20, 0, self::Gravel),
			// North garden fences.
			$this->fence(8, 'Fence west (SO14)', -1050, self::NorthFacade + intdiv($northLength, 2), $northLength, 90),
			$this->fence(8, 'Fence north', 2551, self::NorthEnd, 7201, 0),
			$this->fence(8, 'Fence east', 6151, self::NorthFacade + intdiv($northLength, 2), $northLength, 90),
			// South garden fences.
			$this->fence(9, 'Fence west', -150, self::SouthEnd + intdiv($southLength, 2), $southLength, 90),
			$this->fence(9, 'Fence south', 3076, self::SouthEnd, 6451, 0),
			$this->fence(9, 'Fence east', 6301, self::SouthEnd + intdiv($southLength, 2), $southLength, 90),
		];
		$this->table('items')->insert(array_map(fn (array $row): array => [...$row, 'flat_id' => 1, ...$stamp], $items));
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('DELETE FROM items WHERE room_id IN (8, 9)');
		$pdo->exec('DELETE FROM rooms WHERE id IN (8, 9)');
		$pdo->exec('DELETE FROM materials WHERE id BETWEEN ' . self::Lawn . ' AND ' . self::FenceAnthracite);
		$pdo->exec(
			"UPDATE materials SET code = 'terrace-wpc', name = 'Terrace decking, WPC grey-brown', pattern = 'Planks',"
			. " color = '#7b6d60', roughness = 0.85, tile_width = NULL, tile_height = NULL WHERE id = " . self::Paving,
		);
		$pdo->exec(
			'UPDATE rooms SET polygon = ' . $pdo->quote(
				json_encode([[0, 14422], [6151, 14422], [6151, 16372], [0, 16372]], JSON_THROW_ON_ERROR),
			)
			. ' WHERE id = 7',
		);

		$this->table('items')->alterColumn('kind', Type::Enum, enum: self::ItemKinds)->alter();
		$this->table('materials')->alterColumn('pattern', Type::Enum, enum: self::Patterns, default: 'Plain')->alter();
		$this->table('rooms')->alterColumn('type', Type::Enum, enum: self::RoomTypes)->alter();
	}

	/** @return array<string, string|int|float|null> */
	private function material(
		int $id,
		string $code,
		string $name,
		string $pattern,
		string $color,
		float $roughness,
		float $metalness = 0.0,
	): array
	{
		return [
			'id' => $id,
			'code' => $code,
			'name' => $name,
			'pattern' => $pattern,
			'color' => $color,
			'roughness' => $roughness,
			'metalness' => $metalness,
			'texture' => null,
			'tile_width' => null,
			'tile_height' => null,
		];
	}

	/**
	 * Panel fence 1530 mm high on 200 mm base boards, posts every 2250 mm; centred on (x, y) along its length.
	 *
	 * @return array<string, string|int|null>
	 */
	private function fence(int $roomId, string $name, int $x, int $y, int $length, int $rotation): array
	{
		return $this->item($roomId, 'Fence', $name, $x, $y, 0, $length, 60, 1530, $rotation, self::FenceAnthracite, [
			'panelLength' => 2250,
			'baseBoard' => 200,
		]);
	}

	/**
	 * @param array<string, mixed>|null $params
	 * @return array<string, string|int|null>
	 */
	private function item(
		int $roomId,
		string $kind,
		string $name,
		int $x,
		int $y,
		int $z,
		int $width,
		int $depth,
		int $height,
		int $rotation,
		int $materialId,
		?array $params = null,
	): array {
		return [
			'room_id' => $roomId,
			'kind' => $kind,
			'name' => $name,
			'x' => $x,
			'y' => $y,
			'z' => $z,
			'width' => $width,
			'depth' => $depth,
			'height' => $height,
			'rotation' => $rotation,
			'material_id' => $materialId,
			'params' => $params === null ? null : json_encode($params, JSON_THROW_ON_ERROR),
		];
	}
}
