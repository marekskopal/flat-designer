<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use PDO;
use const JSON_THROW_ON_ERROR;

/**
 * A second layout of the kitchen runs of flat 50, as variants of a new set "Kitchen run" next to "Kitchen layout"
 * (island or not): the existing "Kitchen runs" group becomes the variant "Columns on the hob wall", the new one
 * "Columns at both ends" puts a tall column at each end of the L-shaped worktop:
 *
 * - Sink wall (front south): the fridge column in the west corner of the niche, the dishwasher, then the sink on a
 *   500 mm base by the corner.
 * - Hob wall (front west): a corner unit whose 580 mm blind part hides behind the sink wall run (620 mm door), the
 *   hob drawers and a 300 mm pull-out next to the oven + microwave column, which stands where the fridge does in the
 *   other layout.
 * - Wall cabinets as in the other layout: oak 350 deep, the green top row 600 deep, modules split alike.
 *
 * Needs the "Kitchen runs" group of `SeedItemGroups`; without it nothing is added. Item names differ from the other
 * layout's, which earlier migrations match by name.
 */
final class SeedKitchenColumnsAtBothEndsMigration extends Migration
{
	private const int ProjectId = 1;

	private const string SetName = 'Kitchen run';

	private const string RunsGroup = 'Kitchen runs';

	private const string CurrentVariant = 'Columns on the hob wall';

	private const string NewVariant = 'Columns at both ends';

	private const int Green = 9;

	private const int Oak = 10;

	private const int BlackGlass = 11;

	private const int Granite = 12;

	private const int Led = 13;

	private const array Base = ['plinth' => 100, 'handle' => 'horizontal'];

	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$select = $pdo->prepare(
			'SELECT g.id, g.floor_id, (SELECT i.room_id FROM items i WHERE i.group_id = g.id AND i.deleted_at IS NULL LIMIT 1) AS room_id
			FROM item_groups g JOIN floors f ON f.id = g.floor_id
			WHERE f.project_id = ? AND g.name = ? AND g.deleted_at IS NULL ORDER BY g.id LIMIT 1',
		);
		if ($select === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$select->execute([self::ProjectId, self::RunsGroup]);
		/** @var array{id: int|string, floor_id: int|string, room_id: int|string|null}|false $runs */
		$runs = $select->fetch(PDO::FETCH_ASSOC);
		if ($runs === false) {
			return;
		}

		$insertSet = $pdo->prepare(
			'INSERT INTO variant_sets (floor_id, name, active_group_id, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
		);
		$updateGroup = $pdo->prepare('UPDATE item_groups SET name = ?, variant_set_id = ? WHERE id = ?');
		$insertGroup = $pdo->prepare(
			'INSERT INTO item_groups (floor_id, name, variant_set_id, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
		);
		if ($insertSet === false || $updateGroup === false || $insertGroup === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}

		$insertSet->execute([$runs['floor_id'], self::SetName, $runs['id']]);
		$setId = (int) $pdo->lastInsertId();
		$updateGroup->execute([self::CurrentVariant, $setId, $runs['id']]);
		$insertGroup->execute([$runs['floor_id'], self::NewVariant, $setId]);
		$groupId = (int) $pdo->lastInsertId();

		$now = date('Y-m-d H:i:s');
		$this->table('items')->insert(array_map(
			fn (array $row): array => [
				...$row,
				'floor_id' => (int) $runs['floor_id'],
				'room_id' => $runs['room_id'] === null ? null : (int) $runs['room_id'],
				'group_id' => $groupId,
				'created_at' => $now,
				'updated_at' => $now,
			],
			$this->items(),
		));
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec(sprintf(
			"DELETE i FROM items i JOIN item_groups g ON g.id = i.group_id JOIN floors f ON f.id = g.floor_id
			WHERE f.project_id = %d AND g.name = '%s'",
			self::ProjectId,
			self::NewVariant,
		));
		$pdo->exec(sprintf(
			"DELETE g FROM item_groups g JOIN floors f ON f.id = g.floor_id WHERE f.project_id = %d AND g.name = '%s'",
			self::ProjectId,
			self::NewVariant,
		));
		$pdo->exec(sprintf(
			"UPDATE item_groups g JOIN floors f ON f.id = g.floor_id SET g.name = '%s', g.variant_set_id = NULL
			WHERE f.project_id = %d AND g.name = '%s'",
			self::RunsGroup,
			self::ProjectId,
			self::CurrentVariant,
		));
		$pdo->exec(sprintf(
			"DELETE s FROM variant_sets s JOIN floors f ON f.id = s.floor_id WHERE f.project_id = %d AND s.name = '%s'",
			self::ProjectId,
			self::SetName,
		));
	}

