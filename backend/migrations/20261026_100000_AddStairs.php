<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/** Stairs (FD-37): from their floor up to the next one, with the void they cut through it. */
final class AddStairsMigration extends Migration
{
	private const array ElementTypes = ['Item', 'Wall', 'Opening', 'Room', 'Group', 'VariantSet', 'Plot'];

	public function up(): void
	{
		$this->table('stairs')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('shape', Type::Enum, enum: ['Straight', 'LShaped', 'UShaped', 'Spiral'])
			->addColumn('x', Type::Int)
			->addColumn('y', Type::Int)
			->addColumn('rotation', Type::Int)
			->addColumn('width', Type::Int)
			->addColumn('going', Type::Int)
			->addColumn('risers', Type::Int, nullable: true)
			->addColumn('first_flight', Type::Int, nullable: true)
			->addColumn('turn', Type::Enum, enum: ['Left', 'Right'])
			->addColumn('gap', Type::Int)
			->addColumn('construction', Type::Enum, enum: ['Solid', 'Stringers', 'Open'])
			->addColumn('railing', Type::Enum, enum: ['None', 'Left', 'Right', 'Both'])
			->addColumn('tread_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('structure_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['floor_id'], 'stairs_floor_id_index', false)
			->addForeignKey('floor_id', 'floors', 'id', 'stairs_floor_id_fk')
			->addForeignKey('tread_material_id', 'materials', 'id', 'stairs_tread_material_id_fk', ReferenceOptionEnum::SetNull)
			->addForeignKey('structure_material_id', 'materials', 'id', 'stairs_structure_material_id_fk', ReferenceOptionEnum::SetNull)
			->create();

		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: [...self::ElementTypes, 'Stair'])
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM project_changes WHERE element_type = 'Stair'");
		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: self::ElementTypes)
			->alter();
		$this->table('stairs')->drop();
	}
}
