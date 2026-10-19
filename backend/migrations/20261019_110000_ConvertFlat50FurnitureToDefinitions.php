<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\BuiltInFurnitureConverter;
use FlatDesigner\Service\Furniture\DefinitionEvaluator;
use FlatDesigner\Service\Furniture\DefinitionException;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Service\Json\JsonDecoder;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use PDO;
use const JSON_THROW_ON_ERROR;

/**
 * The custom-made furniture of flat 50 (project 1) becomes Custom items of the global definitions seeded for it
 * (`SeedFlat50FurnitureDefinitions`): kitchen cabinets, worktops, backsplashes, the wardrobe, the dining set and the
 * hall stand's boards. In place — same item ids, so groups, variants and the change log stay — with the kind's params
 * mapped to the definition's values and slot materials by `BuiltInFurnitureConverter` (the material stays the item
 * material, the definitions' first slot). An item whose values the definition refuses at its size stays built-in.
 * Deleted items stay built-in too: undo restores an item from the change log, built-in kind and all, which is why the
 * built-in kinds stay. Bought products and building boxes (sinks, hob, LED strips, sanitary ware, fences, gravel,
 * pre-wall) stay built-in, as do saved versions, which still render with the built-in kinds. The project's revision is
 * bumped, so an open editor's next edit meets a conflict and reloads (no live update is published from a migration).
 * `down()` maps back the items whose values and materials the mapping carries (values left out taken from the
 * definition's defaults); items made from the definitions since, with their own thickness, gap or materials, stay.
 */
final class ConvertFlat50FurnitureToDefinitionsMigration extends Migration
{
	private const int ProjectId = 1;

	/** The hall stand's boards are Box items named so; its pegs and rail (boxes too) and the other boxes are not boards. */
	private const array Boards = ['Hall stand%panel', 'Hall stand%side', 'Hall stand%board'];

	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$definitions = $this->definitionIds($pdo);
		if ($definitions === []) {
			return;
		}

		$kinds = [...array_keys(BuiltInFurnitureConverter::Replaced), 'Box'];
		$select = FurnitureSeeds::prepare($pdo, sprintf(
			"SELECT i.id, i.kind, i.width, i.depth, i.height, i.params FROM items i JOIN floors f ON f.id = i.floor_id
			WHERE f.project_id = ? AND i.deleted_at IS NULL AND i.kind IN (%s) AND (i.kind <> 'Box' OR %s)",
			implode(', ', array_fill(0, count($kinds), '?')),
			implode(' OR ', array_fill(0, count(self::Boards), 'i.name LIKE ?')),
		));
		$select->execute([self::ProjectId, ...$kinds, ...self::Boards]);
		$update = FurnitureSeeds::prepare($pdo, "UPDATE items SET kind = 'Custom', definition_id = ?, params = ? WHERE id = ?");
		$evaluator = new DefinitionEvaluator();

		$converted = 0;
		foreach (FurnitureSeeds::rows($select) as $row) {
			$params = is_string($row['params']) ? JsonDecoder::decodeObject($row['params']) : [];
			$target = BuiltInFurnitureConverter::toDefinition((string) $row['kind'], $params, (int) $row['width'], (int) $row['height']);
			$definitionId = $target === null ? null : $definitions[$target[0]] ?? null;
			if ($target === null || $definitionId === null) {
				continue;
			}
			[, $values, $materials] = $target;

			try {
				$evaluator->evaluate(
					$this->definitionBody($pdo, $definitionId),
					(int) $row['width'],
					(int) $row['depth'],
					(int) $row['height'],
					$values,
				);
			} catch (DefinitionException) {
				// Outside what the definition takes (an odd size, a value past a limit): the built-in kind keeps drawing it.
				continue;
			}

			$custom = ['values' => BuiltInFurnitureConverter::jsonValues($values)] + ($materials === [] ? [] : ['materials' => $materials]);
			$update->execute([$definitionId, json_encode($custom, JSON_THROW_ON_ERROR), $row['id']]);
			$converted++;
		}
		if ($converted > 0) {
			FurnitureSeeds::prepare($pdo, 'UPDATE projects SET revision = revision + 1 WHERE id = ?')->execute([self::ProjectId]);
		}
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$kinds = [];
		$bodies = [];
		foreach ($this->definitionIds($pdo) as $name => $id) {
			$kind = $name === BuiltInFurnitureConverter::Panel ? 'Box' : array_search($name, BuiltInFurnitureConverter::Replaced, true);
			if (!is_string($kind)) {
				continue;
			}

			$kinds[$id] = $kind;
			$bodies[$id] = $this->definitionBody($pdo, $id);
		}
		if ($kinds === []) {
			return;
		}

