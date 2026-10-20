<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FloorUnderlay;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\FloorUnderlayRepository;
use FlatDesigner\Model\Repository\ProjectFileRepository;

/** Restores a floor's underlay from a snapshot, while its file is still in the project (files are not in snapshots). */
final readonly class SnapshotUnderlayRestorer
{
	public function __construct(private FloorUnderlayRepository $underlayRepository, private ProjectFileRepository $projectFileRepository)
	{
	}

	/** @param SnapshotReader $entry the floor's entry; snapshots taken before underlays existed have none */
	public function restore(Project $project, Floor $floor, SnapshotReader $entry): void
	{
		if ($entry->raw('underlay') === null) {
			return;
		}
		$data = $entry->object('underlay');
		$file = $this->projectFileRepository->findOneInProject($project->id, $data->int('fileId'));
		if ($file === null) {
			return;
		}

		$underlay = new FloorUnderlay(
			floor: $floor,
			file: $file,
			widthPx: $data->int('widthPx'),
			heightPx: $data->int('heightPx'),
			x: $data->int('x'),
			y: $data->int('y'),
			scale: $data->number('scale'),
			rotation: $data->number('rotation'),
			opacity: $data->number('opacity'),
		);
		$underlay->createdAt = new DateTimeImmutable();
		$underlay->updatedAt = $underlay->createdAt;
		$this->underlayRepository->persist($underlay);
	}
}
