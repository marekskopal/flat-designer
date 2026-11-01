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

	/** @return list<array{int, int, int}> */
	public static function decodeSpotHeights(string $json): array
	{
		$data = self::decode($json);
		if (!is_array($data) || !array_is_list($data)) {
			throw new RuntimeException('Expected a JSON list of spot heights.');
		}

		$points = [];
		foreach ($data as $point) {
			if (
				!is_array($point)
				|| count($point) !== 3
				|| !is_int($point[0] ?? null)
				|| !is_int($point[1] ?? null)
				|| !is_int($point[2] ?? null)
			) {
				throw new RuntimeException('Spot height must be three integers.');
			}
			$points[] = [$point[0], $point[1], $point[2]];
		}

		return $points;
	}

	/** @return list<array{materialId: int, bottom: int, top: int}> */
	public static function decodeWallFinishes(string $json): array
	{
		$data = self::decode($json);
		if (!is_array($data) || !array_is_list($data)) {
			throw new RuntimeException('Expected a JSON list of wall finishes.');
		}

		$bands = [];
		foreach ($data as $band) {
			if (!is_array($band)) {
				throw new RuntimeException('Wall finish must be an object.');
			}
			$materialId = $band['materialId'] ?? null;
			$bottom = $band['bottom'] ?? null;
			$top = $band['top'] ?? null;
			if (!is_int($materialId) || !is_int($bottom) || !is_int($top) || $top <= $bottom) {
				throw new RuntimeException('Wall finish needs integer materialId, bottom and top (top > bottom).');
			}
			$bands[] = ['materialId' => $materialId, 'bottom' => $bottom, 'top' => $top];
		}

		return $bands;
	}

	/** @return list<array{edge: string, along: int}> */
	public static function decodeDownpipes(string $json): array
	{
		$data = self::decode($json);
		if (!is_array($data) || !array_is_list($data)) {
			throw new RuntimeException('Expected a JSON list of downpipes.');
		}

		$downpipes = [];
		foreach ($data as $downpipe) {
			if (!is_array($downpipe) || !is_string($downpipe['edge'] ?? null) || !is_int($downpipe['along'] ?? null)) {
				throw new RuntimeException('Downpipe needs an edge and an integer along.');
			}
			$downpipes[] = ['edge' => $downpipe['edge'], 'along' => $downpipe['along']];
		}

		return $downpipes;
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
