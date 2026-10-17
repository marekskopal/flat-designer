<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use PDO;

/**
 * Groups the furniture of flat 50 that belongs together: the two kitchen runs with their tall column,
 * wall cabinets and LED strips, the kitchen island, and the hall stand (when it is there — it was added
 * in the editor, not by a seed). Items are matched by name within the kitchen and hallway rooms.
 */
final class SeedItemGroupsMigration extends Migration
{
	private const int ProjectId = 1;

	private const array KitchenKinds = ['BaseCabinet', 'WallCabinet', 'TallCabinet', 'Worktop', 'Backsplash', 'Sink', 'Hob', 'LedStrip'];

	public function up(): void
	{
		$kinds = "'" . implode("', '", self::KitchenKinds) . "'";
		$this->group('Kitchen runs', sprintf("r.code = 'H1/B_04' AND i.kind IN (%s) AND i.name NOT LIKE 'Island %%'", $kinds));
		$this->group('Kitchen island', "r.code = 'H1/B_04' AND i.name LIKE 'Island %'");
		$this->group('Hall stand', "r.code = 'H1/B_01' AND i.name LIKE 'Hall stand %'");
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec(sprintf(
			"DELETE g FROM item_groups g JOIN floors f ON f.id = g.floor_id WHERE f.project_id = %d AND g.name IN ('Kitchen runs', 'Kitchen island', 'Hall stand')",
			self::ProjectId,
		));
	}

	/** Creates the group on the floor of the matching items (none match: no group). */
	private function group(string $name, string $condition): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$select = $pdo->query(sprintf(
			'SELECT i.id, i.floor_id FROM items i JOIN floors f ON f.id = i.floor_id JOIN rooms r ON r.id = i.room_id '
			. 'WHERE f.project_id = %d AND i.deleted_at IS NULL AND %s ORDER BY i.id',
			self::ProjectId,
			$condition,
		));
		if ($select === false) {
			throw new \RuntimeException('Could not select the items of ' . $name . '.');
		}

		/** @var list<array{id: int|string, floor_id: int|string}> $items */
		$items = $select->fetchAll(PDO::FETCH_ASSOC);
		if ($items === []) {
			return;
		}

		$insert = $pdo->prepare('INSERT INTO item_groups (floor_id, name, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
		if ($insert === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$insert->execute([$items[0]['floor_id'], $name]);
		$groupId = (int) $pdo->lastInsertId();

		$pdo->exec(sprintf(
			'UPDATE items SET group_id = %d WHERE id IN (%s)',
			$groupId,
			implode(', ', array_map(static fn (array $item): int => (int) $item['id'], $items)),
		));
	}
}
