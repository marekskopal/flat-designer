<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use PDO;

/**
 * Variant set "Kitchen layout" of flat 50: the kitchen island (active) or an empty "Without island", as the
 * buyer may choose. Needs the "Kitchen island" group of `SeedItemGroups`; without it nothing is added.
 */
final class SeedKitchenLayoutMigration extends Migration
{
	private const int ProjectId = 1;

	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$select = $pdo->prepare(
			"SELECT g.id, g.floor_id FROM item_groups g JOIN floors f ON f.id = g.floor_id
			WHERE f.project_id = ? AND g.name = 'Kitchen island' AND g.deleted_at IS NULL ORDER BY g.id LIMIT 1",
		);
		if ($select === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$select->execute([self::ProjectId]);
		/** @var array{id: int|string, floor_id: int|string}|false $island */
		$island = $select->fetch(PDO::FETCH_ASSOC);
		if ($island === false) {
			return;
		}

		$insertSet = $pdo->prepare(
			"INSERT INTO variant_sets (floor_id, name, active_group_id, created_at, updated_at) VALUES (?, 'Kitchen layout', ?, NOW(), NOW())",
		);
		$insertGroup = $pdo->prepare(
			"INSERT INTO item_groups (floor_id, name, variant_set_id, created_at, updated_at) VALUES (?, 'Without island', ?, NOW(), NOW())",
		);
		$updateGroup = $pdo->prepare('UPDATE item_groups SET variant_set_id = ? WHERE id = ?');
		if ($insertSet === false || $insertGroup === false || $updateGroup === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}

		$insertSet->execute([$island['floor_id'], $island['id']]);
		$setId = (int) $pdo->lastInsertId();
		$updateGroup->execute([$setId, $island['id']]);
		$insertGroup->execute([$island['floor_id'], $setId]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec(sprintf(
			"DELETE g FROM item_groups g JOIN variant_sets s ON s.id = g.variant_set_id JOIN floors f ON f.id = s.floor_id
			WHERE f.project_id = %d AND s.name = 'Kitchen layout' AND g.name = 'Without island'",
			self::ProjectId,
		));
		$pdo->exec(sprintf(
			"DELETE s FROM variant_sets s JOIN floors f ON f.id = s.floor_id WHERE f.project_id = %d AND s.name = 'Kitchen layout'",
			self::ProjectId,
		));
	}
}
