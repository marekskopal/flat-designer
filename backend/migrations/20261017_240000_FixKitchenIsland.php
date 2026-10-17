<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Turns the kitchen island of flat 50 as in the studio render (`source_files/kitchen/Render 1.png`): its long
 * side runs north–south in front of the sink run, fronts facing east towards the hob run and the tall column,
 * worktop overhanging west. The seed laid it east–west with fronts north, west of the kitchen niche.
 *
 * Aisles: 1000 mm to the sink run (front at y = 6085), 1000 mm to the hob run (front at x = 5571).
 */
final class FixKitchenIslandMigration extends Migration
{
	private const int ProjectId = 1;

	private const array Island = [
		'Island drawers 600' => [4260, 4685, 90],
		'Island doors 800' => [4260, 3985, 90],
		'Island worktop' => [4160, 4285, 90],
	];

	private const array Seeded = [
		'Island drawers 600' => [4150, 4390, 180],
		'Island doors 800' => [3450, 4390, 180],
		'Island worktop' => [3750, 4290, 0],
	];

	public function up(): void
	{
		$this->move(self::Island);
	}

	public function down(): void
	{
		$this->move(self::Seeded);
	}

	/** @param array<string, array{int, int, int}> $positions */
	private function move(array $positions): void
	{
		$stmt = $this->databaseProvider->getDatabase()->getPdo()->prepare(
			'UPDATE items i JOIN floors f ON f.id = i.floor_id SET i.x = :x, i.y = :y, i.rotation = :rotation
			WHERE f.project_id = :project AND i.name = :name',
		);
		if ($stmt === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}

		foreach ($positions as $name => [$x, $y, $rotation]) {
			$stmt->execute(['x' => $x, 'y' => $y, 'rotation' => $rotation, 'project' => self::ProjectId, 'name' => $name]);
		}
	}
}
