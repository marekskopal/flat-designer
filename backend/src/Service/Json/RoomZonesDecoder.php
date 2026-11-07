<?php

declare(strict_types=1);

namespace Izbik\Service\Json;

use RuntimeException;

/** A room's floor and ceiling zones (`RoomZones`) as stored, JSON lists of `{polygon, materialId}` / `{polygon, height, materialId}`. */
final readonly class RoomZonesDecoder
{
	/** @return list<array{polygon: list<array{int, int}>, materialId: int}> */
	public static function floorZones(string $json): array
	{
		return self::floorZonesOf(JsonDecoder::decode($json));
	}

	/**
	 * Floor zones already decoded (a snapshot's).
	 *
	 * @return list<array{polygon: list<array{int, int}>, materialId: int}>
	 */
	public static function floorZonesOf(mixed $data): array
	{
		$zones = [];
		foreach (self::zones($data) as $zone) {
			if (!is_int($zone['materialId'] ?? null)) {
				throw new RuntimeException('Floor zone needs an integer materialId.');
			}
			$zones[] = ['polygon' => JsonDecoder::points($zone['polygon'] ?? null), 'materialId' => $zone['materialId']];
		}

		return $zones;
	}

	/** @return list<array{polygon: list<array{int, int}>, height: int, materialId: int|null}> */
	public static function ceilingZones(string $json): array
	{
		return self::ceilingZonesOf(JsonDecoder::decode($json));
	}

	/**
	 * Ceiling zones already decoded (a snapshot's).
	 *
	 * @return list<array{polygon: list<array{int, int}>, height: int, materialId: int|null}>
	 */
	public static function ceilingZonesOf(mixed $data): array
	{
		$zones = [];
		foreach (self::zones($data) as $zone) {
			$materialId = $zone['materialId'] ?? null;
			if (!is_int($zone['height'] ?? null) || $materialId !== null && !is_int($materialId)) {
				throw new RuntimeException('Ceiling zone needs an integer height and materialId or null.');
			}
			$zones[] = [
				'polygon' => JsonDecoder::points($zone['polygon'] ?? null),
				'height' => $zone['height'],
				'materialId' => $materialId];
		}

		return $zones;
	}

	/** @return list<array<mixed>> */
	private static function zones(mixed $data): array
	{
		if (!is_array($data) || !array_is_list($data)) {
			throw new RuntimeException('Expected a JSON list of zones.');
		}

		return array_map(static fn (mixed $zone): array => is_array($zone) ? $zone : [], $data);
	}
}
