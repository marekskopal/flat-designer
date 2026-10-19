<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * Mitres of walls whose end points meet in pairs, as the viewer draws them (`wallJoints()` in
 * `viewer/engine/plan-geometry.ts`): how far each face reaches past the wall's end points (mm; negative
 * = cut short). Ends shared by three or more walls, straight continuations and very sharp angles stay square.
 *
 * @phpstan-type Ends array{startLeft: float, startRight: float, endLeft: float, endRight: float}
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
			// Distance from the corner along `a` where the face on the left of `a` meets the other wall's face
			// (the right face is its mirror image).
			$k = ($hb + $ha * ($ax * $bx + $ay * $by)) / $cross;
			if (abs($k) > 4 * max($ha, $hb)) {
				continue;
			}
			$faces = $result[$end['wall']->id] ?? self::Square;
			if ($end['atStart']) {
				$faces['startLeft'] = -$k;
				$faces['startRight'] = $k;
			} else {
				// Seen from the end, the left of `a` is the wall's right.
				$faces['endRight'] = -$k;
				$faces['endLeft'] = $k;
			}
			$result[$end['wall']->id] = $faces;
		}

		return $result;
	}

	/** @return array{float, float} */
	private static function intoWall(WallLine $wall, bool $atStart): array
	{
		$length = $wall->length();
		$dx = ($wall->endX - $wall->startX) / $length;
		$dy = ($wall->endY - $wall->startY) / $length;

		return $atStart ? [$dx, $dy] : [-$dx, -$dy];
	}
}
