<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Roofs in the quantities (FD-38): prices of a roof's covering and soffit, the surfaces `RoofCovering` and `Soffit`. */
final class AddRoofQuantitySurfacesMigration extends Migration
{
	private const array Surfaces = ['Floor', 'WallFinish', 'Wall', 'Ceiling', 'Beams', 'Skirting', 'Furniture'];

	public function up(): void
	{
		$this->table('project_material_prices')
			->alterColumn('surface', Type::Enum, enum: [...self::Surfaces, 'RoofCovering', 'Soffit'], nullable: true)
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM project_material_prices WHERE surface IN ('RoofCovering', 'Soffit')");
		$this->table('project_material_prices')->alterColumn('surface', Type::Enum, enum: self::Surfaces, nullable: true)->alter();
	}
}
