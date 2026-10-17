<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

final class InitMigration extends Migration
{
	public function up(): void
	{
		$this->table('flats')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('name', Type::String)
			->addColumn('address', Type::String, nullable: true)
			->addColumn('description', Type::Text, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->create();

		$this->table('materials')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('code', Type::String, size: 64)
			->addColumn('name', Type::String)
			->addColumn('pattern', Type::Enum, enum: ['Plain', 'Wood', 'Planks', 'Tiles', 'Glass'], default: 'Plain')
			->addColumn('color', Type::String, size: 7)
			->addColumn('roughness', Type::Float)
			->addColumn('metalness', Type::Float)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['code'], 'materials_code_unique', true)
			->create();

		$this->table('rooms')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('flat_id', Type::Int, size: 11)
			->addColumn('code', Type::String, size: 32)
			->addColumn('name', Type::String)
			->addColumn('type', Type::Enum, enum: ['Living', 'Bedroom', 'Bathroom', 'Toilet', 'Hallway', 'Terrace'])
			->addColumn('polygon', Type::Text)
			->addColumn('ceiling_height', Type::Int, nullable: true)
			->addColumn('floor_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['flat_id'], 'rooms_flat_id_index', false)
			->addForeignKey('flat_id', 'flats', 'id', 'rooms_flat_id_fk')
			->addForeignKey('floor_material_id', 'materials', 'id', 'rooms_floor_material_id_fk')
			->create();

		$this->table('walls')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('flat_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('type', Type::Enum, enum: ['Exterior', 'Interior', 'Shaft'])
			->addColumn('start_x', Type::Int)
			->addColumn('start_y', Type::Int)
			->addColumn('end_x', Type::Int)
			->addColumn('end_y', Type::Int)
			->addColumn('thickness', Type::Int)
			->addColumn('height', Type::Int)
			->addColumn('material_id', Type::Int, size: 11, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['flat_id'], 'walls_flat_id_index', false)
			->addForeignKey('flat_id', 'flats', 'id', 'walls_flat_id_fk')
			->addForeignKey('material_id', 'materials', 'id', 'walls_material_id_fk')
			->create();

		$this->table('openings')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('wall_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('type', Type::Enum, enum: ['Door', 'Window'])
			->addColumn('offset', Type::Int)
			->addColumn('width', Type::Int)
			->addColumn('height', Type::Int)
			->addColumn('sill_height', Type::Int, default: 0)
			->addColumn('leaf_width', Type::Int, nullable: true)
			->addColumn('hinge', Type::Enum, enum: ['Start', 'End'], nullable: true)
			->addColumn('side', Type::Enum, enum: ['Left', 'Right'], nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['wall_id'], 'openings_wall_id_index', false)
			->addForeignKey('wall_id', 'walls', 'id', 'openings_wall_id_fk')
			->create();

		$this->table('items')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('flat_id', Type::Int, size: 11)
			->addColumn('room_id', Type::Int, size: 11, nullable: true)
			->addColumn(
				'kind',
				Type::Enum,
				enum: ['BaseCabinet', 'WallCabinet', 'TallCabinet', 'Worktop', 'Backsplash', 'Sink', 'Hob', 'LedStrip'],
			)
			->addColumn('name', Type::String)
			->addColumn('x', Type::Int)
			->addColumn('y', Type::Int)
			->addColumn('z', Type::Int, default: 0)
			->addColumn('width', Type::Int)
			->addColumn('depth', Type::Int)
			->addColumn('height', Type::Int)
			->addColumn('rotation', Type::Int, default: 0)
			->addColumn('material_id', Type::Int, size: 11, nullable: true)
			->addColumn('params', Type::Text, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['flat_id'], 'items_flat_id_index', false)
			->addForeignKey('flat_id', 'flats', 'id', 'items_flat_id_fk')
			->addForeignKey('room_id', 'rooms', 'id', 'items_room_id_fk')
			->addForeignKey('material_id', 'materials', 'id', 'items_material_id_fk')
			->create();
	}

	public function down(): void
	{
		$this->table('items')->drop();
		$this->table('openings')->drop();
		$this->table('walls')->drop();
		$this->table('rooms')->drop();
		$this->table('materials')->drop();
		$this->table('flats')->drop();
	}
}
