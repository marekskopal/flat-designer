<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Curved stairs: the shape `Curved` and its `inner_radius`, a flight curving round a centre. */
final class AddCurvedStairsMigration extends Migration
{
	private const array Shapes = ['Straight', 'LShaped', 'UShaped', 'Spiral'];

	public function up(): void
	{
		$this->table('stairs')
			->alterColumn('shape', Type::Enum, enum: [...self::Shapes, 'Curved'])
			->addColumn('inner_radius', Type::Int, nullable: true)
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("UPDATE stairs SET shape = 'Straight' WHERE shape = 'Curved'");
		$this->table('stairs')
			->alterColumn('shape', Type::Enum, enum: self::Shapes)
			->dropColumn('inner_radius')
			->alter();
	}
}
