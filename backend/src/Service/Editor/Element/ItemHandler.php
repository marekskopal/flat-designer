<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\ItemDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use const JSON_THROW_ON_ERROR;

final readonly class ItemHandler implements ElementHandler
{
	private const int MaxParamsBytes = 16384;

	public function __construct(
		private ItemRepository $itemRepository,
		private RoomRepository $roomRepository,
		private MaterialResolver $materialResolver,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Item;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['roomId', 'kind', 'name', 'x', 'y', 'z', 'width', 'depth', 'height', 'rotation', 'materialId', 'params'];
	}

	/** @return array<string, mixed> */
	public function defaults(): array
	{
		return [
			'roomId' => null,
			'kind' => ItemKindEnum::Box->value,
			'name' => 'Item',
			'x' => 0,
			'y' => 0,
			'z' => 0,
			'width' => 600,
			'depth' => 600,
			'height' => 720,
			'rotation' => 0,
			'materialId' => null,
			'params' => [],
		];
	}

	public function find(Floor $floor, int $id): ?Item
	{
		return $this->itemRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		$item = self::cast($element);
		return $item->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		$item = self::cast($element);
		return State::of(ItemDto::fromEntity($item));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state);
		$roomId = $fields->nullableId('roomId');
		$this->room($floor, $roomId);
		$materialId = $fields->nullableId('materialId');
		$this->materialResolver->resolve($materialId);

		return [
			'roomId' => $roomId,
			'kind' => $fields->enum('kind', ItemKindEnum::class)->value,
			'name' => $fields->string('name', 255),
			'x' => $fields->int('x', -State::CoordinateLimit, State::CoordinateLimit),
			'y' => $fields->int('y', -State::CoordinateLimit, State::CoordinateLimit),
			'z' => $fields->int('z', -State::MaxSize, State::MaxSize),
			'width' => $fields->int('width', 1, State::MaxSize),
			'depth' => $fields->int('depth', 1, State::MaxSize),
			'height' => $fields->int('height', 1, State::MaxSize),
			'rotation' => (($fields->int('rotation', -3600, 3600) % 360) + 360) % 360,
			'materialId' => $materialId,
			'params' => self::params($fields->raw('params')),
		];
	}

	public function create(Floor $floor, array $state): Item
	{
		$item = State::stamp(new Item(
			floor: $floor,
			room: null,
			kind: ItemKindEnum::Box,
			name: '',
			x: 0,
			y: 0,
			z: 0,
			width: 0,
			depth: 0,
			height: 0,
			rotation: 0,
			material: null,
			params: null,
		));
		$this->apply($item, $state);

		return $item;
	}

	public function update(AEntity $element, array $state): void
	{
		$item = self::cast($element);
		$item->updatedAt = new DateTimeImmutable();
		$this->apply($item, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$item = self::cast($element);
		$item->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$item->updatedAt = new DateTimeImmutable();
		$this->itemRepository->persist($item);
	}

	public function label(array $state): string
	{
		return sprintf('item "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(Item $item, array $state): void
	{
		$fields = new FieldReader($state);
		$item->room = $this->room($item->floor, $fields->nullableId('roomId'));
		$item->kind = $fields->enum('kind', ItemKindEnum::class);
		$item->name = $fields->string('name', 255);
		$item->x = $fields->int('x', -State::CoordinateLimit, State::CoordinateLimit);
		$item->y = $fields->int('y', -State::CoordinateLimit, State::CoordinateLimit);
		$item->z = $fields->int('z', -State::MaxSize, State::MaxSize);
		$item->width = $fields->int('width', 1, State::MaxSize);
		$item->depth = $fields->int('depth', 1, State::MaxSize);
		$item->height = $fields->int('height', 1, State::MaxSize);
		$item->rotation = $fields->int('rotation', 0, 359);
		$item->material = $this->materialResolver->resolve($fields->nullableId('materialId'));
		$params = $fields->raw('params');
		$item->params = $params === [] || $params === null ? null : json_encode($params, JSON_THROW_ON_ERROR);
		$this->itemRepository->persist($item);
	}

	private function room(Floor $floor, ?int $roomId): ?Room
	{
		if ($roomId === null) {
			return null;
		}

		$room = $this->roomRepository->findOnFloor($floor->id, $roomId);
		if ($room === null || $room->deletedAt !== null) {
			throw EditException::invalid(sprintf('Room %d is not on this floor.', $roomId));
		}

		return $room;
	}

	/** @return array<string, mixed> */
	private static function params(mixed $params): array
	{
		if ($params === null || $params === []) {
			return [];
		}
		if (!is_array($params) || array_is_list($params)) {
			throw EditException::invalid('Field "params" must be a JSON object.');
		}
		if (strlen(json_encode($params, JSON_THROW_ON_ERROR)) > self::MaxParamsBytes) {
			throw EditException::invalid('Field "params" is too large.');
		}

		$object = [];
		foreach ($params as $key => $value) {
			$object[(string) $key] = $value;
		}

		return $object;
	}

	private static function cast(AEntity $element): Item
	{
		assert($element instanceof Item);

		return $element;
	}
}
