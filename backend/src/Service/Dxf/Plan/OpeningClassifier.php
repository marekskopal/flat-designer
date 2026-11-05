<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Model\Entity\Enum\OpeningHingeEnum;
use Izbik\Model\Entity\Enum\OpeningSideEnum;
use Izbik\Model\Entity\Enum\OpeningTypeEnum;
use Izbik\Service\Dxf\DxfArc;
use Izbik\Service\Dxf\DxfSegment;
use function abs;
use function hypot;
use function max;
use function min;
use function preg_match;
use function usort;

/**
 * What an opening's drawing shows in a stretch of a wall. A door is an arc (its swing) round a point at a jamb: hinged
 * there, swinging to the side the arc lies on; two arcs from both jambs are a double door, its hinge-side leaf the
 * larger arc's radius. Lines along the wall on it are glazing: a window. Otherwise a block named like a door or a
 * window says which.
 */
final class OpeningClassifier
{
	/** Swings this wide or wider; smaller arcs are handles, symbols and the like. */
	private const float MinSwing = 250.0;

	private const float MaxSwing = 2500.0;

	/** Swings are quarter or half circles, not full ones. */
	private const float MaxSweep = 3.5;

	private const float Margin = 30.0;

	private const string DoorBlock = '/door|dver|dveř|dvere|dveře|tür|tur/i';

	private const string WindowBlock = '/window|win|okn|fenster|glaz/i';

	/** @param list<DxfSegment|DxfArc> $entities */
	public static function classify(array $entities, WallPiece $wall, float $from, float $to): ?PlannedOpening
	{
		$swings = self::swings($entities, $wall, $from, $to);
		if ($swings !== []) {
			return self::door($swings, $wall, $from, $to);
		}
		if (self::glazing($entities, $wall, $from, $to) >= 0.3 * ($to - $from)) {
			return new PlannedOpening($from, $to, OpeningTypeEnum::Window);
		}

		return self::byBlock($entities, $from, $to);
	}

	/**
	 * The door swings: arcs of a door leaf's size round a point on the wall within the stretch, the largest first.
	 *
	 * @param list<DxfSegment|DxfArc> $entities
	 * @return list<array{float, DxfArc}> where along the wall each turns, and the arc
	 */
	private static function swings(array $entities, WallPiece $wall, float $from, float $to): array
	{
		$band = new WallBand($wall, 2 * self::Margin);
		$swings = [];
		foreach ($entities as $entity) {
			if (
				!$entity instanceof DxfArc
				|| $entity->radius < self::MinSwing
				|| $entity->radius > self::MaxSwing
				|| $entity->end - $entity->start > self::MaxSweep
				|| !$band->containsPoint($entity->cx, $entity->cy)
			) {
				continue;
			}
			$along = $wall->frame->t($entity->cx, $entity->cy);
			if ($along >= $from - 2 * self::Margin && $along <= $to + 2 * self::Margin) {
				$swings[] = [$along, $entity];
			}
		}
		usort($swings, static fn (array $a, array $b): int => $b[1]->radius <=> $a[1]->radius);

		return $swings;
	}

	/** @param non-empty-list<array{float, DxfArc}> $swings */
	private static function door(array $swings, WallPiece $wall, float $from, float $to): PlannedOpening
	{
		$width = $to - $from;
		[$hingeAt, $arc] = $swings[0];
		$hinge = $hingeAt - $from < $to - $hingeAt ? OpeningHingeEnum::Start : OpeningHingeEnum::End;
		[$x, $y] = $arc->pointAt(($arc->start + $arc->end) / 2);
		$side = $wall->frame->c($x, $y) > $wall->c ? OpeningSideEnum::Left : OpeningSideEnum::Right;
		foreach ($swings as [$otherAt, $other]) {
			if (abs($otherAt - $hingeAt) > $width / 2) {
				// A second swing from the other jamb.
				$leaf = abs($arc->radius - $other->radius) > self::Margin ? min(max($arc->radius, 100.0), $width - 100) : null;

				return new PlannedOpening($from, $to, OpeningTypeEnum::DoubleDoor, $hinge, $side, $leaf);
			}
		}

		// The leaf takes the opening less its frame, as a new door's does: the swing is often drawn as wide as the opening.
		return new PlannedOpening($from, $to, OpeningTypeEnum::Door, $hinge, $side);
	}

	/**
	 * The longest stretch of lines along the wall lying on it within the stretch.
	 *
	 * @param list<DxfSegment|DxfArc> $entities
	 */
	private static function glazing(array $entities, WallPiece $wall, float $from, float $to): float
	{
		$band = new WallBand($wall, self::Margin);
		$glazing = 0.0;
		foreach ($entities as $entity) {
			if (!$entity instanceof DxfSegment) {
				continue;
			}
			$length = hypot($entity->x2 - $entity->x1, $entity->y2 - $entity->y1);
			$along = $length < 1e-9 ? new Frame(1.0, 0.0) : new Frame(
				($entity->x2 - $entity->x1) / $length,
				($entity->y2 - $entity->y1) / $length,
			);
			$clipped = $wall->frame->alignment($along) >= 0.999 ? $band->clip($entity) : null;
			if ($clipped !== null) {
				$glazing = max($glazing, min($to, $clipped[1]) - max($from, $clipped[0]));
			}
		}

		return $glazing;
	}

	/** @param list<DxfSegment|DxfArc> $entities */
	private static function byBlock(array $entities, float $from, float $to): ?PlannedOpening
	{
		$window = false;
		foreach ($entities as $entity) {
			if ($entity->block !== null && preg_match(self::DoorBlock, $entity->block) === 1) {
				return new PlannedOpening($from, $to, OpeningTypeEnum::Door, OpeningHingeEnum::Start, OpeningSideEnum::Left);
			}
			$window = $window || ($entity->block !== null && preg_match(self::WindowBlock, $entity->block) === 1);
		}

		return $window ? new PlannedOpening($from, $to, OpeningTypeEnum::Window) : null;
	}
}
