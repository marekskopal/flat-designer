<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Model\Entity\Enum\OpeningTypeEnum;
use Izbik\Service\Dxf\DxfArc;
use Izbik\Service\Dxf\DxfSegment;
use function max;
use function min;
use function usort;

/**
 * Puts the doors and windows of the opening layers into the walls (mm). Each gap between pieces of a wall is an
 * opening already, its kind read from the groups of opening entities drawn in it (`OpeningClassifier`; nothing drawn:
 * a passage). A group left lying across an unbroken wall is an opening too when it shows a door's swing, glazing or a
 * door or window block, over the stretch it draws on the wall (`WallBand`), on the wall it covers most of.
 */
final readonly class OpeningFinder
{
	/** How far outside a wall's face an opening's drawing still counts as lying on it. */
	private const float Margin = 30.0;

	/** The narrowest opening taken from a drawing over an unbroken wall. */
	private const float MinWidth = 300.0;

	public function __construct(private EntityGroups $groups)
	{
	}

	/**
	 * @param list<WallPiece> $walls
	 * @param list<array{WallPiece, float, float}> $gaps
	 */
	public function place(array $walls, array $gaps): void
	{
		$used = [];
		foreach ($gaps as [$wall, $from, $to]) {
			$roots = $this->groupsIn($wall, $from, $to);
			$used += $roots;
			$wall->openings[] = OpeningClassifier::classify($this->groups->members($roots), $wall, $from, $to)
				?? new PlannedOpening($from, $to, OpeningTypeEnum::Passage);
		}
		foreach ($this->groups->groups($used) as $group) {
			$this->placeOverWall($group, $walls);
		}
		foreach ($walls as $wall) {
			usort($wall->openings, static fn (PlannedOpening $a, PlannedOpening $b): int => $a->from <=> $b->from);
		}
	}

	/** @return array<int, true> the roots of the groups with an entity whose box lies in the gap */
	private function groupsIn(WallPiece $wall, float $from, float $to): array
	{
		$half = $wall->thickness / 2 + self::Margin;
		$corners = [
			$wall->frame->point($from, $wall->c - $half),
			$wall->frame->point($from, $wall->c + $half),
			$wall->frame->point($to, $wall->c - $half),
			$wall->frame->point($to, $wall->c + $half),
		];
		$gap = [
			min($corners[0][0], $corners[1][0], $corners[2][0], $corners[3][0]),
			min($corners[0][1], $corners[1][1], $corners[2][1], $corners[3][1]),
			max($corners[0][0], $corners[1][0], $corners[2][0], $corners[3][0]),
			max($corners[0][1], $corners[1][1], $corners[2][1], $corners[3][1]),
		];
		$roots = [];
		foreach ($this->groups->boxes as $index => $box) {
			$along = $wall->frame->t(($box[0] + $box[2]) / 2, ($box[1] + $box[3]) / 2);
			$overlaps = $box[2] >= $gap[0] && $box[0] <= $gap[2] && $box[3] >= $gap[1] && $box[1] <= $gap[3];
			if ($overlaps && $along >= $from - $half && $along <= $to + $half) {
				$roots[$this->groups->root($index)] = true;
			}
		}

		return $roots;
	}

	/**
	 * @param list<DxfSegment|DxfArc> $group
	 * @param list<WallPiece> $walls
	 */
	private function placeOverWall(array $group, array $walls): void
	{
		$best = null;
		foreach ($walls as $wall) {
			$extent = new WallBand($wall, self::Margin / 2)->extent($group);
			if (
				$extent === null
				|| $extent[1] - $extent[0] < self::MinWidth
				|| (
					$best !== null
					&& $extent[1] - $extent[0] <= $best[1]->to - $best[1]->from
				)
			) {
				continue;
			}
			$opening = OpeningClassifier::classify($group, $wall, $extent[0], $extent[1]);
			if ($opening !== null) {
				$best = [$wall, $opening];
			}
		}
		if ($best === null) {
			return;
		}
		[$wall, $opening] = $best;
		foreach ($wall->openings as $other) {
			if ($opening->from < $other->to && $opening->to > $other->from) {
				return;
			}
		}
		$wall->openings[] = $opening;
	}
}
