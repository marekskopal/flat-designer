<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Façades (FD-88): a project's default façade material (`projects.exterior_material_id`, for the outer faces of its
 * exterior walls nobody set), a room's slab soffit (`rooms.soffit_material_id`, under a floor overhanging the one
 * below), an opening's lined reveal (`openings.reveal_lining`) and prices of the surface `Facade`.
 */
final class AddFacadesMigration extends Migration
{
	private const array Surfaces = ['Floor', 'WallFinish', 'Wall', 'Ceiling', 'Beams', 'Skirting', 'Furniture', 'RoofCovering', 'Soffit'];

	public function up(): void
	{
		$this->table('projects')
			->addColumn('exterior_material_id', Type::Int, size: 11, nullable: true)
			->addForeignKey('exterior_material_id', 'materials', 'id', 'projects_exterior_material_id_fk', ReferenceOptionEnum::SetNull)
			->alter();
		$this->table('rooms')
			->addColumn('soffit_material_id', Type::Int, size: 11, nullable: true)
			->addForeignKey('soffit_material_id', 'materials', 'id', 'rooms_soffit_material_id_fk', ReferenceOptionEnum::SetNull)
			->alter();
		$this->table('openings')
			->addColumn('reveal_lining', Type::Boolean, default: false)
			->alter();
		$this->table('project_material_prices')
			->alterColumn('surface', Type::Enum, enum: [...self::Surfaces, 'Facade'], nullable: true)
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM project_material_prices WHERE surface = 'Facade'");
		$this->table('project_material_prices')->alterColumn('surface', Type::Enum, enum: self::Surfaces, nullable: true)->alter();
		$this->table('openings')->dropColumn('reveal_lining')->alter();
		$this->table('rooms')
			->dropForeignKey('rooms_soffit_material_id_fk')
			->dropColumn('soffit_material_id')
			->alter();
		$this->table('projects')
			->dropForeignKey('projects_exterior_material_id_fk')
			->dropColumn('exterior_material_id')
			->alter();
	}
}
