<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\StairConstructionEnum;
use FlatDesigner\Model\Entity\Enum\StairRailingEnum;
use FlatDesigner\Model\Entity\Enum\StairShapeEnum;
use FlatDesigner\Model\Entity\Enum\StairTurnEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\Stair;
use FlatDesigner\Model\Repository\StairRepository;
use FlatDesigner\Service\Project\Exception\InvalidSnapshotException;

/** Restores a floor's stairs from a snapshot. */
final readonly class SnapshotStairRestorer
{
	public function __construct(private StairRepository $stairRepository)
	{
	}

	/**
	 * @param SnapshotReader $entry the floor's entry; snapshots taken before stairs existed have none
	 * @param array<int, Material> $materials
	 */
	public function restore(Floor $floor, SnapshotReader $entry, array $materials): void
	{
		if ($entry->raw('stairs') === null) {
			return;
		}
		foreach ($entry->objects('stairs') as $data) {
			$stair = new Stair(
				floor: $floor,
				name: $data->string('name'),
				shape: StairShapeEnum::tryFrom($data->string('shape')) ?? throw new InvalidSnapshotException('Unknown stair shape.'),
				x: $data->int('x'),
				y: $data->int('y'),
				rotation: $data->int('rotation'),
				width: $data->int('width'),
				going: $data->int('going'),
				risers: $data->nullableInt('risers'),
				firstFlight: $data->nullableInt('firstFlight'),
				turn: StairTurnEnum::tryFrom($data->string('turn')) ?? StairTurnEnum::Left,
				gap: $data->int('gap'),
				construction: StairConstructionEnum::tryFrom($data->string('construction')) ?? StairConstructionEnum::Solid,
				railing: StairRailingEnum::tryFrom($data->string('railing')) ?? StairRailingEnum::Right,
				treadMaterial: $materials[$data->nullableInt('treadMaterialId') ?? 0] ?? null,
				structureMaterial: $materials[$data->nullableInt('structureMaterialId') ?? 0] ?? null,
			);
			$stair->createdAt = new DateTimeImmutable();
			$stair->updatedAt = $stair->createdAt;
			$this->stairRepository->persist($stair);
		}
	}
}
