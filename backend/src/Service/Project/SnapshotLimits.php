<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

use Izbik\Service\Project\Exception\InvalidSnapshotException;

/** How much a snapshot from a file may hold, so an import stays a bounded amount of work. */
final readonly class SnapshotLimits
{
	public const int MaxFloors = 50;

	public const int MaxElementsPerFloor = 20000;

	public const int MaxViews = 1000;

	/** A project file's own furniture definitions (`ProjectBundle`). */
	public const int MaxDefinitions = 1000;

	/** A project file's files and models together. */
	public const int MaxAssets = 2000;

	private const array ElementLists = [
		'rooms',
		'walls',
		'items',
		'groups',
		'variantSets',
		'definitions',
		'plots',
		'stairs',
		'roofs',
		'voids',
		'railings',
		'sections',
	];

	/** @param array<mixed> $data the decoded snapshot */
	public static function check(array $data): void
	{
		$floors = SnapshotJson::arrays($data['floors'] ?? null);
		if (count($floors) > self::MaxFloors) {
			throw new InvalidSnapshotException(sprintf('A project may have at most %d floors.', self::MaxFloors));
		}
		foreach ($floors as $entry) {
			if (self::elements($entry) > self::MaxElementsPerFloor) {
				throw new InvalidSnapshotException(sprintf('A floor may have at most %d elements.', self::MaxElementsPerFloor));
			}
		}
		if (is_array($data['views'] ?? null) && count($data['views']) > self::MaxViews) {
			throw new InvalidSnapshotException(sprintf('A project may have at most %d saved views.', self::MaxViews));
		}
	}

	/** @param array<mixed> $entry a floor's */
	private static function elements(array $entry): int
	{
		$elements = 0;
		foreach (self::ElementLists as $list) {
			$elements += is_array($entry[$list] ?? null) ? count($entry[$list]) : 0;
		}
		foreach (SnapshotJson::arrays($entry['walls'] ?? null) as $wall) {
			$elements += is_array($wall['openings'] ?? null) ? count($wall['openings']) : 0;
		}

		return $elements;
	}
}
