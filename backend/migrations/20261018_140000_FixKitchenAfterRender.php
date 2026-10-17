<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use const JSON_THROW_ON_ERROR;

/**
 * Re-lays the kitchen of flat 50 closer to the studio render (`source_files/kitchen/Render 1.png`), measured
 * against the 600 mm oven:
 *
 * - Hob wall (front west, south of the sink run front at y = 6085): a corner unit whose 580 mm blind part hides
 *   behind the sink run, leaving a 320 mm door; 800 mm hob drawers; the 600 mm oven column (drawers 300 + 150, oven
 *   600, microwave 400, door 600) and the 600 mm fridge column (freezer 700, fridge 1350) with the handles at the
 *   left edge. The 450 mm top doors of both columns have no handles.
 * - Sink run: the render's 800 + 600 + 600 does not fit the 1700 mm left of the hob wall, so a single-door 600
 *   sink base (sink 30 mm from the wall), the 600 dishwasher and a 500 base.
 * - Wall cabinets: the green top row is as deep as the tall columns (600); the oak row below stays 350 deep, its
 *   side modules split where the top row splits.
 * - Island: two 600 units, its back in line with the west end of the kitchen run, worktop flush with the units
 *   (no overhang for stools).
 *
 * Items are matched by name; the corner unit is new.
 */
final class FixKitchenAfterRenderMigration extends Migration
{
	private const int ProjectId = 1;

	private const array Base = ['plinth' => 100, 'handle' => 'horizontal'];

	public function up(): void
	{
		$this->insertCorner();

		$door = $this->cabinet([['type' => 'door']]);
		$oven = $this->cabinet([
			['type' => 'drawer', 'height' => 300],
			['type' => 'drawer', 'height' => 150],
			['type' => 'oven', 'height' => 600],
			['type' => 'microwave', 'height' => 400],
			['type' => 'door', 'height' => 600],
			['type' => 'door', 'height' => 450, 'handle' => 'none'],
		]);
		$fridge = $this->cabinet([
			['type' => 'door', 'height' => 700],
			['type' => 'door', 'height' => 1350],
			['type' => 'door', 'height' => 450, 'handle' => 'none'],
		], ['handle' => 'vertical', 'handleSide' => 'left']);
		$this->update([
			'Sink base 800' => ['name' => 'Sink base 600', 'x' => 4171, 'width' => 600, 'params' => $door],
			'Granite sink 1.5 bowl' => ['x' => 4171, 'width' => 540],
			'Dishwasher 600 (integrated)' => ['x' => 4771],
			'Corner base 880' => ['name' => 'Base 500', 'x' => 5321, 'width' => 500, 'params' => $door],

			'Hob drawers 900' => ['name' => 'Hob drawers 800', 'y' => 5365, 'width' => 800],
			'Induction hob 600' => ['y' => 5365],
			'Worktop – hob run' => ['y' => 5515, 'width' => 1100],
			'Backsplash – hob run' => ['y' => 5815, 'width' => 1700],
			'Tall oven + microwave 600' => ['y' => 4665, 'params' => $oven],
			'Tall integrated fridge 600' => ['y' => 4065, 'params' => $fridge],

			'Wall cabinet back 1' => ['x' => 4111, 'width' => 480],
			'Wall cabinet back 2' => ['x' => 4651],
			'Wall cabinet back 3' => ['x' => 5251, 'width' => 600],
			'Wall cabinet back 4' => ['x' => 5851],
			'Wall cabinet back 1 – top' => ['x' => 4111, 'y' => 6365, 'width' => 480, 'depth' => 600],
			'Wall cabinet back 2 – top' => ['x' => 4651, 'y' => 6365, 'depth' => 600],
			'Wall cabinet back 3 – top' => ['x' => 5251, 'y' => 6365, 'width' => 600, 'depth' => 600],
			'Wall cabinet back 4 – top' => ['x' => 5851, 'y' => 6365, 'depth' => 600],
			'Wall cabinet side 1' => ['y' => 5977, 'width' => 676],
			'Wall cabinet side 2' => ['y' => 5302, 'width' => 674],
			'Wall cabinet side 1 – top' => ['x' => 5851, 'y' => 5852, 'width' => 426, 'depth' => 600],
			'Wall cabinet side 2 – top' => ['x' => 5851, 'y' => 5302, 'width' => 674, 'depth' => 600],
			'LED strip – hob run' => ['y' => 5640, 'width' => 1350],

			'Island drawers 600' => ['x' => 4161, 'y' => 4685],
			'Island doors 800' => ['name' => 'Island doors 600', 'x' => 4161, 'y' => 4085, 'width' => 600, 'params' => $door],
			'Island worktop' => ['x' => 4171, 'y' => 4385, 'width' => 1200, 'depth' => 600],
		]);
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec(sprintf(
			"DELETE i FROM items i JOIN floors f ON f.id = i.floor_id WHERE f.project_id = %d AND i.name = 'Corner base 900'",
			self::ProjectId,
		));

		$oven = $this->cabinet([
			['type' => 'drawer', 'height' => 300],
			['type' => 'drawer', 'height' => 300],
			['type' => 'oven', 'height' => 600],
			['type' => 'microwave', 'height' => 450],
			['type' => 'door', 'height' => 450],
			['type' => 'door', 'height' => 400],
		]);
		$fridge = $this->cabinet([
			['type' => 'door', 'height' => 800],
			['type' => 'door', 'height' => 1250],
			['type' => 'door', 'height' => 450],
		], ['handle' => 'vertical']);
		$doubleDoor = $this->cabinet([['type' => 'door', 'count' => 2]]);
		$corner = $this->cabinet([['type' => 'door']], ['blindRight' => 580]);
		$this->update([
			'Sink base 600' => ['name' => 'Sink base 800', 'x' => 4271, 'width' => 800, 'params' => $doubleDoor],
			'Granite sink 1.5 bowl' => ['x' => 4271, 'width' => 780],
			'Dishwasher 600 (integrated)' => ['x' => 4971],
			'Base 500' => ['name' => 'Corner base 880', 'x' => 5711, 'width' => 880, 'params' => $corner],

			'Hob drawers 800' => ['name' => 'Hob drawers 900', 'y' => 5635, 'width' => 900],
			'Induction hob 600' => ['y' => 5635],
			'Worktop – hob run' => ['y' => 5625, 'width' => 880],
			'Backsplash – hob run' => ['y' => 5925, 'width' => 1480],
			'Tall oven + microwave 600' => ['y' => 4885, 'params' => $oven],
			'Tall integrated fridge 600' => ['y' => 4285, 'params' => $fridge],

			'Wall cabinet back 1' => ['x' => 4171, 'width' => 600],
			'Wall cabinet back 2' => ['x' => 4771],
			'Wall cabinet back 3' => ['x' => 5311, 'width' => 480],
			'Wall cabinet back 4' => ['x' => 5851],
			'Wall cabinet back 1 – top' => ['x' => 4171, 'y' => 6490, 'width' => 600, 'depth' => 350],
			'Wall cabinet back 2 – top' => ['x' => 4771, 'y' => 6490, 'depth' => 350],
			'Wall cabinet back 3 – top' => ['x' => 5311, 'y' => 6490, 'width' => 480, 'depth' => 350],
			'Wall cabinet back 4 – top' => ['x' => 5851, 'y' => 6490, 'depth' => 350],
			'Wall cabinet side 1' => ['y' => 6033, 'width' => 565],
			'Wall cabinet side 2' => ['y' => 5468, 'width' => 565],
			'Wall cabinet side 1 – top' => ['x' => 5976, 'y' => 6033, 'width' => 565, 'depth' => 350],
			'Wall cabinet side 2 – top' => ['x' => 5976, 'y' => 5468, 'width' => 565, 'depth' => 350],
			'LED strip – hob run' => ['y' => 5750, 'width' => 1130],

			'Island drawers 600' => ['x' => 4260, 'y' => 4685],
			'Island doors 600' => ['name' => 'Island doors 800', 'x' => 4260, 'y' => 3985, 'width' => 800, 'params' => $doubleDoor],
			'Island worktop' => ['x' => 4160, 'y' => 4285, 'width' => 1600, 'depth' => 820],
		]);
	}

