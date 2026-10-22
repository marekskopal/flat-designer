<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use FlatDesigner\Model\Entity\Floor;

/**
 * Finds the restored floor that stands for a floor of the working copy, for what a version restore carries over
 * (saved views, review comments): the same level and name, else the first of the same level.
 */
final class FloorMatcher
{
	/** @param list<Floor> $floors */
	public static function like(array $floors, int $level, string $name): ?Floor
	{
		$sameLevel = null;
		foreach ($floors as $floor) {
			if ($floor->level === $level && $floor->name === $name) {
				return $floor;
			}
			$sameLevel ??= $floor->level === $level ? $floor : null;
		}

		return $sameLevel;
	}
}
