<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Dto\FloorDto;
use FlatDesigner\Dto\LocationDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\DoorFrameStyleEnum;
use FlatDesigner\Model\Entity\Enum\DoorHandleEnum;
use FlatDesigner\Model\Entity\Enum\DoorLeafStyleEnum;
use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;
use FlatDesigner\Model\Entity\Enum\ShutterStyleEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Entity\VariantSet;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\VariantSetRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Comment\CommentKeeper;
use FlatDesigner\Service\Editor\Element\ItemModelLink;
use FlatDesigner\Service\Project\Exception\InvalidSnapshotException;
use FlatDesigner\Service\Provider\SceneProvider;
use JsonException;
use stdClass;
use const JSON_THROW_ON_ERROR;

/**
 * Serialises a project's working copy (all floors with their rooms, walls, openings and items) into the
 * JSON stored in `ProjectVersion::$snapshot`, serves floor scenes straight from it and restores it.
 *
 * Format 1: `{format, project: {name, address, description}, materials: [MaterialDto], location: LocationDto, floors: [{floor:
 * FloorDto, rooms: [RoomDto], walls: [WallDto with openings], items: [ItemDto], groups: [GroupDto], variantSets:
 * [VariantSetDto], definitions: [SceneDefinitionDto], underlay: UnderlayDto|null, models: [SceneModelDto], pictures: [int],
 * plots: [PlotDto]}],
 * views: [{floorId, name, settings: SavedViewSettingsDto}]}` (the saved views, see `SnapshotViewRestorer`)
 * (a floor's `elevation` / `slabThickness`, missing in snapshots taken before stacked floors, are stacked from the levels
 * and walls by `FloorStack`)
 * (`groups` / `variantSets` / `definitions` / `underlay` / `models` / `pictures` / `plots` missing in snapshots taken before they
 * existed; `pictures` lists the files the items show, so that share guests may load them; `location`, missing in older
 * snapshots, is shown with the version, and a restore leaves the project's own) — each floor entry plus the materials and location is
 * exactly the viewer's `SceneDto`, so a version is rendered without touching the working copy, with the furniture
 * definitions as they were. Ids inside are those of the working copy at the time and only link entries together,
 * except definition ids: a restore links an item to the live definition of that id while the project may still use
 * it, otherwise to a project copy of the stored one; the underlay's file id, kept while the file exists; and model ids,
 * kept while the model exists (its file is not in the snapshot).
 */
