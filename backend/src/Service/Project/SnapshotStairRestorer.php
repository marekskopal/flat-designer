<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

use DateTimeImmutable;
use Izbik\Model\Entity\Enum\RailingStyleEnum;
use Izbik\Model\Entity\Enum\StairConstructionEnum;
use Izbik\Model\Entity\Enum\StairRailingEnum;
use Izbik\Model\Entity\Enum\StairShapeEnum;
use Izbik\Model\Entity\Enum\StairTurnEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Material;
use Izbik\Model\Entity\Stair;
use Izbik\Model\Repository\StairRepository;

/** Restores a floor's stairs from a snapshot. An enum value it does not know (a case renamed since) takes the default, as the openings' do. */
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
				shape: StairShapeEnum::tryFrom($data->string('shape')) ?? StairShapeEnum::Straight,
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
			// Snapshots taken before outdoor stairs: to the next floor, from the floor's level.
			$stair->rise = $data->nullableInt('rise');
			// Missing in snapshots taken before curved stairs.
			$stair->innerRadius = $data->nullableInt('innerRadius');
			$stair->baseZ = $data->nullableInt('baseZ') ?? 0;
			// Before railing styles and wall handrails: posts and a handrail, no wall rail, an unlined void.
			$stair->railingStyle = RailingStyleEnum::tryFrom($data->nullableString('railingStyle') ?? '');
			$stair->railingMaterial = $materials[$data->nullableInt('railingMaterialId') ?? 0] ?? null;
			$stair->wallHandrail = StairRailingEnum::tryFrom($data->nullableString('wallHandrail') ?? '') ?? StairRailingEnum::None;
			$stair->voidLiningMaterial = $materials[$data->nullableInt('voidLiningMaterialId') ?? 0] ?? null;
			$stair->createdAt = new DateTimeImmutable();
			$stair->updatedAt = $stair->createdAt;
			$this->stairRepository->persist($stair);
		}
	}
}