	/** The corner unit on the hob wall, next to the hob drawers, in their room and group. */
	private function insertCorner(): void
	{
		$stmt = $this->databaseProvider->getDatabase()->getPdo()->prepare(
			"INSERT INTO items (floor_id, room_id, group_id, kind, name, x, y, z, width, depth, height, rotation, material_id, params,
				created_at, updated_at)
			SELECT i.floor_id, i.room_id, i.group_id, 'BaseCabinet', 'Corner base 900', 5861, 6215, 0, 900, 580, 860, -90, i.material_id,
				:params, NOW(), NOW()
			FROM items i JOIN floors f ON f.id = i.floor_id
			WHERE f.project_id = :project AND i.name = 'Hob drawers 900' AND i.deleted_at IS NULL
			ORDER BY i.id LIMIT 1",
		);
		if ($stmt === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}

		$stmt->execute([
			'project' => self::ProjectId,
			'params' => json_encode($this->cabinet([['type' => 'door']], ['blindLeft' => 580]), JSON_THROW_ON_ERROR),
		]);
	}

	/**
	 * Params of a plinth cabinet with horizontal handles.
	 *
	 * @param list<array<string, int|string>> $fronts
	 * @param array<string, int|string> $params
	 * @return array<string, mixed>
	 */
	private function cabinet(array $fronts, array $params = []): array
	{
		return array_merge(self::Base, $params, ['fronts' => $fronts]);
	}

	/** @param array<string, array<string, int|string|array<string, mixed>>> $changes item name => columns to set */
	private function update(array $changes): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		foreach ($changes as $name => $columns) {
			$values = array_map(
				static fn (int|string|array $value): int|string => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
				$columns,
			);
			$stmt = $pdo->prepare(sprintf(
				'UPDATE items i JOIN floors f ON f.id = i.floor_id SET %s WHERE f.project_id = :project AND i.name = :match',
				implode(', ', array_map(static fn (string $column): string => sprintf('i.%1$s = :%1$s', $column), array_keys($columns))),
			));
			if ($stmt === false) {
				throw new \RuntimeException('Could not prepare statement.');
			}

			$stmt->execute([...$values, 'project' => self::ProjectId, 'match' => $name]);
		}
	}
}