	/** @return list<array<string, string|int|null>> */
	private function items(): array
	{
		$door = $this->cabinet([['type' => 'door']]);
		$drawers = $this->cabinet([
			['type' => 'drawer', 'height' => 180],
			['type' => 'drawer', 'height' => 290],
			['type' => 'drawer', 'height' => 290],
		]);
		$fridge = $this->cabinet([
			['type' => 'door', 'height' => 700],
			['type' => 'door', 'height' => 1350],
			['type' => 'door', 'height' => 450, 'handle' => 'none'],
		], ['handle' => 'vertical']);
		$oven = $this->cabinet([
			['type' => 'drawer', 'height' => 300],
			['type' => 'drawer', 'height' => 150],
			['type' => 'oven', 'height' => 600],
			['type' => 'microwave', 'height' => 400],
			['type' => 'door', 'height' => 600],
			['type' => 'door', 'height' => 450, 'handle' => 'none'],
		]);
		$led = ['color' => '#ffc98a', 'intensity' => 6];

		return [
			// Sink wall, front facing south: fridge column, dishwasher, sink by the corner.
			$this->item('TallCabinet', 'Tall integrated fridge 600 – sink wall', 4171, 6365, 0, 600, 600, 2600, 0, self::Green, $fridge),
			$this->item('BaseCabinet', 'Dishwasher 600 – next to the fridge', 4771, 6375, 0, 600, 580, 860, 0, self::Green, $door),
			$this->item('BaseCabinet', 'Sink base 500 – by the corner', 5321, 6375, 0, 500, 580, 860, 0, self::Green, $door),
			$this->item('Sink', 'Granite sink – by the corner', 5321, 6355, 900, 460, 480, 320, 0, self::Granite, ['bowls' => 1]),
			$this->item('Worktop', 'Worktop – sink wall', 5311, 6365, 860, 1680, 600, 40, 0, self::Oak),
			$this->item('Backsplash', 'Backsplash – sink wall', 5311, 6657, 900, 1680, 16, 550, 0, self::Oak),
			...$this->wallCabinets('sink wall', [[4751, 560, 560], [5311, 560, 560], [5871, 560, 560]], 6490, 6365, 0),
			$this->item('LedStrip', 'LED strip – sink wall', 5311, 6345, 1440, 1680, 20, 8, 0, self::Led, $led),

			// Hob wall, front facing west: corner unit, hob drawers and a pull-out next to the oven column.
			$this->item('BaseCabinet', 'Corner base 1200 – hob wall', 5861, 6065, 0, 1200, 580, 860, -90, self::Green, $this->cabinet(
				[['type' => 'door']],
				['blindLeft' => 580],
			)),
			$this->item('BaseCabinet', 'Hob drawers 800 – by the oven', 5861, 5065, 0, 800, 580, 860, -90, self::Green, $drawers),
			$this->item('BaseCabinet', 'Pull-out 300 – by the oven', 5861, 4515, 0, 300, 580, 860, -90, self::Green, $this->cabinet(
				[['type' => 'drawer']],
			)),
			$this->item('Hob', 'Induction hob 600 – by the oven', 5861, 5065, 900, 590, 520, 6, -90, self::BlackGlass),
			$this->item(
				'TallCabinet',
				'Tall oven + microwave 600 – hob wall end',
				5851,
				4065,
				0,
				600,
				600,
				2600,
				-90,
				self::Green,
				$oven,
			),
			$this->item('Worktop', 'Worktop – hob wall', 5851, 5215, 860, 1700, 600, 40, -90, self::Oak),
			$this->item('Backsplash', 'Backsplash – hob wall', 6143, 5515, 900, 2300, 16, 550, -90, self::Oak),
			...$this->wallCabinets('hob wall', [[5990, 650, 0], [5340, 650, 650], [4690, 650, 650]], 5976, 5851, -90),
			$this->item('WallCabinet', 'Wall cabinet hob wall 1 – top', 5851, 5865, 2150, 400, 600, 450, -90, self::Green, [
				'handle' => 'none',
				'fronts' => [['type' => 'door']],
			]),
			$this->item('LedStrip', 'LED strip – hob wall', 5831, 5340, 1440, 1950, 20, 8, -90, self::Led, $led),
		];
	}

	/**
	 * Oak wall cabinets (1450–2150, 350 deep) with a green top row to the ceiling (2150–2600, 600 deep) above.
	 *
	 * @param list<array{int, int, int}> $modules [centre along the wall, oak width, top width — 0: no top above it]
	 * @return list<array<string, string|int|null>>
	 */
	private function wallCabinets(string $label, array $modules, int $oakDepthCentre, int $topDepthCentre, int $rotation): array
	{
		$rows = [];
		$params = ['handle' => 'none', 'fronts' => [['type' => 'door']]];
		foreach ($modules as $i => [$centre, $width, $topWidth]) {
			$name = sprintf('Wall cabinet %s %d', $label, $i + 1);
			[$x, $y] = $rotation === 0 ? [$centre, $oakDepthCentre] : [$oakDepthCentre, $centre];
			$rows[] = $this->item('WallCabinet', $name, $x, $y, 1450, $width, 350, 700, $rotation, self::Oak, $params);
			if ($topWidth <= 0) {
				continue;
			}

			[$x, $y] = $rotation === 0 ? [$centre, $topDepthCentre] : [$topDepthCentre, $centre];
			$rows[] = $this->item('WallCabinet', $name . ' – top', $x, $y, 2150, $topWidth, 600, 450, $rotation, self::Green, $params);
		}

		return $rows;
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

	/**
	 * @param array<string, mixed>|null $params
	 * @return array<string, string|int|null>
	 */
	private function item(
		string $kind,
		string $name,
		int $x,
		int $y,
		int $z,
		int $width,
		int $depth,
		int $height,
		int $rotation,
		int $materialId,
		?array $params = null,
	): array {
		return [
			'kind' => $kind,
			'name' => $name,
			'x' => $x,
			'y' => $y,
			'z' => $z,
			'width' => $width,
			'depth' => $depth,
			'height' => $height,
			'rotation' => $rotation,
			'material_id' => $materialId,
			'params' => $params === null ? null : json_encode($params, JSON_THROW_ON_ERROR),
		];
	}
}
