<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Element;

use DateTimeImmutable;
use Izbik\Dto\RoomDto;
use Izbik\Model\Entity\AEntity;
use Izbik\Model\Entity\Enum\ElementTypeEnum;
use Izbik\Model\Entity\Enum\RoomTypeEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Room;
use Izbik\Model\Repository\MaterialRepository;
use Izbik\Model\Repository\RoomRepository;
use Izbik\Service\Editor\FieldReader;
use Izbik\Service\Editor\PolygonValidator;
use const JSON_THROW_ON_ERROR;

/**
 * Rooms: floor outline, ceiling height, floor material, wall finishes, ceiling and skirting (`RoomCeiling`), floor and ceiling zones
 * (`RoomZones`). Items keep their link to a
 * deleted room (hidden in the scene) so undo restores both.
 */
final readonly class RoomHandler implements ElementHandler
{
	/** How far a room's floor may lie above or below the floor's level, mm. */
	private const int MaxFloorElevation = 3000;

	/** The floor a new garage gets. */
	public const string GarageFloor = 'concrete-floor';

	public function __construct(
		private RoomRepository $roomRepository,
		private MaterialResolver $materialResolver,
		private RoomCeiling $ceiling,
		private WallFinishBands $wallFinishBands,
		private MaterialRepository $materialRepository,
		private RoomZones $zones,
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
			'floorAngle',
			'floorElevation',
			...array_keys(RoomCeiling::Defaults),
			'soffitMaterialId',
			'floorZones',
			'ceilingZones',
		];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/**
	 * A living room 3 m square; a garage on a concrete floor.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(Floor $floor, array $fields): array
	{
		$garage = ($fields['type'] ?? null) === RoomTypeEnum::Garage->value;

		return [
			'code' => 'R',
			'name' => 'Room',
			'type' => RoomTypeEnum::Living->value,
			'polygon' => [[0, 0], [3000, 0], [3000, 3000], [0, 3000]],
			'ceilingHeight' => 2600,
			'floorMaterialId' => $garage ? $this->materialRepository->findByCode(self::GarageFloor)?->id : null,
			'wallFinishes' => [],
			'tileOriginX' => null,
			'tileOriginY' => null,
			'floorAngle' => 0,
			'floorElevation' => 0,
		] + RoomCeiling::Defaults + ['soffitMaterialId' => null, 'floorZones' => [], 'ceilingZones' => []];
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
		$soffitMaterialId = $fields->nullableId('soffitMaterialId');
		$this->materialResolver->resolve($soffitMaterialId);
		$ceilingHeight = $fields->nullableInt('ceilingHeight', 1000, State::MaxSize);
		$polygon = PolygonValidator::validate($fields->raw('polygon'), State::CoordinateLimit);
		$ceiling = $this->ceiling->fields($ceilingHeight, $fields);

		return [
			'code' => $fields->string('code', 32),
			'name' => $fields->string('name', 255),
			'type' => $fields->enum('type', RoomTypeEnum::class)->value,
			'polygon' => $polygon,
			'ceilingHeight' => $ceilingHeight,
			'floorMaterialId' => $floorMaterialId,
			'wallFinishes' => $this->wallFinishBands->parse($fields->raw('wallFinishes') ?? [], 'wallFinishes'),
			'tileOriginX' => $fields->nullableInt('tileOriginX', -State::CoordinateLimit, State::CoordinateLimit),
			'tileOriginY' => $fields->nullableInt('tileOriginY', -State::CoordinateLimit, State::CoordinateLimit),
			// Planks and tiles look the same turned half round, so 0 ≤ angle < 180 (missing in older change states).
			'floorAngle' => ((self::floorAngle($fields) % 180) + 180) % 180,
			// Missing in older change states: the floor's level.
			'floorElevation' => $fields->raw('floorElevation') === null ? 0 : self::floorElevation($fields),
		] + $ceiling + [
			'soffitMaterialId' => $soffitMaterialId,
		] + $this->zones($fields, $polygon, $ceilingHeight, $ceiling['vaultSpacing'], $current === null ? null : self::cast($current));
	}

	/**
	 * The zones (missing in older change states: none), placed in the room only when set anew: a room reshaped, lowered
	 * or vaulted since keeps the ones it has.
	 *
	 * @param list<array{int, int}> $polygon
	 * @return array{floorZones: list<array{polygon: list<array{int, int}>, materialId: int}>, ceilingZones: list<array{polygon: list<array{int, int}>, height: int, materialId: int|null}>}
	 */
	private function zones(FieldReader $fields, array $polygon, ?int $ceilingHeight, ?int $vaultSpacing, ?Room $current): array
	{
		$floor = $this->zones->floorZones($fields->raw('floorZones'), $polygon, placed: false);
		if (RoomZones::encode($floor) !== $current?->floorZones) {
			$floor = $this->zones->floorZones($fields->raw('floorZones'), $polygon);
		}
		$ceiling = $this->zones->ceilingZones($fields->raw('ceilingZones'), $polygon, $ceilingHeight, $vaultSpacing, placed: false);
		if (RoomZones::encode($ceiling) !== $current?->ceilingZones) {
			$ceiling = $this->zones->ceilingZones($fields->raw('ceilingZones'), $polygon, $ceilingHeight, $vaultSpacing);
		}

		return ['floorZones' => $floor, 'ceilingZones' => $ceiling];
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
		$room->floorAngle = $fields->int('floorAngle', 0, 179, 'degrees');
		$room->floorElevation = self::floorElevation($fields);
		$this->ceiling->apply($room, $fields);
		$room->soffitMaterial = $this->materialResolver->resolve($fields->nullableId('soffitMaterialId'));
		$room->floorZones = RoomZones::encode($fields->raw('floorZones'));
		$room->ceilingZones = RoomZones::encode($fields->raw('ceilingZones'));
		$this->roomRepository->persist($room);
	}

	private static function floorElevation(FieldReader $fields): int
	{
		return $fields->int('floorElevation', -self::MaxFloorElevation, self::MaxFloorElevation);
	}

	private static function floorAngle(FieldReader $fields): int
	{
		return $fields->raw('floorAngle') === null ? 0 : $fields->int('floorAngle', -360, 360, 'degrees');
	}

	private static function cast(AEntity $element): Room
	{
		assert($element instanceof Room);

		return $element;
	}
}
