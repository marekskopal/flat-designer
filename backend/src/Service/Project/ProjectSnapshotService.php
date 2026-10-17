<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Dto\FloorDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Project\Exception\InvalidSnapshotException;
use FlatDesigner\Service\Provider\SceneProvider;
use JsonException;
use stdClass;
use const JSON_THROW_ON_ERROR;

/**
 * Serialises a project's working copy (all floors with their rooms, walls, openings and items) into the
 * JSON stored in `ProjectVersion::$snapshot`, serves floor scenes straight from it and restores it.
 *
 * Format 1: `{format, project: {name, address, description}, materials: [MaterialDto], floors: [{floor:
 * FloorDto, rooms: [RoomDto], walls: [WallDto with openings], items: [ItemDto], groups: [GroupDto]}]}` (`groups`
 * missing in snapshots taken before item groups existed) — each floor entry plus
 * the materials is exactly the viewer's `SceneDto`, so a version is rendered without touching the
 * working copy. Ids inside are those of the working copy at the time and only link entries together.
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
	) {
	}

	public function createSnapshot(Project $project): string
	{
		$floors = [];
		foreach ($this->floorRepository->findByProject($project->id) as $floor) {
			$scene = $this->sceneProvider->getScene($floor);
			$floors[] = ['floor' => $scene->floor, 'rooms' => $scene->rooms, 'walls' => $scene->walls, 'items' => $scene->items, 'groups' => $scene->groups];
		}

		return json_encode(
			[
				'format' => self::Format,
				'project' => ['name' => $project->name, 'address' => $project->address, 'description' => $project->description],
				'materials' => $this->sceneProvider->getMaterials(),
				'floors' => $floors,
			],
			JSON_THROW_ON_ERROR,
		);
	}

	/** @return list<FloorDto> */
	public function getFloors(string $snapshot): array
	{
		return array_map(
			fn (SnapshotReader $entry): FloorDto => new FloorDto(
				id: $entry->object('floor')->int('id'),
				name: $entry->object('floor')->string('name'),
				level: $entry->object('floor')->int('level'),
			),
			$this->read($snapshot)->objects('floors'),
		);
	}

	/**
	 * The stored scene of one floor, shaped like `SceneDto`. Decoded to objects rather than arrays so
	 * empty JSON objects (item params) stay objects when re-encoded.
	 */
	public function getFloorScene(string $snapshot, int $floorId): ?stdClass
	{
		$data = $this->decode($snapshot, false);
		if (!$data instanceof stdClass || !is_array($data->floors ?? null)) {
			throw new InvalidSnapshotException('Snapshot has no floors.');
		}

		foreach ($data->floors as $entry) {
			if ($entry instanceof stdClass && $entry->floor instanceof stdClass && $entry->floor->id === $floorId) {
				$scene = clone $entry;
				$scene->materials = $data->materials ?? [];

				return $scene;
			}
		}

		return null;
	}

	/**
	 * Replaces the project's floors with those of the snapshot (new ids). Project name, address and
	 * description are left as they are. The caller runs this in a transaction.
	 */
	public function restore(Project $project, string $snapshot): void
	{
		$data = $this->read($snapshot);

		foreach ($this->floorRepository->findByProject($project->id) as $floor) {
			// Rooms, walls, openings, items and groups go with it (ON DELETE CASCADE).
			$this->floorRepository->delete($floor);
		}

		$materials = [];
		foreach ($this->materialRepository->findAllOrdered() as $material) {
			$materials[$material->id] = $material;
		}

		foreach ($data->objects('floors') as $entry) {
			$floorData = $entry->object('floor');
			$floor = $this->stamp(new Floor(project: $project, name: $floorData->string('name'), level: $floorData->int('level')));
			$this->floorRepository->persist($floor);

			$roomIds = $this->restoreRooms($floor, $entry->objects('rooms'), $materials);
			$this->restoreWalls($floor, $entry->objects('walls'), $materials);
			$groupIds = $this->restoreGroups($floor, $entry->raw('groups') === null ? [] : $entry->objects('groups'));
			$this->restoreItems($floor, $entry->objects('items'), $materials, $roomIds, $groupIds);
		}
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
			$wallFinishes = $data->raw('wallFinishes');
			$room = $this->stamp(new Room(
				floor: $floor,
				code: $data->string('code'),
				name: $data->string('name'),
				type: RoomTypeEnum::from($data->string('type')),
				polygon: json_encode($data->raw('polygon'), JSON_THROW_ON_ERROR),
				ceilingHeight: $data->nullableInt('ceilingHeight'),
				floorMaterial: $materials[$data->nullableInt('floorMaterialId') ?? 0] ?? null,
				wallFinishes: $wallFinishes === null || $wallFinishes === [] ? null : json_encode($wallFinishes, JSON_THROW_ON_ERROR),
			));
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
				// Snapshots taken before the flag existed have no entrance.
				$restored->entrance = $opening->raw('entrance') === true;
				$this->openingRepository->persist($restored);
			}
		}
	}

	/**
	 * @param list<SnapshotReader> $groups
	 * @return array<int, ItemGroup> new groups by their id in the snapshot
	 */
	private function restoreGroups(Floor $floor, array $groups): array
	{
		$restored = [];
		foreach ($groups as $data) {
			$group = $this->stamp(new ItemGroup(floor: $floor, name: $data->string('name')));
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
	 */
	private function restoreItems(Floor $floor, array $items, array $materials, array $roomIds, array $groupIds): void
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
