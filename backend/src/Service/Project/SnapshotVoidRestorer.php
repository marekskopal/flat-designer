<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\HandrailProfileEnum;
use FlatDesigner\Model\Entity\Enum\RailingStyleEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FloorVoid;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\Railing;
use FlatDesigner\Model\Repository\FloorVoidRepository;
use FlatDesigner\Model\Repository\RailingRepository;
use FlatDesigner\Service\Editor\Element\RailingHandler;
use const JSON_THROW_ON_ERROR;

/** Restores a floor's voids and railings (FD-90) from a snapshot; snapshots taken before them have none. */
final readonly class SnapshotVoidRestorer
{
	public function __construct(private FloorVoidRepository $voidRepository, private RailingRepository $railingRepository)
	{
	}

	/**
	 * @param SnapshotReader $entry the floor's entry
	 * @param array<int, Material> $materials
	 */
	public function restore(Floor $floor, SnapshotReader $entry, array $materials): void
	{
		foreach ($entry->raw('voids') === null ? [] : $entry->objects('voids') as $data) {
			$void = new FloorVoid(
				floor: $floor,
				name: $data->string('name'),
				polygon: json_encode($data->raw('polygon'), JSON_THROW_ON_ERROR),
				liningMaterial: $materials[$data->nullableInt('liningMaterialId') ?? 0] ?? null,
			);
			$void->liningDepth = $data->nullableInt('liningDepth');
			$void->createdAt = new DateTimeImmutable();
			$void->updatedAt = $void->createdAt;
			$this->voidRepository->persist($void);
		}
		foreach ($entry->raw('railings') === null ? [] : $entry->objects('railings') as $data) {
			$railing = new Railing(
				floor: $floor,
				name: $data->string('name'),
				points: json_encode($data->raw('points'), JSON_THROW_ON_ERROR),
				height: $data->nullableInt('height') ?? RailingHandler::DefaultHeight,
				style: RailingStyleEnum::tryFrom($data->string('style')) ?? RailingStyleEnum::Balusters,
				postSpacing: $data->nullableInt('postSpacing') ?? RailingHandler::DefaultPostSpacing,
				handrail: HandrailProfileEnum::tryFrom($data->string('handrail')) ?? HandrailProfileEnum::Round,
				material: $materials[$data->nullableInt('materialId') ?? 0] ?? null,
				infillMaterial: $materials[$data->nullableInt('infillMaterialId') ?? 0] ?? null,
			);
			$railing->baseZ = $data->nullableInt('baseZ') ?? 0;
			$railing->spacing = $data->nullableInt('spacing');
			$railing->createdAt = new DateTimeImmutable();
			$railing->updatedAt = $railing->createdAt;
			$this->railingRepository->persist($railing);
		}
	}
}
