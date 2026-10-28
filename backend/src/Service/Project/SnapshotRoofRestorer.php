<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use FlatDesigner\Model\Entity\Enum\RoofShapeEnum;
use FlatDesigner\Model\Entity\Enum\RoofWindowTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Model\Entity\RoofWindow;
use FlatDesigner\Model\Repository\RoofRepository;
use FlatDesigner\Model\Repository\RoofWindowRepository;
use FlatDesigner\Service\Editor\Element\State;

/** Restores a floor's roofs and their windows from a snapshot. An enum value it does not know (a case renamed since) takes the default, as the openings' do. */
final readonly class SnapshotRoofRestorer
{
	public function __construct(private RoofRepository $roofRepository, private RoofWindowRepository $roofWindowRepository)
	{
	}

	/**
	 * @param SnapshotReader $entry the floor's entry; snapshots taken before roofs existed have none
	 * @param array<int, Material> $materials
	 */
	public function restore(Floor $floor, SnapshotReader $entry, array $materials): void
	{
		if ($entry->raw('roofs') === null) {
			return;
		}
		foreach ($entry->objects('roofs') as $data) {
			$roof = State::stamp(new Roof(
				floor: $floor,
				name: $data->string('name'),
				shape: RoofShapeEnum::tryFrom($data->string('shape')) ?? RoofShapeEnum::Gable,
				x: $data->int('x'),
				y: $data->int('y'),
				rotation: $data->int('rotation'),
				width: $data->int('width'),
				depth: $data->int('depth'),
				eaveHeight: $data->int('eaveHeight'),
				pitch: $data->int('pitch'),
				overhang: $data->int('overhang'),
				vergeOverhang: $data->int('vergeOverhang'),
				thickness: $data->int('thickness'),
				parapet: $data->nullableInt('parapet'),
				coveringMaterial: $materials[$data->nullableInt('coveringMaterialId') ?? 0] ?? null,
				soffitMaterial: $materials[$data->nullableInt('soffitMaterialId') ?? 0] ?? null,
			));
			$this->roofRepository->persist($roof);

			foreach ($data->objects('windows') as $window) {
				$this->roofWindowRepository->persist(State::stamp(new RoofWindow(
					roof: $roof,
					name: $window->string('name'),
					type: RoofWindowTypeEnum::tryFrom($window->string('type')) ?? RoofWindowTypeEnum::CentrePivot,
					along: $window->int('along'),
					across: $window->int('across'),
					width: $window->int('width'),
					length: $window->int('length'),
					frameMaterial: $materials[$window->nullableInt('frameMaterialId') ?? 0] ?? null,
				)));
			}
		}
	}
}
