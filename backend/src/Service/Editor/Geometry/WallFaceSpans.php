<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;

/**
 * Where a room edge runs along wall faces, and the openings on it. Twin of `wallSpansAlongEdge()`, `wallFacesAlongEdge()`,
 * `wallGapsAlongEdge()` and `skirtingPieces()` in the viewer (`ceilings.ts`, `plan-geometry.ts`), kept identical by
 * `tests/fixtures/wall-spans.json`. A wall lies on the edge a → b when it runs parallel to it and its centre line is half
 * a thickness (± tolerance) away. Lengths are mm along the edge from a. A niche is an opening of the face it is cut
 * into only, flagged `niche` (it stays in the plan).
 */
final class WallFaceSpans
{
	public const float Tolerance = 30;

	/**
	 * Stretches of the edge along a wall face, merged; a wall's ends reach half a thickness on (its mitre at corners).
	 *
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @param list<WallDto> $walls
	 * @return list<array{float, float}>
	 */
	public static function spans(array $a, array $b, array $walls): array
	{
		return Spans::merge(array_map(static fn (array $face): array => [$face['from'], $face['to']], self::wallSpans($a, $b, $walls)));
	}

	/**
	 * Openings of the walls on the edge, projected onto it.
	 *
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @param list<WallDto> $walls
	 * @return list<array{from: float, to: float, bottom: int, top: int, niche: bool}>
	 */
	public static function gaps(array $a, array $b, array $walls): array
	{
		$gaps = [];
		foreach (self::wallSpans($a, $b, $walls) as $face) {
			array_push($gaps, ...$face['gaps']);
		}

		return $gaps;
	}

	/**
	 * Where skirting runs along the edge: along the walls on it, except their doorways.
	 *
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @param list<WallDto> $walls
	 * @return list<array{float, float}>
	 */
	public static function skirting(array $a, array $b, array $walls): array
	{
		$doorways = array_map(
			static fn (array $gap): array => [$gap['from'], $gap['to']],
			array_values(array_filter(self::gaps($a, $b, $walls), static fn (array $gap): bool => $gap['bottom'] <= 0)),
		);

		return Spans::subtract(self::spans($a, $b, $walls), $doorways);
	}

	/**
	 * The wall faces on the edge one by one, without overlaps (where two walls' ends overlap, the first wall keeps the
	 * stretch), each with the wall's side facing the edge (the face's own finish, `WallDto::finishesOn()`) and its own
	 * openings clipped to it.
	 *
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @param list<WallDto> $walls
	 * @return list<array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>}>
	 */
	public static function faces(array $a, array $b, array $walls): array
	{
		$faces = [];
		$covered = [];
		foreach (self::wallSpans($a, $b, $walls) as $face) {
			if ($face['to'] <= $face['from']) {
				continue;
			}
			foreach (Spans::subtract([[$face['from'], $face['to']]], $covered) as [$from, $to]) {
				$gaps = [];
				foreach ($face['gaps'] as $gap) {
					$gapFrom = max($gap['from'], $from);
					$gapTo = min($gap['to'], $to);
					if ($gapTo > $gapFrom) {
						$gaps[] = ['from' => $gapFrom, 'to' => $gapTo, 'bottom' => $gap['bottom'], 'top' => $gap['top'], 'niche' => $gap['niche']];
					}
				}
				$faces[] = ['wall' => $face['wall'], 'side' => $face['side'], 'from' => $from, 'to' => $to, 'gaps' => $gaps];
			}
			$covered = Spans::merge([...$covered, [$face['from'], $face['to']]]);
		}

		return $faces;
	}

	/**
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @param list<WallDto> $walls
	 * @return list<array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>}>
	 */
	private static function wallSpans(array $a, array $b, array $walls): array
	{
		$length = hypot($b[0] - $a[0], $b[1] - $a[1]);
		if ($length <= 0.0) {
			return [];
		}
		$dir = [($b[0] - $a[0]) / $length, ($b[1] - $a[1]) / $length];
		$along = static fn (float $x, float $y): float => ($x - $a[0]) * $dir[0] + ($y - $a[1]) * $dir[1];

		$spans = [];
		foreach ($walls as $wall) {
			$wallLength = hypot($wall->endX - $wall->startX, $wall->endY - $wall->startY);
			if ($wallLength <= 0.0) {
				continue;
			}
			$wallDir = [($wall->endX - $wall->startX) / $wallLength, ($wall->endY - $wall->startY) / $wallLength];
			$parallel = abs($dir[0] * $wallDir[1] - $dir[1] * $wallDir[0]) < 1e-3;
			// Distance of a from the centre line, along the wall's normal (positive on its left).
			$normal = ($a[0] - $wall->startX) * -$wallDir[1] + ($a[1] - $wall->startY) * $wallDir[0];
			if (!$parallel || abs(abs($normal) - $wall->thickness / 2) > self::Tolerance) {
				continue;
			}

			$t0 = $along($wall->startX, $wall->startY);
			$t1 = $along($wall->endX, $wall->endY);
			$from = max(min($t0, $t1) - $wall->thickness / 2, 0.0);
			$to = min(max($t0, $t1) + $wall->thickness / 2, $length);

			$face = $normal > 0 ? OpeningSideEnum::Left : OpeningSideEnum::Right;
			$gaps = self::openingGaps($wall, $face, $wallDir, $along, $length);

			// A wall whose face misses the edge's extent still brings its openings, as in the viewer.
			$spans[] = ['wall' => $wall, 'side' => $face, 'from' => $from, 'to' => $to, 'gaps' => $gaps];
		}

		return $spans;
	}

	/**
	 * The wall's openings on its `face` projected onto the edge: all but the niches cut into the other face.
	 *
	 * @param array{float, float} $wallDir
	 * @param callable(float, float): float $along position of a plan point along the edge
	 * @return list<array{from: float, to: float, bottom: int, top: int, niche: bool}>
	 */
	private static function openingGaps(WallDto $wall, OpeningSideEnum $face, array $wallDir, callable $along, float $length): array
	{
		$gaps = [];
		foreach ($wall->openings as $opening) {
			if ($opening->type === OpeningTypeEnum::Niche && ($opening->side ?? OpeningSideEnum::Left) !== $face) {
				continue;
			}
			$g0 = $along($wall->startX + $wallDir[0] * $opening->offset, $wall->startY + $wallDir[1] * $opening->offset);
			$end = $opening->offset + $opening->width;
			$g1 = $along($wall->startX + $wallDir[0] * $end, $wall->startY + $wallDir[1] * $end);
			$gapFrom = max(min($g0, $g1), 0.0);
			$gapTo = min(max($g0, $g1), $length);
			if ($gapTo <= $gapFrom) {
				continue;
			}

			$gaps[] = [
				'from' => $gapFrom,
				'to' => $gapTo,
				'bottom' => $opening->sillHeight,
				'top' => $opening->sillHeight + $opening->height,
				'niche' => $opening->type === OpeningTypeEnum::Niche,
			];
		}

		return $gaps;
	}
}
