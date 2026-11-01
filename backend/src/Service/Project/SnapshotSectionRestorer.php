<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\SectionLine;
use FlatDesigner\Model\Repository\SectionLineRepository;

/** Restores a floor's section lines (FD-94) from a snapshot; snapshots taken before them have none. */
final readonly class SnapshotSectionRestorer
{
	public function __construct(private SectionLineRepository $lineRepository)
	{
	}

	/** @param SnapshotReader $entry the floor's entry */
	public function restore(Floor $floor, SnapshotReader $entry): void
	{
		foreach ($entry->raw('sections') === null ? [] : $entry->objects('sections') as $data) {
			$line = new SectionLine(
				floor: $floor,
				name: $data->string('name'),
				startX: $data->int('startX'),
				startY: $data->int('startY'),
				endX: $data->int('endX'),
				endY: $data->int('endY'),
			);
			$line->depth = $data->nullableInt('depth');
			$line->createdAt = new DateTimeImmutable();
			$line->updatedAt = $line->createdAt;
			$this->lineRepository->persist($line);
		}
	}
}
