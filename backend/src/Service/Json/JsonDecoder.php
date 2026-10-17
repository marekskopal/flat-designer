<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Json;

use JsonException;
use RuntimeException;
use const JSON_THROW_ON_ERROR;

final class JsonDecoder
{
	/** @return array<string, mixed> */
	public static function decodeObject(string $json): array
	{
		$data = self::decode($json);
		if (!is_array($data) || array_is_list($data) && $data !== []) {
			throw new RuntimeException('Expected a JSON object.');
		}

		/** @var array<string, mixed> $object */
		$object = $data;

		return $object;
	}

	/** @return list<array{int, int}> */
	public static function decodePolygon(string $json): array
	{
		$data = self::decode($json);
		if (!is_array($data) || !array_is_list($data)) {
			throw new RuntimeException('Expected a JSON list of points.');
		}

		$points = [];
		foreach ($data as $point) {
			if (!is_array($point) || count($point) !== 2 || !is_int($point[0] ?? null) || !is_int($point[1] ?? null)) {
				throw new RuntimeException('Polygon point must be a pair of integers.');
			}
			$points[] = [$point[0], $point[1]];
		}

		return $points;
	}

	private static function decode(string $json): mixed
	{
		try {
			return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException('Invalid JSON: ' . $e->getMessage(), 0, $e);
		}
	}
}