		$select = FurnitureSeeds::prepare($pdo, sprintf(
			"SELECT i.id, i.definition_id, i.params FROM items i JOIN floors f ON f.id = i.floor_id
			WHERE f.project_id = ? AND i.kind = 'Custom' AND i.deleted_at IS NULL AND i.definition_id IN (%s)",
			implode(', ', array_fill(0, count($kinds), '?')),
		));
		$select->execute([self::ProjectId, ...array_keys($kinds)]);
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE items SET kind = ?, definition_id = NULL, params = ? WHERE id = ?');
		foreach (FurnitureSeeds::rows($select) as $row) {
			$definitionId = (int) $row['definition_id'];
			$builtIn = self::builtInParams($kinds[$definitionId], $bodies[$definitionId], $row['params']);
			if ($builtIn !== null) {
				$update->execute([$kinds[$definitionId], $builtIn === [] ? null : json_encode($builtIn, JSON_THROW_ON_ERROR), $row['id']]);
			}
		}
		FurnitureSeeds::prepare($pdo, 'UPDATE projects SET revision = revision + 1 WHERE id = ?')->execute([self::ProjectId]);
	}

	/**
	 * The built-in params of a converted item, or null for an item whose values or materials the mapping does not carry
	 * (made from the definition since, with its own thickness, gap or materials).
	 *
	 * @param array<mixed> $definition
	 * @return array<string, mixed>|null
	 */
	private static function builtInParams(string $kind, array $definition, mixed $json): ?array
	{
		$params = is_string($json) ? JsonDecoder::decodeObject($json) : [];
		$values = is_array($params['values'] ?? null) ? $params['values'] : [];
		$materials = is_array($params['materials'] ?? null) ? $params['materials'] : [];
		if (!BuiltInFurnitureConverter::carriesBack($kind, $values, $materials)) {
			return null;
		}

		// Values left out are the definition's defaults, not the built-in kind's.
		return BuiltInFurnitureConverter::toParams($kind, BuiltInFurnitureConverter::withDefaults($definition, $values), $materials);
	}

	/** @return array<string, int> the global definitions the converter maps to, by name (the first of a name) */
	private function definitionIds(PDO $pdo): array
	{
		$names = [...array_values(BuiltInFurnitureConverter::Replaced), BuiltInFurnitureConverter::Panel];
		$select = FurnitureSeeds::prepare($pdo, sprintf(
			'SELECT id, name FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL AND deleted_at IS NULL
			AND name IN (%s) ORDER BY id',
			implode(', ', array_fill(0, count($names), '?')),
		));
		$select->execute($names);

		$ids = [];
		foreach (FurnitureSeeds::rows($select) as $row) {
			$ids[(string) $row['name']] ??= (int) $row['id'];
		}

		return $ids;
	}

	/** @return array<mixed> the definition JSON */
	private function definitionBody(PDO $pdo, int $id): array
	{
		$select = FurnitureSeeds::prepare($pdo, 'SELECT definition FROM furniture_definitions WHERE id = ?');
		$select->execute([$id]);
		$json = $select->fetchColumn();

		return is_string($json) ? JsonDecoder::decodeObject($json) : [];
	}
}
