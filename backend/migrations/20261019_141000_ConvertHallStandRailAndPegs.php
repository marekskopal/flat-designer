<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use PDO;

/**
 * The hall stand of flat 50 (project 1; added in the editor, so matched by name) gets its hanging rail and coat pegs
 * from the definitions of `SeedHallStandFittingDefinitions` instead of square Box items. In place — same item ids, group
 * and material. The rail hangs from the oak soffit board: its box keeps the tube's bottom (z) and grows up to the board
 * and to the depth of the ceiling roses; without the board above it stays a Box. The pegs keep their size. The
 * project's revision is bumped, as in `ConvertFlat50FurnitureToDefinitions`.
 */
final class ConvertHallStandRailAndPegsMigration extends Migration
{
	private const int ProjectId = 1;

	private const string Rail = 'Hall stand%hanging rail';

	private const string Pegs = 'Hall stand%coat peg%';

	private const string Soffit = 'Hall stand%soffit board';

	/** The rail's box in the definition's `top` mounting: deep enough for the ceiling roses. */
	private const int RailDepth = 50;

	/** The lowest rail box the drops fit in (the tube's half and a rose above it). */
	private const int MinRailHeight = 30;

	/** The rail's Box before: a 20 mm square bar. */
	private const int BarSize = 20;

	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$rail = $this->definitionId($pdo, 'Hanging rail');
		$peg = $this->definitionId($pdo, 'Coat peg');
		if ($rail === null || $peg === null) {
			return;
		}

		$convertRail = FurnitureSeeds::prepare(
			$pdo,
			"UPDATE items i JOIN floors f ON f.id = i.floor_id
			JOIN items s ON s.floor_id = i.floor_id AND s.deleted_at IS NULL AND s.name LIKE ? AND s.z - i.z >= ?
			SET i.kind = 'Custom', i.definition_id = ?, i.params = ?, i.depth = ?, i.height = s.z - i.z
			WHERE f.project_id = ? AND i.kind = 'Box' AND i.deleted_at IS NULL AND i.name LIKE ?",
		);
		$convertRail->execute([
			self::Soffit,
			self::MinRailHeight,
			$rail,
			'{"values":{"mount":"top"}}',
			self::RailDepth,
			self::ProjectId,
			self::Rail,
		]);
		$convertPegs = FurnitureSeeds::prepare(
			$pdo,
			"UPDATE items i JOIN floors f ON f.id = i.floor_id
			SET i.kind = 'Custom', i.definition_id = ?, i.params = '{\"values\":{}}'
			WHERE f.project_id = ? AND i.kind = 'Box' AND i.deleted_at IS NULL AND i.name LIKE ?",
		);
		$convertPegs->execute([$peg, self::ProjectId, self::Pegs]);

		if ($convertRail->rowCount() + $convertPegs->rowCount() > 0) {
			$this->bumpRevision($pdo);
		}
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$rail = $this->definitionId($pdo, 'Hanging rail');
		$peg = $this->definitionId($pdo, 'Coat peg');
		$back = FurnitureSeeds::prepare(
			$pdo,
			"UPDATE items i JOIN floors f ON f.id = i.floor_id
			SET i.kind = 'Box', i.definition_id = NULL, i.params = NULL, i.depth = COALESCE(?, i.depth), i.height = COALESCE(?, i.height)
			WHERE f.project_id = ? AND i.kind = 'Custom' AND i.deleted_at IS NULL AND i.definition_id = ? AND i.name LIKE ?",
		);
		$changed = 0;
		if ($rail !== null) {
			$back->execute([self::BarSize, self::BarSize, self::ProjectId, $rail, self::Rail]);
			$changed += $back->rowCount();
		}
		if ($peg !== null) {
			$back->execute([null, null, self::ProjectId, $peg, self::Pegs]);
			$changed += $back->rowCount();
		}
		if ($changed > 0) {
			$this->bumpRevision($pdo);
		}
	}

	/** The first global definition of the name. */
	private function definitionId(PDO $pdo, string $name): ?int
	{
		$select = FurnitureSeeds::prepare(
			$pdo,
			'SELECT id FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL AND deleted_at IS NULL AND name = ?
			ORDER BY id LIMIT 1',
		);
		$select->execute([$name]);
		$id = $select->fetchColumn();

		return $id === false ? null : (int) $id;
	}

	private function bumpRevision(PDO $pdo): void
	{
		FurnitureSeeds::prepare($pdo, 'UPDATE projects SET revision = revision + 1 WHERE id = ?')->execute([self::ProjectId]);
	}
}
