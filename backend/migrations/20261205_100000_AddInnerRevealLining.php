<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** The room's side of an opening's reveal lined with the room's finish band there (`openings.inner_reveal_lining`). */
final class AddInnerRevealLining extends Migration
{
	public function up(): void
	{
		$this->table('openings')
			->addColumn('inner_reveal_lining', Type::Boolean, default: false)
			->alter();
	}

	public function down(): void
	{
		$this->table('openings')->dropColumn('inner_reveal_lining')->alter();
	}
}
