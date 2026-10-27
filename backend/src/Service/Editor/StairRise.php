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
 * standing higher, its tallest wall and the slab a floor stacked on it would get. Steps split the rise evenly; `risers`
 * null takes as few as keep each at most `MaxAutoRiser`. The static rules also serve a version's scenes
 * (`SnapshotScenes`), whose floors are not rows.
 *
 * @phpstan-type FloorLevel array{id: int, level: int, elevation: int}
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

	/**
	 * The rise of the floor's stairs, mm.
	 *
	 * @param list<Floor>|null $floors the project's floors, when the caller has them
	 * @param list<Wall>|null $walls the floor's walls, when the caller has them
	 */
	public function of(Floor $floor, ?array $floors = null, ?array $walls = null): int
	{
		$walls ??= $this->wallRepository->findByFloor($floor->id);

		return self::rise(
			$floor->level,
			$floor->elevation,
			self::levels($floors ?? $this->floorRepository->findByProject($floor->project->id)),
			FloorStack::tallestWall(array_map(fn (Wall $wall): int => $wall->height, $walls)),
		);
	}

	/**
	 * The floor whose stairs come up through this one.
	 *
	 * @param list<Floor>|null $floors the project's floors, when the caller has them
	 */
	public function floorBelow(Floor $floor, ?array $floors = null): ?Floor
	{
		$floors ??= $this->floorRepository->findByProject($floor->project->id);
		$below = self::below($floor->id, $floor->level, self::levels($floors));
		foreach ($floors as $other) {
			if ($below !== null && $other->id === $below['id']) {
				return $other;
			}
		}

		return null;
	}

	/**
	 * @param list<Floor> $floors
	 * @return list<FloorLevel>
	 */
	public static function levels(array $floors): array
	{
		return array_map(fn (Floor $f): array => ['id' => $f->id, 'level' => $f->level, 'elevation' => $f->elevation], $floors);
	}

	/**
	 * @param list<FloorLevel> $floors the project's floors (the floor itself among them or not)
	 * @param int $tallestWall mm, the floor's tallest wall, for a floor with nothing higher above it
	 */
	public static function rise(int $level, int $elevation, array $floors, int $tallestWall): int
	{
		$above = self::above($level, $floors);
		$rise = $above === null ? 0 : $above['elevation'] - $elevation;

		return $rise > 0 ? $rise : $tallestWall + FloorStack::defaultSlab($level + 1);
	}

	/**
	 * The floor a floor's stairs climb to: the lowest one of the levels above it.
	 *
	 * @param list<FloorLevel> $floors
	 * @return FloorLevel|null
	 */
	public static function above(int $level, array $floors): ?array
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

		return $above;
	}

	/**
	 * The floor whose stairs come up through a floor: the highest one under it that climbs to it (`above()`), so two floors
	 * of one level (a mezzanine) share no stairs.
	 *
	 * @param list<FloorLevel> $floors
	 * @return FloorLevel|null
	 */
	public static function below(int $id, int $level, array $floors): ?array
	{
		$below = null;
		foreach ($floors as $floor) {
			if (
				$floor['level'] >= $level
				|| (
					$below !== null
					&& [$floor['level'], $floor['elevation']] <= [$below['level'], $below['elevation']]
				)
			) {
				continue;
			}
			if ((self::above($floor['level'], $floors)['id'] ?? null) === $id) {
				$below = $floor;
			}
		}

		return $below;
	}

	/** The risers a stair has: as given, or as few as keep each at most `MaxAutoRiser` (two at least). */
	public static function risers(int $rise, ?int $risers): int
	{
		return $risers ?? max(2, (int) ceil($rise / self::MaxAutoRiser));
	}
}
