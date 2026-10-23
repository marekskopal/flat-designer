<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\Plot;
use FlatDesigner\Model\Repository\PlotRepository;
use const JSON_THROW_ON_ERROR;

/** Restores a floor's plots from a snapshot. */
final readonly class SnapshotPlotRestorer
{
	public function __construct(private PlotRepository $plotRepository)
	{
	}

	/**
	 * @param SnapshotReader $entry the floor's entry; snapshots taken before plots existed have none
	 * @param array<int, Material> $materials
	 */
	public function restore(Floor $floor, SnapshotReader $entry, array $materials): void
	{
		if ($entry->raw('plots') === null) {
			return;
		}
		foreach ($entry->objects('plots') as $data) {
			$plot = new Plot(
				floor: $floor,
				name: $data->string('name'),
				polygon: json_encode($data->raw('polygon'), JSON_THROW_ON_ERROR),
				groundMaterial: $materials[$data->nullableInt('groundMaterialId') ?? 0] ?? null,
			);
			// Snapshots taken before plot levels have none.
			$plot->groundLevel = $data->nullableInt('groundLevel') ?? 0;
			$plot->createdAt = new DateTimeImmutable();
			$plot->updatedAt = $plot->createdAt;
			$this->plotRepository->persist($plot);
		}
	}
}
