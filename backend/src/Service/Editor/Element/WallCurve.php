<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\Geometry\WallLine;

/** The curves a wall may have (`Wall::$sagitta`): an arc of at most a semicircle, its inner face at least `MinInnerRadius` round. */
final readonly class WallCurve
{
	/** Smallest radius of a curved wall's inner face, mm (the TS `MinInnerRadius`). */
	public const int MinInnerRadius = 100;

	/** The requested sagitta; 0 when missing (changes logged before walls could curve). */
	public static function read(FieldReader $fields): int
	{
		return $fields->raw('sagitta') === null ? 0 : $fields->int('sagitta', -State::CoordinateLimit, State::CoordinateLimit);
	}

	/**
	 * The sagitta of a wall turned round (start and end swapped): its curve stays on the same side of the plan, unless
	 * the request sets another (undo, redo).
	 */
	public static function turnedRound(int $current, int $requested): int
	{
		return $requested === $current ? -$requested : $requested;
	}

	public static function check(WallLine $line): void
	{
		if ($line->sagitta === 0) {
			return;
		}
		if (2 * abs($line->sagitta) > $line->chord()) {
			throw EditException::invalid(sprintf(
				'A curved wall bends to a semicircle at most: its sagitta may be up to half the distance between its ends (%d mm).',
				(int) floor($line->chord() / 2),
			));
		}
		$arc = $line->arc();
		if ($arc !== null && $arc['radius'] - $line->thickness / 2 < self::MinInnerRadius) {
			throw EditException::invalid(sprintf('The inner face of a curved wall must be at least %d mm round.', self::MinInnerRadius));
		}
	}
}
