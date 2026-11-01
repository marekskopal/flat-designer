<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Fences along polylines (FD-100): railing styles `Mesh` and `Boards`, and `follow` (Level, Rake, Step) on the ground. */
final class AddFenceRailingsMigration extends Migration
{
	private const array Styles = ['Balusters', 'Rods', 'Glass', 'Panel', 'Cable'];

	public function up(): void
	{
		$this->table('railings')
			->alterColumn('style', Type::Enum, enum: [...self::Styles, 'Mesh', 'Boards'])
			->addColumn('follow', Type::Enum, enum: ['Level', 'Rake', 'Step'], default: 'Level')
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("UPDATE railings SET style = 'Panel' WHERE style IN ('Mesh', 'Boards')");
		$this->table('railings')
			->alterColumn('style', Type::Enum, enum: self::Styles)
			->dropColumn('follow')
			->alter();
	}
}