final readonly class ProjectSnapshotService
{
	private const int Format = 1;

	public function __construct(
		private SceneProvider $sceneProvider,
		private FloorRepository $floorRepository,
		private MaterialRepository $materialRepository,
		private RoomRepository $roomRepository,
		private WallRepository $wallRepository,
		private OpeningRepository $openingRepository,
		private ItemRepository $itemRepository,
		private ItemGroupRepository $groupRepository,
		private VariantSetRepository $variantSetRepository,
		private SnapshotDefinitionRestorer $definitionRestorer,
		private SnapshotUnderlayRestorer $underlayRestorer,
		private SnapshotPlotRestorer $plotRestorer,
		private SnapshotViewRestorer $viewRestorer,
		private CommentKeeper $commentKeeper,
		private ItemModelLink $modelLink,
	) {
	}

	public function createSnapshot(Project $project): string
	{
		$floors = [];
		foreach ($this->floorRepository->findByProject($project->id) as $floor) {
			$scene = $this->sceneProvider->getScene($floor);
			$floors[] = [
				'floor' => $scene->floor,
				'rooms' => $scene->rooms,
				'walls' => $scene->walls,
				'items' => $scene->items,
				'groups' => $scene->groups,
				'variantSets' => $scene->variantSets,
				'definitions' => $scene->definitions,
				'underlay' => $scene->underlay,
				'models' => $scene->models,
				'pictures' => $scene->pictures,
				'plots' => $scene->plots,
			];
		}

		return json_encode(
			[
				'format' => self::Format,
				'project' => ['name' => $project->name, 'address' => $project->address, 'description' => $project->description],
				'materials' => $this->sceneProvider->getMaterials(),
				'location' => LocationDto::fromEntity($project),
				'floors' => $floors,
				'views' => $this->viewRestorer->snapshot($project),
			],
			JSON_THROW_ON_ERROR,
		);
	}

	/** @return list<FloorDto> */
	public function getFloors(string $snapshot): array
	{
		$data = $this->read($snapshot);
		$stack = FloorStack::ofSnapshot($data);

		return array_map(
			fn (SnapshotReader $entry): FloorDto => new FloorDto(
				id: $entry->object('floor')->int('id'),
				name: $entry->object('floor')->string('name'),
				level: $entry->object('floor')->int('level'),
				elevation: $stack[$entry->object('floor')->int('id')]['elevation'],
				slabThickness: $stack[$entry->object('floor')->int('id')]['slabThickness'],
			),
			$data->objects('floors'),
		);
	}

	/**
	 * The stored scene of one floor, shaped like `SceneDto`. Decoded to objects rather than arrays so
	 * empty JSON objects (item params) stay objects when re-encoded.
	 */
	public function getFloorScene(string $snapshot, int $floorId): ?stdClass
	{
		return $this->getFloorScenes($snapshot, $floorId)['scene'];
	}

	/**
	 * The stored scene of one floor and those of the floors under it (of a lower level, lowest first), shaped alike,
	 * from one decode of the snapshot. The scene is null when the floor is gone.
	 *
	 * @return array{scene: ?stdClass, below: list<stdClass>}
	 */
	public function getFloorScenes(string $snapshot, int $floorId): array
	{
		return SnapshotScenes::of($this->decode($snapshot, false), $floorId, fn (): SnapshotReader => $this->read($snapshot));
	}

	/**
	 * Replaces the project's floors with those of the snapshot (new ids). Project name, address and
	 * description are left as they are. The caller runs this in a transaction.
	 */
	public function restore(Project $project, string $snapshot): void
	{
		$data = $this->read($snapshot);
		// The views go with the floors; a snapshot from before saved views keeps the current ones.
		$currentViews = $this->viewRestorer->keep($project, $data);
		// The review comments are not in snapshots: they are carried over to the restored floors.
		$comments = $this->commentKeeper->keep($project);

		foreach ($this->floorRepository->findByProject($project->id) as $floor) {
			// Rooms, walls, openings, items, groups and variant sets go with it (ON DELETE CASCADE).
			$this->floorRepository->delete($floor);
		}

		$materials = [];
		foreach ($this->materialRepository->findAllOrdered() as $material) {
			$materials[$material->id] = $material;
		}
		/** @var array<int, FurnitureDefinition|null> $definitions by id in the snapshot, shared by all floors */
		$definitions = [];
		/** @var array<int, Floor> $floorIds new floors by their id in the snapshot */
		$floorIds = [];
		/** @var array<int, VariantSet> $allSetIds */
		$allSetIds = [];
		/** @var array<int, ItemGroup> $allGroupIds */
		$allGroupIds = [];
		$stack = FloorStack::ofSnapshot($data);

		foreach ($data->objects('floors') as $entry) {
			$floorData = $entry->object('floor');
			$floor = $this->stamp(new Floor(
				project: $project,
				name: $floorData->string('name'),
				level: $floorData->int('level'),
				elevation: $stack[$floorData->int('id')]['elevation'],
				slabThickness: $stack[$floorData->int('id')]['slabThickness'],
			));
			$this->floorRepository->persist($floor);
			$floorIds[$floorData->int('id')] = $floor;

			$this->underlayRestorer->restore($project, $floor, $entry);
			$roomIds = $this->restoreRooms($floor, $entry->objects('rooms'), $materials);
			$this->plotRestorer->restore($floor, $entry, $materials);
			$this->restoreWalls($floor, $entry->objects('walls'), $materials);
			$sets = $entry->raw('variantSets') === null ? [] : $entry->objects('variantSets');
			$setIds = $this->restoreVariantSets($floor, $sets);
			$groupIds = $this->restoreGroups($floor, $entry->raw('groups') === null ? [] : $entry->objects('groups'), $setIds);
			$definitions = $this->definitionRestorer->restore(
				$project,
				$entry->raw('definitions') === null ? [] : $entry->objects('definitions'),
				$definitions,
			);
			$this->restoreItems($floor, $entry->objects('items'), $materials, $roomIds, $groupIds, $definitions);
			$this->activateVariants($sets, $setIds, $groupIds);
			$allSetIds += $setIds;
			$allGroupIds += $groupIds;
		}

		$this->viewRestorer->restore($project, $data, $currentViews, $floorIds, $allSetIds, $allGroupIds);
		$this->commentKeeper->restore($comments, array_values($floorIds));
	}

	/**
	 * @param list<SnapshotReader> $rooms
	 * @param array<int, Material> $materials
	 * @return array<int, Room> new rooms by their id in the snapshot
	 */
	private function restoreRooms(Floor $floor, array $rooms, array $materials): array
	{
		$restored = [];
		foreach ($rooms as $data) {
			$wallFinishes = $data->nullableFinishes('wallFinishes');
			$room = $this->stamp(new Room(
				floor: $floor,
				code: $data->string('code'),
				name: $data->string('name'),
				type: RoomTypeEnum::from($data->string('type')),
				polygon: json_encode($data->raw('polygon'), JSON_THROW_ON_ERROR),
				ceilingHeight: $data->nullableInt('ceilingHeight'),
				floorMaterial: $materials[$data->nullableInt('floorMaterialId') ?? 0] ?? null,
				wallFinishes: $wallFinishes === '[]' ? null : $wallFinishes,
			));
			// Snapshots taken before the tile origin existed have none.
			$room->tileOriginX = $data->nullableInt('tileOriginX');
			$room->tileOriginY = $data->nullableInt('tileOriginY');
			$room->floorAngle = $data->nullableInt('floorAngle') ?? 0;
			$room->floorElevation = $data->nullableInt('floorElevation') ?? 0;
			// Nor ceilings and skirting before they had their own fields.
			$room->ceilingMaterial = $materials[$data->nullableInt('ceilingMaterialId') ?? 0] ?? null;
			$room->vaultSpacing = $data->nullableInt('vaultSpacing');
			$room->vaultAngle = $data->nullableInt('vaultAngle') ?? 0;
			$room->vaultRise = $data->nullableInt('vaultRise') ?? 130;
			$room->beamWidth = $data->nullableInt('beamWidth') ?? 80;
			$room->beamDrop = $data->nullableInt('beamDrop') ?? 30;
			$room->beamMaterial = $materials[$data->nullableInt('beamMaterialId') ?? 0] ?? null;
			$room->skirtingHeight = $data->nullableInt('skirtingHeight');
			$room->skirtingMaterial = $materials[$data->nullableInt('skirtingMaterialId') ?? 0] ?? null;
			$this->roomRepository->persist($room);
			$restored[$data->int('id')] = $room;
		}

		return $restored;
	}

	/**
	 * @param list<SnapshotReader> $walls
	 * @param array<int, Material> $materials
	 */
	private function restoreWalls(Floor $floor, array $walls, array $materials): void
	{
		foreach ($walls as $data) {
			$wall = $this->stamp(new Wall(
				floor: $floor,
				name: $data->string('name'),
				type: WallTypeEnum::from($data->string('type')),
				startX: $data->int('startX'),
				startY: $data->int('startY'),
				endX: $data->int('endX'),
				endY: $data->int('endY'),
				thickness: $data->int('thickness'),
				height: $data->int('height'),
				material: $materials[$data->nullableInt('materialId') ?? 0] ?? null,
			));
			// Snapshots taken before walls could curve have straight ones.
			$wall->sagitta = $data->nullableInt('sagitta') ?? 0;
			// Snapshots taken before face finishes have none (the rooms' bands); [] is kept, a bare face.
			$wall->leftFinishes = $data->nullableFinishes('leftFinishes');
			$wall->rightFinishes = $data->nullableFinishes('rightFinishes');
			$this->wallRepository->persist($wall);

			foreach ($data->objects('openings') as $opening) {
				$hinge = $opening->nullableString('hinge');
				$side = $opening->nullableString('side');
				$restored = $this->stamp(new Opening(
					wall: $wall,
					name: $opening->string('name'),
					type: OpeningTypeEnum::from($opening->string('type')),
					offset: $opening->int('offset'),
					width: $opening->int('width'),
					height: $opening->int('height'),
					sillHeight: $opening->int('sillHeight'),
					leafWidth: $opening->nullableInt('leafWidth'),
					hinge: $hinge === null ? null : OpeningHingeEnum::from($hinge),
					side: $side === null ? null : OpeningSideEnum::from($side),
				));
				// Snapshots taken before the flags existed have neither.
				$restored->entrance = $opening->raw('entrance') === true;
				$restored->walkable = $opening->raw('walkable') === true;
				// Nor the door's frame style and handle.
				$restored->frameStyle = DoorFrameStyleEnum::tryFrom(
					$opening->nullableString('frameStyle') ?? '',
				) ?? DoorFrameStyleEnum::Lining;
				$restored->handle = DoorHandleEnum::tryFrom($opening->nullableString('handle') ?? '');
				// And windows before their divisions and sill boards.
				$restored->mullions = $opening->nullableInt('mullions') ?? 1;
				$restored->transoms = $opening->nullableInt('transoms') ?? 0;
				$restored->sillBoard = $opening->nullableInt('sillBoard');
				// And openings before leaf styles and shutters.
				$restored->leafStyle = DoorLeafStyleEnum::tryFrom($opening->nullableString('leafStyle') ?? '') ?? DoorLeafStyleEnum::Flush;
				$restored->leafPanels = $opening->nullableInt('leafPanels') ?? 2;
				$restored->panelHeight = $opening->nullableInt('panelHeight');
				$restored->shutters = ShutterStyleEnum::tryFrom($opening->nullableString('shutters') ?? '');
				$restored->shutterMaterial = $materials[$opening->nullableInt('shutterMaterialId') ?? 0] ?? null;
				$restored->shuttersClosed = $opening->raw('shuttersClosed') === true;
				// And before niches.
				$restored->depth = $opening->nullableInt('depth');
				$this->openingRepository->persist($restored);
			}
		}
	}

	/**
	 * The active variants, once the groups have their new ids.
	 *
	 * @param list<SnapshotReader> $sets
	 * @param array<int, VariantSet> $setIds
	 * @param array<int, ItemGroup> $groupIds
	 */
	private function activateVariants(array $sets, array $setIds, array $groupIds): void
	{
		foreach ($sets as $data) {
			$set = $setIds[$data->int('id')];
			$set->activeGroupId = ($groupIds[$data->nullableInt('activeGroupId') ?? 0] ?? null)?->id;
			$this->variantSetRepository->persist($set);
		}
	}

	/**
	 * @param list<SnapshotReader> $sets
	 * @return array<int, VariantSet> new variant sets by their id in the snapshot (no active variant yet)
	 */
	private function restoreVariantSets(Floor $floor, array $sets): array
	{
		$restored = [];
		foreach ($sets as $data) {
			$set = $this->stamp(new VariantSet(floor: $floor, name: $data->string('name')));
			$this->variantSetRepository->persist($set);
			$restored[$data->int('id')] = $set;
		}

		return $restored;
	}

	/**
	 * @param list<SnapshotReader> $groups
	 * @param array<int, VariantSet> $setIds
	 * @return array<int, ItemGroup> new groups by their id in the snapshot
	 */
	private function restoreGroups(Floor $floor, array $groups, array $setIds): array
	{
		$restored = [];
		foreach ($groups as $data) {
			$group = $this->stamp(new ItemGroup(floor: $floor, name: $data->string('name')));
			$group->variantSet = $setIds[$data->nullableInt('variantSetId') ?? 0] ?? null;
			$this->groupRepository->persist($group);
			$restored[$data->int('id')] = $group;
		}

		return $restored;
	}

	/**
	 * @param list<SnapshotReader> $items
	 * @param array<int, Material> $materials
	 * @param array<int, Room> $roomIds
	 * @param array<int, ItemGroup> $groupIds
	 * @param array<int, FurnitureDefinition|null> $definitions by id in the snapshot (null: no usable copy)
	 */
	private function restoreItems(Floor $floor, array $items, array $materials, array $roomIds, array $groupIds, array $definitions): void
	{
		foreach ($items as $data) {
			$params = $data->raw('params');
			$item = $this->stamp(new Item(
				floor: $floor,
				room: $roomIds[$data->nullableInt('roomId') ?? 0] ?? null,
				kind: ItemKindEnum::from($data->string('kind')),
				name: $data->string('name'),
				x: $data->int('x'),
				y: $data->int('y'),
				z: $data->int('z'),
				width: $data->int('width'),
				depth: $data->int('depth'),
				height: $data->int('height'),
				rotation: $data->int('rotation'),
				material: $materials[$data->nullableInt('materialId') ?? 0] ?? null,
				params: $params === null || $params === [] ? null : json_encode($params, JSON_THROW_ON_ERROR),
			));
			$item->group = $groupIds[$data->nullableInt('groupId') ?? 0] ?? null;
			// Snapshots taken before Custom items existed have no definition ids, before Model items no model ids.
			$item->definition = $definitions[$data->nullableInt('definitionId') ?? 0] ?? null;
			$item->model = $this->modelLink->forProject($floor->project, $data->nullableInt('modelId'));
			$this->itemRepository->persist($item);
		}
	}

	private function read(string $snapshot): SnapshotReader
	{
		$data = $this->decode($snapshot, true);
		if (!is_array($data)) {
			throw new InvalidSnapshotException('Snapshot must be a JSON object.');
		}

		$reader = new SnapshotReader($data);
		if ($reader->int('format') !== self::Format) {
			throw new InvalidSnapshotException('Unsupported snapshot format.');
		}

		return $reader;
	}

	private function decode(string $snapshot, bool $associative): mixed
	{
		try {
			return json_decode($snapshot, $associative, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new InvalidSnapshotException('Snapshot is not valid JSON.', 0, $e);
		}
	}

	/**
	 * @param T $entity
	 * @return T
	 * @template T of AEntity
	 */
	private function stamp(AEntity $entity): AEntity
	{
		$now = new DateTimeImmutable();
		$entity->createdAt = $now;
		$entity->updatedAt = $now;

		return $entity;
	}
}
