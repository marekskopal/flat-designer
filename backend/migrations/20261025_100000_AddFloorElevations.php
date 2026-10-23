<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Project\FloorStack;
use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Stacked floors (FD-36): `floors.elevation`, the finished floor level above the project datum, and
 * `floors.slab_thickness`, the slab under the floor, both mm. Each project's floors are stacked by `FloorStack::stack()`
 * from their levels and tallest walls.
 */
final class AddFloorElevationsMigration extends Migration
{
	public function up(): void
	{
		$this->table('floors')
			->addColumn('elevation', Type::Int, default: 0)
			->addColumn('slab_thickness', Type::Int, default: FloorStack::GroundSlab)
			->alter();

		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$rows = $pdo->query(
			'SELECT f.id, f.project_id, f.level, MAX(w.height) AS tallest FROM floors f '
			. 'LEFT JOIN walls w ON w.floor_id = f.id AND w.deleted_at IS NULL GROUP BY f.id, f.project_id, f.level',
		);
		$update = $pdo->prepare('UPDATE floors SET elevation = ?, slab_thickness = ? WHERE id = ?');
		if ($rows === false || $update === false) {
			throw new \RuntimeException('Could not prepare the floor stacking.');
		}

		/** @var array<int, array<int, array{level: int, tallestWall: int}>> $projects */
		$projects = [];
		/** @var array{id: int|string, project_id: int|string, level: int|string, tallest: int|string|null} $row */
		foreach ($rows->fetchAll() as $row) {
			$projects[(int) $row['project_id']][(int) $row['id']] = [
				'level' => (int) $row['level'],
				'tallestWall' => FloorStack::tallestWall($row['tallest'] === null ? [] : [(int) $row['tallest']]),
			];
		}
		foreach ($projects as $floors) {
			foreach (FloorStack::stack($floors) as $id => ['elevation' => $elevation, 'slabThickness' => $slab]) {
				$update->execute([$elevation, $slab, $id]);
			}
		}
	}

	public function down(): void
	{
		$this->table('floors')
			->dropColumn('elevation')
			->dropColumn('slab_thickness')
			->alter();
	}
}
