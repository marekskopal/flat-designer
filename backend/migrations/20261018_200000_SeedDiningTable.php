<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use PDO;
use const JSON_THROW_ON_ERROR;

/**
 * The dining table of flat 50 with four chairs, in solid ash with a honey lacquer (texture cut from the photo of
 * a chair seat): in the living room right of the door from the hallway (as one comes in), the long side along
 * the hallway wall with two chairs on each side. Chairs 400 wide at the front, 375 at the back, 395 deep, 885
 * high, seat at 460; the table 1320 × 700, 780 high. Without the living room of project 1 only the material is
 * added.
 */
final class SeedDiningTableMigration extends Migration
{
	private const int ProjectId = 1;

	private const string RoomCode = 'H1/B_04';

	private const string MaterialCode = 'solid-ash-honey';

	private const string GroupName = 'Dining table';

	private const int TableX = 1400;

	private const int TableY = 4735;

	private const int ChairOffsetX = 330;

	/** Chair centres from the table centre: the seat fronts reach 80 mm under the top. */
	private const int ChairOffsetY = 467;

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');
		$this->table('materials')->insert([
			[
				'code' => self::MaterialCode,
				'name' => 'Solid ash, honey lacquer (dining table, chairs)',
				'pattern' => 'Wood',
				'color' => '#c59048',
				'roughness' => 0.45,
				'metalness' => 0.0,
				'texture' => 'textures/wood-ash-honey.jpg',
				'tile_width' => 164,
				'tile_height' => 300,
				'created_at' => $now,
				'updated_at' => $now,
			],
		]);
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$materialId = (int) $pdo->lastInsertId();

		$select = $pdo->prepare(
			'SELECT r.id, r.floor_id FROM rooms r JOIN floors f ON f.id = r.floor_id
			WHERE f.project_id = ? AND r.code = ? AND r.deleted_at IS NULL ORDER BY r.id LIMIT 1',
		);
		$insertGroup = $pdo->prepare(
			'INSERT INTO item_groups (floor_id, name, variant_set_id, created_at, updated_at) VALUES (?, ?, NULL, NOW(), NOW())',
		);
		if ($select === false || $insertGroup === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$select->execute([self::ProjectId, self::RoomCode]);
		/** @var array{id: int|string, floor_id: int|string}|false $room */
		$room = $select->fetch(PDO::FETCH_ASSOC);
		if ($room === false) {
			return;
		}

		$insertGroup->execute([$room['floor_id'], self::GroupName]);
		$groupId = (int) $pdo->lastInsertId();

		$chair = json_encode(['seatHeight' => 460, 'backWidth' => 375], JSON_THROW_ON_ERROR);
		$rows = [['Table', 'Dining table 1320 × 700', self::TableX, self::TableY, 1320, 700, 780, 0, null]];
		// North chairs face south (rotation 0), south ones north; numbered clockwise from the north-west.
		$chairs = [[1, 'north', -1, 1, 0], [2, 'north', 1, 1, 0], [3, 'south', 1, -1, 180], [4, 'south', -1, -1, 180]];
		foreach ($chairs as [$number, $side, $sx, $sy, $rotation]) {
			$x = self::TableX + $sx * self::ChairOffsetX;
			$y = self::TableY + $sy * self::ChairOffsetY;
			$rows[] = ['Chair', sprintf('Dining chair %d (%s)', $number, $side), $x, $y, 400, 395, 885, $rotation, $chair];
		}

		$this->table('items')->insert(array_map(
			static fn (array $row): array => [
				'floor_id' => (int) $room['floor_id'],
				'room_id' => (int) $room['id'],
				'group_id' => $groupId,
				'kind' => $row[0],
				'name' => $row[1],
				'x' => $row[2],
				'y' => $row[3],
				'z' => 0,
				'width' => $row[4],
				'depth' => $row[5],
				'height' => $row[6],
				'rotation' => $row[7],
				'material_id' => $materialId,
				'params' => $row[8],
				'created_at' => $now,
				'updated_at' => $now,
			],
			$rows,
		));
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec(sprintf(
			"DELETE i FROM items i JOIN item_groups g ON g.id = i.group_id JOIN floors f ON f.id = g.floor_id
			WHERE f.project_id = %d AND g.name = '%s'",
			self::ProjectId,
			self::GroupName,
		));
		$pdo->exec(sprintf(
			"DELETE g FROM item_groups g JOIN floors f ON f.id = g.floor_id WHERE f.project_id = %d AND g.name = '%s'",
			self::ProjectId,
			self::GroupName,
		));
		$pdo->exec(sprintf(
			"UPDATE items SET material_id = NULL WHERE material_id IN (SELECT id FROM materials WHERE code = '%s')",
			self::MaterialCode,
		));
		$pdo->exec(sprintf("DELETE FROM materials WHERE code = '%s'", self::MaterialCode));
	}
}
