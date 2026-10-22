<?php

declare(strict_types=1);

namespace Migrations;

use DateTimeImmutable;
use FlatDesigner\Service\Furniture\BuiltInFurnitureConverter;
use FlatDesigner\Service\Furniture\BuiltInItemConverter;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Service\Json\JsonDecoder;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use PDO;
use RuntimeException;
use stdClass;
use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * The last of the built-in furniture kinds (`BuiltInFurnitureConverter::Replaced`) become Custom items of their global
 * definitions, so that `RemoveBuiltInFurnitureKindsMigration` can drop the kinds: the items left in every project
 * (deleted ones too, which undo may bring back), the items of saved versions (each floor's `definitions` gaining the
 * definitions they now use, so restores and share links draw them) and the item states of the change log (equal to
 * the items converted in place, so undo matches them). `BuiltInItemConverter` does the mapping; an item its definition
 * refuses becomes a plain box, and its logged states follow it. The revision of a project whose items changed is bumped,
 * so an open editor's next edit meets a conflict and reloads. The migration stops before writing anything when a
 * replacing definition is missing (a deleted one still serves, as items may use deleted definitions). Versions and
 * changes are read one row at a time, as a snapshot is a whole project. There is no way back: `down()` leaves the data
 * as it is.
 */
final class ConvertBuiltInFurnitureMigration extends Migration
{
	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$definitions = $this->definitions($pdo);
		$missing = array_diff(array_values(BuiltInFurnitureConverter::Replaced), array_keys($definitions));
		if ($missing !== []) {
			throw new RuntimeException(sprintf('The global furniture definitions %s are missing.', implode(', ', $missing)));
		}
		$converter = new BuiltInItemConverter($definitions);

		$boxed = $this->convertItems($pdo, $converter);
		$this->convertVersions($pdo, $converter);
		$this->convertChanges($pdo, $converter, $boxed);
	}

	public function down(): void
	{
		// The built-in kinds are gone for good: nothing to map back to.
	}

	/** @return array<int, true> the items made boxes, by id */
	private function convertItems(PDO $pdo, BuiltInItemConverter $converter): array
	{
		$kinds = array_keys(BuiltInFurnitureConverter::Replaced);
		$select = FurnitureSeeds::prepare($pdo, sprintf(
			'SELECT i.id, f.project_id, i.kind, i.width, i.depth, i.height, i.params FROM items i JOIN floors f ON f.id = i.floor_id
			WHERE i.kind IN (%s)',
			implode(', ', array_fill(0, count($kinds), '?')),
		));
		$select->execute($kinds);
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE items SET kind = ?, definition_id = ?, params = ? WHERE id = ?');

		$projects = [];
		$boxed = [];
		foreach (FurnitureSeeds::rows($select) as $row) {
			$item = new stdClass();
			$item->kind = $row['kind'];
			$item->width = (int) $row['width'];
			$item->depth = (int) $row['depth'];
			$item->height = (int) $row['height'];
			$item->params = is_string($row['params']) ? json_decode($row['params'], flags: JSON_THROW_ON_ERROR) : new stdClass();
			$definitionId = $converter->convert($item);

			$update->execute([$item->kind, $definitionId, json_encode($item->params, JSON_THROW_ON_ERROR), $row['id']]);
			$projects[(int) $row['project_id']] = true;
			if ($definitionId === null) {
				$boxed[(int) $row['id']] = true;
			}
		}

		$bump = FurnitureSeeds::prepare($pdo, 'UPDATE projects SET revision = revision + 1 WHERE id = ?');
		foreach (array_keys($projects) as $projectId) {
			$bump->execute([$projectId]);
		}

		return $boxed;
	}

	private function convertVersions(PDO $pdo, BuiltInItemConverter $converter): void
	{
		$select = FurnitureSeeds::prepare($pdo, 'SELECT snapshot FROM project_versions WHERE id = ?');
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE project_versions SET snapshot = ? WHERE id = ?');

		foreach (self::ids($pdo, 'SELECT id FROM project_versions ORDER BY id') as $id) {
			$select->execute([$id]);
			$snapshot = json_decode((string) $select->fetchColumn(), flags: JSON_THROW_ON_ERROR);
			if (!$snapshot instanceof stdClass || !is_array($snapshot->floors ?? null)) {
				continue;
			}

			$changed = false;
			foreach ($snapshot->floors as $floor) {
				if ($floor instanceof stdClass && $converter->convertFloor($floor)) {
					$changed = true;
				}
			}
			if ($changed) {
				$update->execute([json_encode($snapshot, JSON_THROW_ON_ERROR), $id]);
			}
		}
	}

	/** @param array<int, true> $boxed the live items made boxes: their logged states become boxes too */
	private function convertChanges(PDO $pdo, BuiltInItemConverter $converter, array $boxed): void
	{
		$select = FurnitureSeeds::prepare($pdo, 'SELECT element_id, `before`, `after` FROM project_changes WHERE id = ?');
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE project_changes SET `before` = ?, `after` = ? WHERE id = ?');

		$ids = self::ids(
			$pdo,
			"SELECT id FROM project_changes WHERE element_type = 'Item' AND (`before` IS NOT NULL OR `after` IS NOT NULL) ORDER BY id",
		);
		foreach ($ids as $id) {
			$select->execute([$id]);
			$row = FurnitureSeeds::rows($select)[0] ?? null;
			if ($row === null) {
				continue;
			}
			$box = isset($boxed[(int) $row['element_id']]);
			$before = self::convertState($row['before'], $converter, $box);
			$after = self::convertState($row['after'], $converter, $box);
			if ($before !== null || $after !== null) {
				$update->execute([$before ?? $row['before'], $after ?? $row['after'], $id]);
			}
		}
	}

	/** @return list<int> */
	private static function ids(PDO $pdo, string $sql): array
	{
		$select = FurnitureSeeds::prepare($pdo, $sql);
		$select->execute();

		return array_map(static fn (array $row): int => (int) $row['id'], FurnitureSeeds::rows($select));
	}

	/** @return string|null the converted state; null when it has no built-in item */
	private static function convertState(int|string|null $json, BuiltInItemConverter $converter, bool $box): ?string
	{
		$state = is_string($json) ? json_decode($json, flags: JSON_THROW_ON_ERROR) : null;
		if (!$state instanceof stdClass || !BuiltInItemConverter::isBuiltIn($state->kind ?? null)) {
			return null;
		}
		$converter->convert($state, $box ? true : null);

		return json_encode($state, JSON_THROW_ON_ERROR);
	}

	/**
	 * The global definitions replacing the built-in kinds, by name: the first live one of a name, else a deleted one.
	 *
	 * @return array<string, array{id: int, name: string, definition: array<mixed>, json: string, updatedAt: string}>
	 */
	private function definitions(PDO $pdo): array
	{
		$names = array_values(BuiltInFurnitureConverter::Replaced);
		$select = FurnitureSeeds::prepare($pdo, sprintf(
			'SELECT id, name, definition, updated_at FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL
			AND name IN (%s) ORDER BY deleted_at IS NOT NULL, id',
			implode(', ', array_fill(0, count($names), '?')),
		));
		$select->execute($names);

		$definitions = [];
		foreach (FurnitureSeeds::rows($select) as $row) {
			$name = (string) $row['name'];
			$definitions[$name] ??= [
				'id' => (int) $row['id'],
				'name' => $name,
				'definition' => JsonDecoder::decodeObject((string) $row['definition']),
				'json' => (string) $row['definition'],
				'updatedAt' => new DateTimeImmutable((string) $row['updated_at'])->format(DATE_ATOM),
			];
		}

		return $definitions;
	}
}
