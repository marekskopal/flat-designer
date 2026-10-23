<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * Mitres of walls whose end points meet in pairs, as the viewer draws them (`wallJoints()` in
 * `viewer/engine/plan-geometry.ts`): how far each face reaches past the wall's end points (mm; negative
 * = cut short), a curved wall's face along its circle. Ends shared by three or more walls, straight (tangent)
 * continuations and very sharp angles stay square.
 *
 * @phpstan-import-type Point from PlanMath
 * @phpstan-type Ends array{startLeft: float, startRight: float, endLeft: float, endRight: float}
 * @phpstan-type End array{wall: WallLine, atStart: bool, x: int, y: int}
 * @phpstan-type Curve array{kind: 'line', point: Point, dir: Point}|array{kind: 'circle', centre: Point, radius: float}
 */
final readonly class WallJoints
{
	/** End points closer than this (mm) count as one corner. */
	private const float Tolerance = 1.0;

	public const array Square = ['startLeft' => 0.0, 'startRight' => 0.0, 'endLeft' => 0.0, 'endRight' => 0.0];

	/**
	 * @param list<WallLine> $walls
	 * @return array<int, Ends> by wall id; walls without a joint are left out
	 */
	public static function of(array $walls): array
	{
		$ends = [];
		foreach ($walls as $wall) {
			$ends[] = ['wall' => $wall, 'atStart' => true, 'x' => $wall->startX, 'y' => $wall->startY];
			$ends[] = ['wall' => $wall, 'atStart' => false, 'x' => $wall->endX, 'y' => $wall->endY];
		}

		$result = [];
		foreach ($ends as $end) {
			$others = array_values(array_filter(
				$ends,
				static fn (array $other): bool => $other['wall']->id !== $end['wall']->id
					&& hypot($other['x'] - $end['x'], $other['y'] - $end['y']) <= self::Tolerance,
			));
			if (count($others) !== 1 || $end['wall']->length() === 0.0 || $others[0]['wall']->length() === 0.0) {
				continue;
			}
			$other = $others[0];
			// Unit vectors from the corner into each wall.
			[$ax, $ay] = self::intoWall($end['wall'], $end['atStart']);
			[$bx, $by] = self::intoWall($other['wall'], $other['atStart']);
			$cross = $ax * $by - $ay * $bx;
			$ha = $end['wall']->thickness / 2;
			$hb = $other['wall']->thickness / 2;
			if (abs($cross) < 1e-3) {
				continue;
			}
			// How far from the corner into the wall the face on the left of `a` meets the other wall's face, and the one
			// on its right (for straight walls its mirror image).
			$reach = self::reach($end, $other, [$ax, $ay], [$bx, $by], $cross);
			$limit = 4 * max($ha, $hb);
			if ($reach === null || abs($reach[0]) > $limit || abs($reach[1]) > $limit) {
				continue;
			}
			$faces = $result[$end['wall']->id] ?? self::Square;
			if ($end['atStart']) {
				$faces['startLeft'] = -$reach[0];
				$faces['startRight'] = -$reach[1];
			} else {
				// Seen from the end, the left of `a` is the wall's right.
				$faces['endRight'] = -$reach[0];
				$faces['endLeft'] = -$reach[1];
			}
			$result[$end['wall']->id] = $faces;
		}

		return $result;
	}

	/**
	 * Where the faces left and right of `a` meet the other wall's faces at a corner: mm from the corner into the wall,
	 * along its centre line. Null when a curved face misses the other.
	 *
	 * @param End $end
	 * @param End $other
	 * @param Point $a
	 * @param Point $b
	 * @return array{float, float}|null
	 */
	private static function reach(array $end, array $other, array $a, array $b, float $cross): ?array
	{
		$wall = $end['wall'];
		$otherWall = $other['wall'];
		$ha = $wall->thickness / 2;
		$hb = $otherWall->thickness / 2;
		if ($wall->arc() === null && $otherWall->arc() === null) {
			$k = ($hb + $ha * ($a[0] * $b[0] + $a[1] * $b[1])) / $cross;

			return [$k, -$k];
		}
		// The left of `a` is the wall's left at its start, its right at its end; the face it meets is on the right of `b`.
		$sign = $end['atStart'] ? 1 : -1;
		$otherSign = $other['atStart'] ? 1 : -1;
		$corner = [(float) $end['x'], (float) $end['y']];
		$reach = static function (float $across, float $otherAcross) use ($wall, $otherWall, $end, $corner): ?float {
			$meet = self::nearestIntersection(self::curve($wall, $across), self::curve($otherWall, $otherAcross), $corner);
			if ($meet === null) {
				return null;
			}
			$along = $wall->along($meet);

			return $end['atStart'] ? $along : $wall->length() - $along;
		};
		$left = $reach($sign * $ha, -$otherSign * $hb);
		$right = $reach(-$sign * $ha, $otherSign * $hb);

		return $left === null || $right === null ? null : [$left, $right];
	}

	/** @return Curve */
	private static function curve(WallLine $wall, float $across): array
	{
		$arc = $wall->arc();
		if ($arc === null) {
			return ['kind' => 'line', 'point' => $wall->point(0.0, $across), 'dir' => $wall->tangent(0.0)];
		}

		return ['kind' => 'circle', 'centre' => [$arc['cx'], $arc['cy']], 'radius' => $arc['radius'] - ($arc['sweep'] > 0 ? 1 : -1) * $across];
	}

	/**
	 * The intersection of two face curves nearest to `near`; null when they do not meet.
	 *
	 * @param Curve $p
	 * @param Curve $q
	 * @param Point $near
	 * @return Point|null
	 */
	private static function nearestIntersection(array $p, array $q, array $near): ?array
	{
		if ($p['kind'] === 'line') {
			$points = $q['kind'] === 'line'
				? Intersections::lineLine($p['point'], $p['dir'], $q['point'], $q['dir'])
				: Intersections::lineCircle($p['point'], $p['dir'], $q['centre'], $q['radius']);
		} else {
			$points = $q['kind'] === 'line'
				? Intersections::lineCircle($q['point'], $q['dir'], $p['centre'], $p['radius'])
				: Intersections::circleCircle($p['centre'], $p['radius'], $q['centre'], $q['radius']);
		}
		$best = null;
		foreach ($points as $point) {
			if ($best === null || hypot($point[0] - $near[0], $point[1] - $near[1]) < hypot($best[0] - $near[0], $best[1] - $near[1])) {
				$best = $point;
			}
		}

		return $best;
	}

	/**
	 * Unit vector from a wall end into the wall (along the tangent of a curved wall).
	 *
	 * @return Point
	 */
	private static function intoWall(WallLine $wall, bool $atStart): array
	{
		if ($atStart) {
			return $wall->tangent(0.0);
		}
		[$dx, $dy] = $wall->tangent($wall->length());

		return [-$dx, -$dy];
	}
}
