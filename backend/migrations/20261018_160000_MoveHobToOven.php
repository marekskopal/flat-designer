<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Kitchen variant "Columns at both ends" of flat 50: the hob with its drawers goes right next to the oven column, the
 * 300 mm pull-out to the other side of it (towards the corner unit).
 */
final class MoveHobToOvenMigration extends Migration
{
	private const int ProjectId = 1;

	private const array Moved = [
		'Hob drawers 800 – by the oven' => 4765,
		'Induction hob 600 – by the oven' => 4765,
		'Pull-out 300 – by the oven' => 5315,
	];

	private const array Seeded = [
		'Hob drawers 800 – by the oven' => 5065,
		'Induction hob 600 – by the oven' => 5065,
		'Pull-out 300 – by the oven' => 4515,
	];

	public function up(): void
	{
		$this->move(self::Moved);
	}

	public function down(): void
	{
		$this->move(self::Seeded);
	}

	/** @param array<string, int> $positions item name => y */
	private function move(array $positions): void
	{
		$stmt = $this->databaseProvider->getDatabase()->getPdo()->prepare(
			'UPDATE items i JOIN floors f ON f.id = i.floor_id SET i.y = :y WHERE f.project_id = :project AND i.name = :name',
		);
		if ($stmt === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}

		foreach ($positions as $name => $y) {
			$stmt->execute(['y' => $y, 'project' => self::ProjectId, 'name' => $name]);
		}
	}
}
