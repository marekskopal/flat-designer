<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/**
 * The `image` parameters of a furniture definition and the project files an item shows through them (its
 * `params.values`). Read leniently: an invalid definition simply has none.
 */
final readonly class ImageParameters
{
	/**
	 * @param array<mixed> $definition the decoded definition
	 * @return list<string>
	 */
	public static function keys(array $definition): array
	{
		$keys = [];
		foreach (is_array($definition['parameters'] ?? null) ? $definition['parameters'] : [] as $parameter) {
			if (
				is_array($parameter)
				&& ($parameter['type'] ?? null) === ParameterTypeEnum::Image->value
				&& is_string($parameter['key'] ?? null)
			) {
				$keys[] = $parameter['key'];
			}
		}

		return $keys;
	}

	/**
	 * @param array<mixed> $definition the decoded definition
	 * @param mixed $values the item's `params.values`
	 * @return list<int> the file ids, as given (not checked)
	 */
	public static function fileIds(array $definition, mixed $values): array
	{
		return self::fileIdsOf(self::keys($definition), $values);
	}

	/**
	 * @param list<string> $keys the `image` parameter keys (`keys()`)
	 * @param mixed $values the item's `params.values`
	 * @return list<int>
	 */
	public static function fileIdsOf(array $keys, mixed $values): array
	{
		$ids = [];
		foreach ($keys as $key) {
			$value = is_array($values) ? ($values[$key] ?? null) : null;
			if (is_int($value)) {
				$ids[] = $value;
			}
		}

		return $ids;
	}
}
