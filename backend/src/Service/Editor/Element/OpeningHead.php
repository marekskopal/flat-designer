<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use FlatDesigner\Service\Editor\Geometry\WallLine;
use FlatDesigner\Service\Editor\Geometry\WallTop;
use const PHP_INT_MAX;

/**
 * An opening's head under the wall's top (FD-38): the wall's height, or under a roof the top `WallTop` gives over the
 * opening. Checked when the opening is placed anew only, as the roof windows are, so a roof lowered since does not block
 * renaming or undoing the opening (`WallHandler` checks the heads again when the wall's shape or height changes).
 */
final readonly class OpeningHead
{
	/**
	 * The wall's top under the floor's `$roofs` after a change that moves, bends, thickens, raises or lowers the wall, or
	 * makes it rise to the roof or stop at its height; null for any other change, whose heads are not checked (a roof
	 * lowered since does not block renaming or undoing the wall).
	 *
	 * @param array{startX: int, startY: int, endX: int, endY: int, sagitta: int, thickness: int, height: int, upToRoof: bool, heightEnd: int|null, ...} $next
	 * @param list<Roof> $roofs
	 */
	public static function wallTopIfReshaped(Wall $wall, array $next, WallLine $line, array $roofs): ?WallTop
	{
		$was = [$wall->startX, $wall->startY, $wall->endX, $wall->endY, $wall->sagitta, $wall->thickness, $wall->height, $wall->upToRoof, $wall->heightEnd];
		$is = [
			$next['startX'],
			$next['startY'],
			$next['endX'],
			$next['endY'],
			$next['sagitta'],
			$next['thickness'],
			$next['height'],
			$next['upToRoof'],
			$next['heightEnd'],
		];
		if ($was === $is) {
			return null;
		}

		return new WallTop(
			$line,
			$next['height'],
			$next['upToRoof'],
			array_map(fn (Roof $roof): RoofGeometry => RoofGeometry::fromRoof($roof), $roofs),
			$next['heightEnd'],
		);
	}

	/** What an opening's head may reach on a wall with `$top` (none: anything, as the heads are not checked). */
	public static function headRoom(?WallTop $top, int $offset, int $width): int
	{
		return $top === null ? PHP_INT_MAX : $top->lowestOver($offset, $offset + $width);
	}

	/** A new opening, or one whose place or size is set anew. */
	public static function placedAnew(?Opening $current, int $offset, int $width, int $sillHeight, int $height): bool
	{
		return $current === null
			|| [$offset, $width, $sillHeight, $height] !== [$current->offset, $current->width, $current->sillHeight, $current->height];
	}

	/**
	 * The opening's head, plus the `$headroom` it needs above it (a lifting garage door's tracks or drum,
	 * `OpeningClearances::headroom()`), stays under the wall's top over it: its height, or under a roof `WallTop`'s.
	 */
	public static function check(WallTop $top, int $wallHeight, int $offset, int $width, int $head, int $headroom = 0): void
	{
		$limit = $top->lowestOver($offset, $offset + $width);
		if ($head + $headroom <= $limit) {
			return;
		}
		$what = $headroom > 0
			? sprintf('Sill plus height plus the %d mm a lifting garage door needs above it', $headroom)
			: 'Sill plus height';
		if (!$top->underRoof($offset, $offset + $width)) {
			throw EditException::invalid(sprintf(
				$limit === $wallHeight ? '%s exceeds the wall height (%d mm).' : '%s exceeds the wall\'s sloping top over the opening (%d mm).',
				$what,
				$limit,
			));
		}

		throw EditException::invalid(sprintf('%s exceeds the wall\'s top over the opening (%d mm, under the roof).', $what, $limit));
	}
}
