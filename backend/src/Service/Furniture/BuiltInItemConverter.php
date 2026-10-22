<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Json\JsonDecoder;
use RuntimeException;
use stdClass;
use const JSON_THROW_ON_ERROR;

/**
 * Turns an item of a removed built-in furniture kind, as scene JSON decoded to objects (an `ItemDto` of a snapshot or
 * of the change log, or a row's fields), into a Custom item of the global definition that replaced the kind, with
 * the params `BuiltInFurnitureConverter` maps; the params are those `ConvertFlat50FurnitureToDefinitionsMigration`
 * wrote, so a logged state equals the item converted in place. An item the definition refuses at its size (or whose
 * definition is missing) becomes a plain `Box` of the same size, name and material, keeping its old params.
 */
final readonly class BuiltInItemConverter
{
	private DefinitionEvaluator $evaluator;

	/**
	 * @param array<string, array{id: int, name: string, definition: array<mixed>, json: string, updatedAt: string}> $definitions
	 *     the replacing definitions by name (`json` as stored, `updatedAt` as `SceneDefinitionDto` has it)
	 */
	public function __construct(private array $definitions)
	{
		$this->evaluator = new DefinitionEvaluator();
	}

	public static function isBuiltIn(mixed $kind): bool
	{
		return is_string($kind) && array_key_exists($kind, BuiltInFurnitureConverter::Replaced);
	}

	/**
	 * Converts the item in place (`kind`, `definitionId`, `params`): a Custom item when its definition takes it at its
	 * size, else a Box. `$box` overrides that decision, so a logged state follows the live item it was converted with.
	 *
	 * @return int|null the definition the item now uses; null for an item made a Box
	 */
	public function convert(stdClass $item, ?bool $box = null): ?int
	{
		$kind = is_string($item->kind ?? null) ? $item->kind : '';
		$width = self::int($item->width ?? null);
		$depth = self::int($item->depth ?? null);
		$height = self::int($item->height ?? null);
		$params = isset($item->params) ? JsonDecoder::decodeObject(json_encode($item->params, JSON_THROW_ON_ERROR)) : [];

		$target = BuiltInFurnitureConverter::toDefinition($kind, $params, $width, $height);
		if ($target === null) {
			throw new RuntimeException(sprintf('No furniture definition replaces the item kind "%s".', $kind));
		}
		[$name, $values, $materials] = $target;
		$definition = $this->definitions[$name] ?? throw new RuntimeException(sprintf('The global definition "%s" is missing.', $name));

		if ($box ?? !$this->takes($definition['definition'], $width, $depth, $height, $values)) {
			return self::box($item);
		}

		$custom = ['values' => $values === [] ? new stdClass() : BuiltInFurnitureConverter::jsonValues($values)];
		if ($materials !== []) {
			$custom['materials'] = $materials;
		}
		$item->kind = 'Custom';
		$item->definitionId = $definition['id'];
		$item->params = json_decode(json_encode($custom, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

		return $definition['id'];
	}

	/**
	 * @param array<mixed> $definition
	 * @param array<string, mixed> $values
	 */
	private function takes(array $definition, int $width, int $depth, int $height, array $values): bool
	{
		try {
			$this->evaluator->evaluate($definition, $width, $depth, $height, $values);
		} catch (DefinitionException) {
			// Outside what the definition takes (an odd size, a value past a limit).
			return false;
		}

		return true;
	}

	/**
	 * Converts the built-in items of a floor scene (a snapshot's floor entry) and adds the definitions they now use to its
	 * `definitions`.
	 *
	 * @return bool whether the floor had built-in items
	 */
	public function convertFloor(stdClass $floor): bool
	{
		$used = [];
		$changed = false;
		foreach (is_array($floor->items ?? null) ? $floor->items : [] as $item) {
			if ($item instanceof stdClass && self::isBuiltIn($item->kind ?? null)) {
				$definitionId = $this->convert($item);
				if ($definitionId !== null) {
					$used[$definitionId] = true;
				}
				$changed = true;
			}
		}

		$this->addDefinitions($floor, array_keys($used));

		return $changed;
	}

	/** @param list<int> $used the definitions the floor's items now use */
	private function addDefinitions(stdClass $floor, array $used): void
	{
		$entries = is_array($floor->definitions ?? null) ? $floor->definitions : [];
		$listed = array_map(static fn (mixed $entry): mixed => $entry instanceof stdClass ? $entry->id ?? null : null, $entries);
		foreach ($this->definitions as $definition) {
			if (in_array($definition['id'], $used, true) && !in_array($definition['id'], $listed, true)) {
				$entry = new stdClass();
				$entry->id = $definition['id'];
				$entry->name = $definition['name'];
				// Decoded to objects, as `SceneDefinitionDto` has it, so empty ones stay `{}`.
				$entry->definition = json_decode($definition['json'], flags: JSON_THROW_ON_ERROR);
				$entry->updatedAt = $definition['updatedAt'];
				$entries[] = $entry;
			}
		}
		$floor->definitions = $entries;
	}

	/** A plain box of the same size, name and material; the built-in params stay on it, as a record of what it was. */
	private static function box(stdClass $item): null
	{
		$item->kind = 'Box';
		$item->definitionId = null;

		return null;
	}

	private static function int(mixed $value): int
	{
		return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
	}
}
