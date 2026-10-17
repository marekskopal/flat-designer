<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

final class AddTexturesAndWallFinishesMigration extends Migration
{
	private const array ItemKinds = ['BaseCabinet', 'WallCabinet', 'TallCabinet', 'Worktop', 'Backsplash', 'Sink', 'Hob', 'LedStrip'];

	private const array SanitaryKinds = ['Box', 'Bathtub', 'Washbasin', 'Toilet', 'WashingMachine', 'Mirror', 'TowelRadiator', 'FlushPlate', 'Fixture'];

	public function up(): void
	{
		$this->table('materials')
			->addColumn('texture', Type::String, nullable: true)
			->addColumn('tile_width', Type::Int, nullable: true)
			->addColumn('tile_height', Type::Int, nullable: true)
			->alter();

		$this->table('rooms')
			->addColumn('wall_finishes', Type::Text, nullable: true)
			->alter();

		$this->table('items')
			->alterColumn('kind', Type::Enum, enum: [...self::ItemKinds, ...self::SanitaryKinds])
			->alter();
	}

	public function down(): void
	{
		$this->table('items')
			->alterColumn('kind', Type::Enum, enum: self::ItemKinds)
			->alter();

		$this->table('rooms')
			->dropColumn('wall_finishes')
			->alter();

		$this->table('materials')
			->dropColumn('tile_height')
			->dropColumn('tile_width')
			->dropColumn('texture')
			->alter();
	}
}
