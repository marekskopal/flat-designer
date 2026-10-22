<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use stdClass;

/**
 * Maps items of the removed built-in furniture kinds to the global furniture definitions seeded for them
 * (`SeedFlat50FurnitureDefinitions`) and back: the definition's name, the parameter values and the slot materials for
 * the item's kind and params. The values say what the built-in kind did by default (no plinth, no handle, the
 * right-hand handle side, the built-in clamps of a chair, …), so the item looks the same; `parity-reference.json` in
 * the viewer holds the values and what the built-in kinds drew. Mapping back gives params that draw the same, keys
 * written out where the built-in kind would have defaulted. Kept for the conversion migrations only.
 */
final readonly class BuiltInFurnitureConverter
{
	/** The removed built-in furniture kinds and the definitions that replaced them. */
	public const array Replaced = [
		'BaseCabinet' => 'Kitchen base cabinet',
		'WallCabinet' => 'Kitchen wall cabinet',
		'TallCabinet' => 'Kitchen tall cabinet',
		'Worktop' => 'Worktop',
		'Backsplash' => 'Backsplash',
		'Wardrobe' => 'Sliding wardrobe',
		'Chair' => 'Dining chair',
		'Table' => 'Dining table',
	];

	/** A plain board (a Box item that is one): the definition the hall stand's panels convert to. */
	public const string Panel = 'Panel';

	/** The values each kind's mapping writes; a Custom item with others carries definition-only state. */
	private const array ValueKeys = [
		'BaseCabinet' => ['plinth', 'fronts', 'handle', 'handleSide', 'blindLeft', 'blindRight'],
		'WallCabinet' => ['fronts', 'handle', 'handleSide', 'blindLeft', 'blindRight'],
		'TallCabinet' => ['plinth', 'fronts', 'handle', 'handleSide', 'blindLeft', 'blindRight'],
		'Wardrobe' => ['doors', 'plinth'],
		'Chair' => ['seatHeight', 'backWidth'],
	];

	/** The slot materials each kind's mapping carries. */
	private const array MaterialKeys = ['Wardrobe' => ['profile']];

	/**
	 * The definition's name, parameter values and slot materials for a built-in item (`kind` as stored; a Box is a
	 * `Panel`), or null for a kind without a definition.
	 *
	 * @param array<mixed> $params the built-in kind's params
	 * @return array{string, array<string, mixed>, array<string, int>}|null
	 */
	public static function toDefinition(string $kind, array $params, int $width, int $height): ?array
	{
		$name = $kind === 'Box' ? self::Panel : self::Replaced[$kind] ?? null;
		if ($name === null) {
			return null;
		}

		$materials = [];
		if ($kind === 'Wardrobe' && is_int($params['profileMaterialId'] ?? null)) {
			$materials['profile'] = $params['profileMaterialId'];
		}

		return [
			$name,
			match ($kind) {
				'BaseCabinet', 'TallCabinet' => ['plinth' => self::int($params['plinth'] ?? null, 0)] + BuiltInFurnitureMappings::cabinet(
					$params,
				),
				'WallCabinet' => BuiltInFurnitureMappings::cabinet($params),
				'Wardrobe' => BuiltInFurnitureMappings::wardrobe($params, $width),
				'Chair' => BuiltInFurnitureMappings::chair($params, $width, $height),
				default => [],
			},
			$materials,
		];
	}

	/**
	 * The built-in params for a definition's parameter values and slot materials (the way back).
	 *
	 * @param array<mixed> $values
	 * @param array<mixed> $materials
	 * @return array<string, mixed>
	 */
	public static function toParams(string $kind, array $values, array $materials = []): array
	{
		// Kinds without parameters (worktop, backsplash, table, panel) have no params either.
		return match ($kind) {
			'BaseCabinet', 'WallCabinet', 'TallCabinet' => array_filter([
				'plinth' => $kind === 'WallCabinet' ? null : $values['plinth'] ?? null,
				'handle' => $values['handle'] ?? null,
				'handleSide' => $values['handleSide'] ?? null,
				'blindLeft' => ($values['blindLeft'] ?? 0) === 0 ? null : $values['blindLeft'],
				'blindRight' => ($values['blindRight'] ?? 0) === 0 ? null : $values['blindRight'],
				'fronts' => $values['fronts'] ?? null,
			], static fn (mixed $value): bool => $value !== null),
			'Wardrobe' => BuiltInFurnitureMappings::wardrobeParams($values, $materials),
			'Chair' => array_filter(
				['seatHeight' => $values['seatHeight'] ?? null, 'backWidth' => $values['backWidth'] ?? null],
				static fn (mixed $value): bool => $value !== null,
			),
			default => [],
		};
	}

	/**
	 * Whether a Custom item's values and materials are nothing but what the mapping carries, so mapping it back loses
	 * nothing (an item made from the definition with its own board thickness, gap or fronts material is left alone).
	 *
	 * @param array<mixed> $values
	 * @param array<mixed> $materials
	 */
	public static function carriesBack(string $kind, array $values, array $materials): bool
	{
		$valueKeys = self::ValueKeys[$kind] ?? [];
		$materialKeys = self::MaterialKeys[$kind] ?? [];
		foreach (array_keys($values) as $key) {
			if (!in_array($key, $valueKeys, true)) {
				return false;
			}
		}
		foreach (array_keys($materials) as $key) {
			if (!in_array($key, $materialKeys, true)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The item's values completed with the definition's parameter defaults, as the definition draws the item: what
	 * `toParams()` needs, since an item may leave values out.
	 *
	 * @param array<mixed> $definition
	 * @param array<mixed> $values
	 * @return array<string, mixed>
	 */
	public static function withDefaults(array $definition, array $values): array
	{
		$complete = [];
		foreach ($values as $key => $value) {
			$complete[(string) $key] = $value;
		}
		foreach (is_array($definition['parameters'] ?? null) ? $definition['parameters'] : [] as $parameter) {
			if (is_array($parameter) && is_string($parameter['key'] ?? null) && !array_key_exists($parameter['key'], $complete)) {
				$complete[$parameter['key']] = $parameter['default'] ?? null;
			}
		}

		return $complete;
	}

	/**
	 * Values as stored in an item's params: empty items of lists as objects, so `{}` stays `{}` in the JSON.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function jsonValues(array $values): array
	{
		foreach ($values as $key => $value) {
			if (is_array($value) && array_is_list($value)) {
				$values[$key] = array_map(static fn (mixed $item): mixed => $item === [] ? new stdClass() : $item, $value);
			}
		}

		return $values;
	}

	public static function int(mixed $value, int $default): int
	{
		return is_int($value) ? $value : $default;
	}
}
