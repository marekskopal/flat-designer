<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\PolygonValidator;
use const JSON_THROW_ON_ERROR;

/**
 * Rooms: floor outline, ceiling height, floor material, wall finishes, ceiling and skirting (`RoomCeiling`). Items keep their link to a
 * deleted room (hidden in the scene) so undo restores both.
 */
final readonly class RoomHandler implements ElementHandler
{
	private const int MaxWallFinishes = 10;

	public function __construct(
		private RoomRepository $roomRepository,
		private MaterialResolver $materialResolver,
		private RoomCeiling $ceiling,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Room;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return [
			'code',
			'name',
			'type',
			'polygon',
			'ceilingHeight',
			'floorMaterialId',
			'wallFinishes',
			'tileOriginX',
			'tileOriginY',
			...array_keys(RoomCeiling::Defaults),
		];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(array $fields): array
	{
		return [
			'code' => 'R',
			'name' => 'Room',
			'type' => RoomTypeEnum::Living->value,
			'polygon' => [[0, 0], [3000, 0], [3000, 3000], [0, 3000]],
			'ceilingHeight' => 2600,
			'floorMaterialId' => null,
			'wallFinishes' => [],
			'tileOriginX' => null,
			'tileOriginY' => null,
		] + RoomCeiling::Defaults;
	}

	public function find(Floor $floor, int $id): ?Room
	{
		return $this->roomRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		$room = self::cast($element);
		return $room->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		$room = self::cast($element);
		return State::of(RoomDto::fromEntity($room));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state);
		$floorMaterialId = $fields->nullableId('floorMaterialId');
		$this->materialResolver->resolve($floorMaterialId);
		$ceilingHeight = $fields->nullableInt('ceilingHeight', 1000, State::MaxSize);

		return [
			'code' => $fields->string('code', 32),
			'name' => $fields->string('name', 255),
			'type' => $fields->enum('type', RoomTypeEnum::class)->value,
			'polygon' => PolygonValidator::validate($fields->raw('polygon'), State::CoordinateLimit),
			'ceilingHeight' => $ceilingHeight,
			'floorMaterialId' => $floorMaterialId,
			'wallFinishes' => $this->wallFinishes($fields->raw('wallFinishes')),
			'tileOriginX' => $fields->nullableInt('tileOriginX', -State::CoordinateLimit, State::CoordinateLimit),
			'tileOriginY' => $fields->nullableInt('tileOriginY', -State::CoordinateLimit, State::CoordinateLimit),
		] + $this->ceiling->fields($ceilingHeight, $fields);
	}

	public function create(Floor $floor, array $state): Room
	{
		$room = State::stamp(new Room(
			floor: $floor,
			code: '',
			name: '',
			type: RoomTypeEnum::Living,
			polygon: '[]',
			ceilingHeight: null,
			floorMaterial: null,
			wallFinishes: null,
		));
		$this->apply($room, $state);

		return $room;
	}

	public function update(AEntity $element, array $state): void
	{
		$room = self::cast($element);
		$room->updatedAt = new DateTimeImmutable();
		$this->apply($room, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$room = self::cast($element);
		$room->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$room->updatedAt = new DateTimeImmutable();
		$this->roomRepository->persist($room);
	}

	public function label(array $state): string
	{
		return sprintf('room "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(Room $room, array $state): void
	{
		$fields = new FieldReader($state);
		$room->code = $fields->string('code', 32);
		$room->name = $fields->string('name', 255);
		$room->type = $fields->enum('type', RoomTypeEnum::class);
		$room->polygon = json_encode($fields->raw('polygon'), JSON_THROW_ON_ERROR);
		$room->ceilingHeight = $fields->nullableInt('ceilingHeight', 1000, State::MaxSize);
		$room->floorMaterial = $this->materialResolver->resolve($fields->nullableId('floorMaterialId'));
		$wallFinishes = $fields->raw('wallFinishes');
		$room->wallFinishes = $wallFinishes === [] || $wallFinishes === null ? null : json_encode($wallFinishes, JSON_THROW_ON_ERROR);
		$room->tileOriginX = $fields->nullableInt('tileOriginX', -State::CoordinateLimit, State::CoordinateLimit);
		$room->tileOriginY = $fields->nullableInt('tileOriginY', -State::CoordinateLimit, State::CoordinateLimit);
		$room->ceilingMaterial = $this->materialResolver->resolve($fields->nullableId('ceilingMaterialId'));
		$room->vaultSpacing = $fields->nullableInt('vaultSpacing', 1, State::MaxSize);
		$room->vaultAngle = $fields->int('vaultAngle', 0, 179);
		$room->vaultRise = $fields->int('vaultRise', 0, State::MaxSize);
		$room->beamWidth = $fields->int('beamWidth', 0, State::MaxSize);
		$room->beamDrop = $fields->int('beamDrop', 0, State::MaxSize);
		$room->beamMaterial = $this->materialResolver->resolve($fields->nullableId('beamMaterialId'));
		$room->skirtingHeight = $fields->nullableInt('skirtingHeight', 1, State::MaxSize);
		$room->skirtingMaterial = $this->materialResolver->resolve($fields->nullableId('skirtingMaterialId'));
		$this->roomRepository->persist($room);
	}

	/** @return list<array{materialId: int, bottom: int, top: int}> */
	private function wallFinishes(mixed $bands): array
	{
		if ($bands === null) {
			return [];
		}
		if (!is_array($bands) || !array_is_list($bands) || count($bands) > self::MaxWallFinishes) {
			throw EditException::invalid(sprintf('Field "wallFinishes" must be a list of at most %d bands.', self::MaxWallFinishes));
		}

		$normalized = [];
		foreach ($bands as $band) {
			$fields = new FieldReader(is_array($band) ? self::object($band) : []);
			$materialId = $fields->nullableId('materialId') ?? throw EditException::invalid('Every wall finish needs a materialId.');
			$this->materialResolver->resolve($materialId);
			$bottom = $fields->int('bottom', 0, State::MaxSize);
			$top = $fields->int('top', 1, State::MaxSize);
			if ($top <= $bottom) {
				throw EditException::invalid('A wall finish must end above where it starts (top > bottom).');
			}
			$normalized[] = ['materialId' => $materialId, 'bottom' => $bottom, 'top' => $top];
		}

		return $normalized;
	}

	/**
	 * @param array<mixed> $value
	 * @return array<string, mixed>
	 */
	private static function object(array $value): array
	{
		$object = [];
		foreach ($value as $key => $item) {
			$object[(string) $key] = $item;
		}

		return $object;
	}

	private static function cast(AEntity $element): Room
	{
		assert($element instanceof Room);

		return $element;
	}
}
