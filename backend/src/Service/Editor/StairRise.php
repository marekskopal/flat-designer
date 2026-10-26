<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Project\FloorStack;

/**
 * How high a floor's stairs climb: from the floor to the next one up (the lowest level above it), or, without one
 * standing higher, its tallest wall and a slab. Steps split the rise evenly; `risers` null takes as few as keep each at
 * most `MaxAutoRiser`. The static rules also serve a version's scenes (`SnapshotScenes`), whose floors are not rows.
 */
final readonly class StairRise
{
	/** mm, the highest riser an automatic count gives. */
	public const int MaxAutoRiser = 180;

	/** mm, the range of a riser with a count given. */
	public const int MinRiser = 140;

	public const int MaxRiser = 220;

	public function __construct(private FloorRepository $floorRepository, private WallRepository $wallRepository)
	{
	}

	/** The rise of the floor's stairs, mm. */
	public function of(Floor $floor): int
	{
		return self::rise(
			$floor->level,
			$floor->elevation,
			array_map(
				fn (Floor $f): array => ['level' => $f->level, 'elevation' => $f->elevation],
				$this->floorRepository->findByProject($floor->project->id),
			),
			FloorStack::tallestWall(array_map(fn (Wall $wall): int => $wall->height, $this->wallRepository->findByFloor($floor->id))),
		);
	}

	/** The floor whose stairs come up through this one: the highest level under it (the higher one of a level). */
	public function floorBelow(Floor $floor): ?Floor
	{
		$below = null;
		foreach ($this->floorRepository->findByProject($floor->project->id) as $other) {
			if (
				$other->level < $floor->level
				&& (
					$below === null
					|| [$other->level, $other->elevation] > [$below->level, $below->elevation]
				)
			) {
				$below = $other;
			}
		}

		return $below;
	}

	/**
	 * @param list<array{level: int, elevation: int}> $floors the project's floors (the floor itself among them or not)
	 * @param int $tallestWall mm, the floor's tallest wall, for a floor with nothing higher above it
	 */
	public static function rise(int $level, int $elevation, array $floors, int $tallestWall): int
	{
		$above = null;
		foreach ($floors as $floor) {
			if (
				$floor['level'] > $level
				&& (
					$above === null
					|| [$floor['level'], $floor['elevation']] < [$above['level'], $above['elevation']]
				)
			) {
				$above = $floor;
			}
		}
		$rise = $above === null ? 0 : $above['elevation'] - $elevation;

		return $rise > 0 ? $rise : $tallestWall + FloorStack::UpperSlab;
	}

	/** The risers a stair has: as given, or as few as keep each at most `MaxAutoRiser` (two at least). */
	public static function risers(int $rise, ?int $risers): int
	{
		return $risers ?? max(2, (int) ceil($rise / self::MaxAutoRiser));
	}
}
