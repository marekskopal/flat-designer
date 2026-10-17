<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/** Variant sets: alternative item groups of one piece of furniture, of which one is shown. */
final class AddVariantSetsMigration extends Migration
{
	private const array ElementTypes = ['Item', 'Wall', 'Opening', 'Room', 'Group'];

	public function up(): void
	{
		$this->table('variant_sets')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('active_group_id', Type::Int, size: 11, nullable: true)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['floor_id'], 'variant_sets_floor_id_index', false)
			->addForeignKey('floor_id', 'floors', 'id', 'variant_sets_floor_id_fk')
			->addForeignKey('active_group_id', 'item_groups', 'id', 'variant_sets_active_group_id_fk', ReferenceOptionEnum::SetNull)
			->create();

		$this->table('item_groups')
			->addColumn('variant_set_id', Type::Int, size: 11, nullable: true)
			->addIndex(['variant_set_id'], 'item_groups_variant_set_id_index', false)
			->addForeignKey('variant_set_id', 'variant_sets', 'id', 'item_groups_variant_set_id_fk', ReferenceOptionEnum::SetNull)
			->alter();

		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: [...self::ElementTypes, 'VariantSet'])
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM project_changes WHERE element_type = 'VariantSet'");
		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: self::ElementTypes)
			->alter();

		$pdo->exec(
			'ALTER TABLE item_groups DROP FOREIGN KEY item_groups_variant_set_id_fk, DROP KEY item_groups_variant_set_id_index, '
			. 'DROP COLUMN variant_set_id',
		);
		$this->table('variant_sets')->drop();
	}
}
