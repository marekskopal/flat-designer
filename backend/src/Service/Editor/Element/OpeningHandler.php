<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\OpeningDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;

/**
 * Doors and windows. An opening stays on the wall it was created on (`wallId` is fixed); it must fit
 * within the wall's length and height and must not overlap another opening of that wall.
 */
final readonly class OpeningHandler implements ElementHandler
{
	public function __construct(private OpeningRepository $openingRepository, private WallRepository $wallRepository)
	{
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Opening;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['wallId', 'name', 'type', 'offset', 'width', 'height', 'sillHeight', 'leafWidth', 'hinge', 'side', 'entrance'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(array $fields): array
	{
		if (($fields['type'] ?? null) === OpeningTypeEnum::Window->value) {
			return [
				'wallId' => null,
				'name' => 'Window',
				'type' => OpeningTypeEnum::Window->value,
				'offset' => 100,
				'width' => 1200,
				'height' => 1200,
				'sillHeight' => 900,
				'leafWidth' => null,
				'hinge' => null,
				'side' => null,
				'entrance' => false,
			];
		}

		return [
			'wallId' => null,
			'name' => 'Door',
			'type' => OpeningTypeEnum::Door->value,
			'offset' => 100,
			'width' => 900,
			'height' => 1970,
			'sillHeight' => 0,
			'leafWidth' => 800,
			'hinge' => OpeningHingeEnum::Start->value,
			'side' => OpeningSideEnum::Left->value,
			'entrance' => false,
		];
	}

	public function find(Floor $floor, int $id): ?Opening
	{
		$opening = $this->openingRepository->findById($id);

		return $opening !== null && $opening->wall->floor->id === $floor->id ? $opening : null;
	}

	/** An opening of a deleted wall counts as deleted too. */
	public function isDeleted(AEntity $element): bool
	{
		$opening = self::cast($element);
		return $opening->deletedAt !== null || $opening->wall->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		$opening = self::cast($element);
		return ['wallId' => $opening->wall->id] + State::of(OpeningDto::fromEntity($opening));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$currentOpening = $current === null ? null : self::cast($current);
		$fields = new FieldReader($state);
		$wallId = $fields->nullableId('wallId') ?? throw EditException::invalid('Field "wallId" is required.');
		if ($currentOpening !== null && $currentOpening->wall->id !== $wallId) {
			throw EditException::invalid('An opening cannot be moved to another wall; delete it and add a new one.');
		}
		$wall = $this->wall($floor, $wallId);

		$length = WallHandler::length($wall->startX, $wall->startY, $wall->endX, $wall->endY);
		$offset = $fields->int('offset', 0, $length);
		$width = $fields->int('width', 100, $length);
		$height = $fields->int('height', 100, $wall->height);
		$sillHeight = $fields->int('sillHeight', 0, $wall->height);
		if ($offset + $width > $length) {
			throw EditException::invalid(
				sprintf('The opening ends %d mm past the end of the wall (%d mm long).', $offset + $width - $length, $length),
			);
		}
		if ($sillHeight + $height > $wall->height) {
			throw EditException::invalid(sprintf('Sill plus height exceeds the wall height (%d mm).', $wall->height));
		}

		foreach ($this->openingRepository->findByWalls([$wall->id]) as $other) {
			if ($other->id !== $currentOpening?->id && $offset < $other->offset + $other->width && $other->offset < $offset + $width) {
				throw EditException::invalid(sprintf('The opening overlaps "%s" on this wall.', $other->name));
			}
		}

		return [
			'wallId' => $wall->id,
			'name' => $fields->string('name', 255),
			'type' => $fields->enum('type', OpeningTypeEnum::class)->value,
			'offset' => $offset,
			'width' => $width,
			'height' => $height,
			'sillHeight' => $sillHeight,
			'leafWidth' => $fields->nullableInt('leafWidth', 100, $width),
			'hinge' => $fields->nullableEnum('hinge', OpeningHingeEnum::class)?->value,
			'side' => $fields->nullableEnum('side', OpeningSideEnum::class)?->value,
			'entrance' => $fields->bool('entrance'),
		];
	}

	public function create(Floor $floor, array $state): Opening
	{
		$fields = new FieldReader($state);
		$opening = State::stamp(new Opening(
			wall: $this->wall($floor, $fields->nullableId('wallId') ?? 0),
			name: '',
			type: OpeningTypeEnum::Door,
			offset: 0,
			width: 0,
			height: 0,
			sillHeight: 0,
			leafWidth: null,
			hinge: null,
			side: null,
		));
		$this->apply($opening, $state);

		return $opening;
	}

	public function update(AEntity $element, array $state): void
	{
		$opening = self::cast($element);
		$opening->updatedAt = new DateTimeImmutable();
		$this->apply($opening, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$opening = self::cast($element);
		$opening->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$opening->updatedAt = new DateTimeImmutable();
		$this->openingRepository->persist($opening);
	}

	public function label(array $state): string
	{
		$type = ($state['type'] ?? null) === OpeningTypeEnum::Window->value ? 'window' : 'door';

		return sprintf('%s "%s"', $type, is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(Opening $opening, array $state): void
	{
		$fields = new FieldReader($state);
		$opening->name = $fields->string('name', 255);
		$opening->type = $fields->enum('type', OpeningTypeEnum::class);
		$opening->offset = $fields->int('offset', 0, State::CoordinateLimit);
		$opening->width = $fields->int('width', 1, State::CoordinateLimit);
		$opening->height = $fields->int('height', 1, State::MaxSize);
		$opening->sillHeight = $fields->int('sillHeight', 0, State::MaxSize);
		$opening->leafWidth = $fields->nullableInt('leafWidth', 1, State::CoordinateLimit);
		$opening->hinge = $fields->nullableEnum('hinge', OpeningHingeEnum::class);
		$opening->side = $fields->nullableEnum('side', OpeningSideEnum::class);
		$opening->entrance = $fields->bool('entrance');
		$this->openingRepository->persist($opening);
	}

	private function wall(Floor $floor, int $wallId): Wall
	{
		$wall = $this->wallRepository->findOnFloor($floor->id, $wallId);
		if ($wall === null || $wall->deletedAt !== null) {
			throw EditException::invalid(sprintf('Wall %d is not on this floor.', $wallId));
		}

		return $wall;
	}

	private static function cast(AEntity $element): Opening
	{
		assert($element instanceof Opening);

		return $element;
	}
}
