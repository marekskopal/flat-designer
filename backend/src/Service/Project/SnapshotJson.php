<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

/** Reading a decoded snapshot (JSON as arrays) that may hold anything: the arrays of a list, the array of a key. */
final readonly class SnapshotJson
{
	/** @return list<array<mixed>> the arrays in `$value`, if it is an array */
	public static function arrays(mixed $value): array
	{
		return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
	}

	/**
	 * @param array<mixed> $data
	 * @return array<mixed> `$data[$key]` if it is an array, else none
	 */
	public static function array(array $data, string $key): array
	{
		return is_array($data[$key] ?? null) ? $data[$key] : [];
	}

	/**
	 * Each floor entry of the snapshot through `$map`.
	 *
	 * @param array<mixed> $data
	 * @param callable(array<mixed>): array<mixed> $map
	 * @return array<mixed>
	 */
	public static function mapFloors(array $data, callable $map): array
	{
		$data['floors'] = array_map($map, self::arrays($data['floors'] ?? null));

		return $data;
	}

	/**
	 * Each item of a floor entry through `$map`.
	 *
	 * @param array<mixed> $entry
	 * @param callable(array<mixed>): array<mixed> $map
	 * @return array<mixed>
	 */
	public static function mapItems(array $entry, callable $map): array
	{
		$entry['items'] = array_map($map, self::arrays($entry['items'] ?? null));

		return $entry;
	}
}
