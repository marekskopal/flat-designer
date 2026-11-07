<?php

declare(strict_types=1);

namespace Izbik\Service\Quantities;

use Izbik\Dto\RoomDto;
use Izbik\Dto\WallDto;
use Izbik\Dto\WallFinishDto;
use Izbik\Model\Entity\Enum\OpeningSideEnum;
use Izbik\Model\Entity\Enum\QuantitySurfaceEnum;
use Izbik\Service\Editor\Geometry\FaceBands;
use Izbik\Service\Editor\Geometry\Spans;
use Izbik\Service\Editor\Geometry\WallLine;

/**
 * A wall face on a room edge for `RoomSurfaces`: in the stretches its own bands line (`FaceBands`), and on a level top
 * its bands and paint.
 */
final readonly class RoomFace
{
	/**
	 * The face in stretches by its own bands (`FaceBands`: a band may line a stretch of the wall), each with the bands
	 * lining it, the room's where the face has none of its own; the openings cut to each stretch.
	 *
	 * @param array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>} $face
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @return list<array{array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>}, list<WallFinishDto>}>
	 */
	public static function stretches(RoomDto $room, array $face, array $a, array $b): array
	{
		$own = $face['wall']->finishesOn($face['side']);
		$ranged = array_filter($own ?? [], static fn (WallFinishDto $band): bool => $band->isRanged());
		$length = sqrt(($b[0] - $a[0]) ** 2 + ($b[1] - $a[1]) ** 2);
		if ($ranged === [] || $length <= 0) {
			return [[$face, $own ?? $room->wallFinishes]];
		}
		// Along the edge to along the wall's centre line and back (linear: an edge lies along a straight face, or one
		// piece of a curved one).
		$line = WallLine::fromDto($face['wall']);
		$along = static fn (float $t): float => $line->along(
			[$a[0] + ($b[0] - $a[0]) * $t / $length, $a[1] + ($b[1] - $a[1]) * $t / $length],
		);
		[$start, $end] = [$along($face['from']), $along($face['to'])];
		if (abs($end - $start) < 1e-6) {
			return [[$face, $own ?? $room->wallFinishes]];
		}
		$onEdge = static fn (float $w): float => $face['from'] + ($w - $start) / ($end - $start) * ($face['to'] - $face['from']);

		$stretches = [];
		foreach (FaceBands::stretches($own, min($start, $end), max($start, $end)) as $stretch) {
			[$from, $to] = [
				min($onEdge($stretch['from']), $onEdge($stretch['to'])), max($onEdge($stretch['from']), $onEdge($stretch['to']))];
			$gaps = [];
			foreach ($face['gaps'] as $gap) {
				[$gapFrom, $gapTo] = [max($gap['from'], $from), min($gap['to'], $to)];
				if ($gapTo > $gapFrom) {
					$gaps[] = ['from' => $gapFrom, 'to' => $gapTo, 'bottom' => $gap['bottom'], 'top' => $gap['top'], 'niche' => $gap['niche']];
				}
			}
			$stretches[] = [['wall' => $face['wall'], 'side' => $face['side'], 'from' => $from, 'to' => $to, 'gaps' => $gaps], $stretch['bands'] ?? $room->wallFinishes];
		}

		return $stretches;
	}

	/**
	 * A face's finish bands and the paint above and between them, without its openings.
	 *
	 * @param array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>} $face
	 * @param list<WallFinishDto> $finishes
	 * @return list<array{QuantitySurfaceEnum, int|null, float}> mm²
	 */
	public static function amounts(array $face, array $finishes, ?float $wallTop): array
	{
		$top = $wallTop === null ? (float) $face['wall']->height : min((float) $face['wall']->height, $wallTop);
		$amounts = [];
		$bands = [];
		foreach ($finishes as $band) {
			$bottom = max((float) $band->bottom, 0.0);
			$bandTop = min((float) $band->top, $top);
			if ($bandTop <= $bottom) {
				continue;
			}

			$amounts[] = [QuantitySurfaceEnum::WallFinish, $band->materialId, self::faceArea($face, $bottom, $bandTop)];
			$bands[] = [$bottom, $bandTop];
		}
		// Without a ceiling, no paint.
		foreach ($wallTop === null ? [] : Spans::uncovered($top, $bands) as [$bottom, $paintTop]) {
			$amounts[] = [QuantitySurfaceEnum::Wall, $face['wall']->materialId, self::faceArea($face, $bottom, $paintTop)];
		}

		return $amounts;
	}

	/**
	 * Area of the face between two heights, without its openings.
	 *
	 * @param array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>} $face
	 */
	private static function faceArea(array $face, float $bottom, float $top): float
	{
		$area = ($face['to'] - $face['from']) * ($top - $bottom);
		foreach ($face['gaps'] as $gap) {
			$height = min((float) $gap['top'], $top) - max((float) $gap['bottom'], $bottom);
			if ($height > 0) {
				$area -= ($gap['to'] - $gap['from']) * $height;
			}
		}

		return max($area, 0.0);
	}
}
