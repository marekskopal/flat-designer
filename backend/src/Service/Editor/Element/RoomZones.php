<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Element;

use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Editor\FieldReader;
use Izbik\Service\Editor\Geometry\PlanMath;
use Izbik\Service\Editor\PolygonValidator;
use const JSON_THROW_ON_ERROR;

/**
 * Zones over part of a room, for `RoomHandler`: `floorZones` `{polygon, materialId}` lay another floor material over part
 * of the floor (a tiled zone in a wooden floor); `ceilingZones` `{polygon, height, materialId}` hang a dropped ceiling
 * down to `height` mm above the room's floor (a bulkhead over a kitchen run), only under a flat ceiling. Every corner
 * of a zone lies inside the room's outline or on it.
 *
 * @phpstan-type FloorZone array{polygon: list<array{int, int}>, materialId: int}
 * @phpstan-type CeilingZone array{polygon: list<array{int, int}>, height: int, materialId: int|null}
 */
final readonly class RoomZones
{
	private const int MaxZones = 20;
	private const int MaxPoints = 64;

	/** The lowest a dropped ceiling may come, mm above the room's floor. */
	private const int MinHeight = 1000;

	/** How far a dropped ceiling's soffit stays under the ceiling at least, mm. */
	private const int MinDrop = 50;

	/** How far off the room's outline a zone's corner may lie, mm. */
	private const float Tolerance = 1.0;

	/** JSON schema of one floor zone, for MCP tools. */
	public const array FloorZoneSchema = [
		'type' => 'object',
		'properties' => [
			'polygon' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]],
			'materialId' => ['type' => 'integer'],
		],
		'required' => ['polygon', 'materialId'],
	];

	/** JSON schema of one ceiling zone, for MCP tools. */
	public const array CeilingZoneSchema = [
		'type' => 'object',
		'properties' => [
			'polygon' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]],
			'height' => ['type' => 'integer'],
			'materialId' => ['type' => ['integer', 'null']],
		],
		'required' => ['polygon', 'height'],
	];

	public function __construct(private MaterialResolver $materialResolver)
	{
	}

	/**
	 * Checked floor zones; missing (older states) is none. Their corners are checked against the room's `outline` only
	 * when `$placed` (zones set anew), so a room reshaped since keeps them.
	 *
	 * @param list<array{int, int}> $outline the room's
	 * @return list<FloorZone>
	 */
	public function floorZones(mixed $zones, array $outline, bool $placed = true): array
	{
		$normalized = [];
		foreach (self::list($zones, 'floorZones') as $zone) {
			$fields = new FieldReader($zone);
			$materialId = $fields->nullableId('materialId') ?? throw EditException::invalid('Every floor zone needs a materialId.');
			$this->materialResolver->resolve($materialId);
			$normalized[] = ['polygon' => self::polygon($fields->raw('polygon'), $placed ? $outline : null), 'materialId' => $materialId];
		}

		return $normalized;
	}

	/**
	 * Checked ceiling zones; missing (older states) is none. Set anew (`$placed`), they need a flat ceiling over them
	 * and to lie in the room's outline; a room changed since keeps them (the viewer and the quantities leave out one its
	 * ceiling no longer takes).
	 *
	 * @param list<array{int, int}> $outline the room's
	 * @return list<CeilingZone>
	 */
	public function ceilingZones(mixed $zones, array $outline, ?int $ceilingHeight, ?int $vaultSpacing, bool $placed = true): array
	{
		$list = self::list($zones, 'ceilingZones');
		if ($list === []) {
			return [];
		}
		if (!$placed) {
			$ceilingHeight = State::MaxSize + self::MinDrop;
			$vaultSpacing = null;
		}
		if ($ceilingHeight === null) {
			throw EditException::invalid('An open-air room has no ceiling to hang a ceiling zone from.');
		}
		if ($vaultSpacing !== null) {
			throw EditException::invalid('Ceiling zones need a flat ceiling, not a vaulted one.');
		}

		$normalized = [];
		foreach ($list as $zone) {
			$fields = new FieldReader($zone);
			$materialId = $fields->nullableId('materialId');
			$this->materialResolver->resolve($materialId);
			$normalized[] = [
				'polygon' => self::polygon($fields->raw('polygon'), $placed ? $outline : null),
				'height' => $fields->int('height', self::MinHeight, $ceilingHeight - self::MinDrop),
				'materialId' => $materialId,
			];
		}

		return $normalized;
	}

	/** Zones as stored: none is null. */
	public static function encode(mixed $zones): ?string
	{
		return $zones === [] || $zones === null ? null : json_encode($zones, JSON_THROW_ON_ERROR);
	}

	/** @return list<array<string, mixed>> */
	private static function list(mixed $zones, string $field): array
	{
		if ($zones === null) {
			return [];
		}
		if (!is_array($zones) || !array_is_list($zones) || count($zones) > self::MaxZones) {
			throw EditException::invalid(sprintf('Field "%s" must be a list of at most %d zones.', $field, self::MaxZones));
		}

		$list = [];
		foreach ($zones as $zone) {
			$object = [];
			foreach (is_array($zone) ? $zone : [] as $key => $value) {
				$object[(string) $key] = $value;
			}
			$list[] = $object;
		}

		return $list;
	}

	/**
	 * @param list<array{int, int}>|null $outline the room's to lie in; null: not checked
	 * @return list<array{int, int}>
	 */
	private static function polygon(mixed $polygon, ?array $outline): array
	{
		if (!is_array($polygon) || count($polygon) < 3 || count($polygon) > self::MaxPoints) {
			throw EditException::invalid(sprintf('The polygon of a zone must be a list of 3 to %d [x, y] points.', self::MaxPoints));
		}
		$points = PolygonValidator::validate($polygon, State::CoordinateLimit);
		if ($outline === null) {
			return $points;
		}
		$room = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $outline);
		foreach ($points as [$x, $y]) {
			if (!self::inside($room, [(float) $x, (float) $y])) {
				throw EditException::invalid(sprintf('The corner [%d, %d] of a zone lies outside the room.', $x, $y));
			}
		}

		return $points;
	}

	/**
	 * Inside the outline or within the tolerance of its edges.
	 *
	 * @param list<array{float, float}> $outline
	 * @param array{float, float} $point
	 */
	private static function inside(array $outline, array $point): bool
	{
		if (PlanMath::contains($outline, $point)) {
			return true;
		}
		$count = count($outline);
		foreach ($outline as $i => $a) {
			if (self::distanceToSegment($point, $a, $outline[($i + 1) % $count]) <= self::Tolerance) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{float, float} $p
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 */
	private static function distanceToSegment(array $p, array $a, array $b): float
	{
		$dx = $b[0] - $a[0];
		$dy = $b[1] - $a[1];
		$lengthSquared = $dx * $dx + $dy * $dy;
		$t = $lengthSquared === 0.0 ? 0.0 : max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $lengthSquared));

		return hypot($p[0] - $a[0] - $t * $dx, $p[1] - $a[1] - $t * $dy);
	}
}
