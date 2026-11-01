<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Opening materials and garages (FD-89): an opening's frame, outside frame and leaf materials, boarded leaves
 * (`board_direction`), the opening type `GarageDoor` with its `garage_door_style`, a lift-and-slide door's
 * `fixed_panes`, the frame's `inset` from the outer face, the room type `Garage` and its concrete floor.
 */
final class AddOpeningMaterialsAndGaragesMigration extends Migration
{
	private const array Types = ['Door', 'DoubleDoor', 'SlidingDoor', 'PocketDoor', 'Passage', 'Window', 'Niche'];

	private const array LeafStyles = ['Flush', 'Panelled', 'Glazed'];

	private const array RoomTypes = ['Living', 'Bedroom', 'Bathroom', 'Toilet', 'Hallway', 'Terrace', 'Garden', 'Path', 'Driveway'];

	private const array Materials = ['frame_material_id', 'outside_frame_material_id', 'leaf_material_id'];

	public function up(): void
	{
		$openings = $this->table('openings')
			->alterColumn('type', Type::Enum, enum: [...self::Types, 'GarageDoor'])
			->alterColumn('leaf_style', Type::Enum, enum: [...self::LeafStyles, 'Boarded'], default: 'Flush');
		foreach (self::Materials as $column) {
			$openings
				->addColumn($column, Type::Int, size: 11, nullable: true)
				->addForeignKey($column, 'materials', 'id', 'openings_' . $column . '_fk', ReferenceOptionEnum::SetNull);
		}
		$openings
			->addColumn('board_direction', Type::Enum, enum: ['Vertical', 'Horizontal'], default: 'Vertical')
			->addColumn('garage_door_style', Type::Enum, enum: ['Sectional', 'UpAndOver', 'SideHinged', 'Roller'], nullable: true)
			->addColumn('fixed_panes', Type::Int, default: 0)
			->addColumn('inset', Type::Int, nullable: true)
			->alter();
		$this->table('rooms')
			->alterColumn('type', Type::Enum, enum: [...self::RoomTypes, 'Garage'])
			->alter();

		$now = date('Y-m-d H:i:s');
		$this->table('materials')->insert([
			[
				'code' => 'concrete-floor',
				'name' => 'Concrete floor, power-floated',
				'pattern' => 'Plain',
				'color' => '#a19f9a',
				'roughness' => 0.8,
				'metalness' => 0.0,
				'tile_width' => null,
				'tile_height' => null,
				'created_at' => $now,
				'updated_at' => $now,
			]]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM materials WHERE code = 'concrete-floor'");
		$pdo->exec("UPDATE rooms SET type = 'Hallway' WHERE type = 'Garage'");
		$this->table('rooms')->alterColumn('type', Type::Enum, enum: self::RoomTypes)->alter();
		$pdo->exec("UPDATE openings SET type = 'DoubleDoor' WHERE type = 'GarageDoor'");
		$pdo->exec("UPDATE openings SET leaf_style = 'Flush' WHERE leaf_style = 'Boarded'");
		$openings = $this->table('openings');
		foreach (self::Materials as $column) {
			$openings->dropForeignKey('openings_' . $column . '_fk')->dropColumn($column);
		}
		$openings
			->dropColumn('board_direction')
			->dropColumn('garage_door_style')
			->dropColumn('fixed_panes')
			->dropColumn('inset')
			->alterColumn('type', Type::Enum, enum: self::Types)
			->alterColumn('leaf_style', Type::Enum, enum: self::LeafStyles, default: 'Flush')
			->alter();
	}
}
