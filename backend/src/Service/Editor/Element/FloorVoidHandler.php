<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\FloorVoidDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FloorVoid;
use FlatDesigner\Model\Repository\FloorVoidRepository;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\PolygonValidator;
use const JSON_THROW_ON_ERROR;

/**
 * Floor voids (FD-90): holes through the floor's slab and room floors, open to the floor below (whose ceilings they cut),
 * their edges lined with `liningMaterialId`.
 */
final readonly class FloorVoidHandler implements ElementHandler
{
	/** mm, the deepest lining: a storey and a half. */
	public const int MaxLiningDepth = 6000;

	/** mm, the side of a new void's square, south-west corner at the origin. */
	private const int DefaultSide = 1200;

	public function __construct(private FloorVoidRepository $voidRepository, private MaterialResolver $materialResolver)
	{
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::FloorVoid;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['name', 'polygon', 'liningMaterialId', 'liningDepth'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(Floor $floor, array $fields): array
	{
		$side = self::DefaultSide;

		return [
			'name' => 'Void',
			'polygon' => [[0, 0], [$side, 0], [$side, $side], [0, $side]],
			'liningMaterialId' => null,
			'liningDepth' => null,
		];
	}

	public function find(Floor $floor, int $id): ?FloorVoid
	{
		return $this->voidRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		return self::cast($element)->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		return State::of(FloorVoidDto::fromEntity(self::cast($element)));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state);
		$liningMaterialId = $fields->nullableId('liningMaterialId');
		$this->materialResolver->resolve($liningMaterialId);

		return [
			'name' => $fields->string('name', 255),
			'polygon' => PolygonValidator::validate($fields->raw('polygon'), State::CoordinateLimit),
			'liningMaterialId' => $liningMaterialId,
			'liningDepth' => $fields->nullableInt('liningDepth', 1, self::MaxLiningDepth),
		];
	}

	public function create(Floor $floor, array $state): FloorVoid
	{
		$void = State::stamp(new FloorVoid(floor: $floor, name: '', polygon: '[]'));
		$this->apply($void, $state);

		return $void;
	}

	public function update(AEntity $element, array $state): void
	{
		$void = self::cast($element);
		$void->updatedAt = new DateTimeImmutable();
		$this->apply($void, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$void = self::cast($element);
		$void->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$void->updatedAt = new DateTimeImmutable();
		$this->voidRepository->persist($void);
	}

	public function label(array $state): string
	{
		return sprintf('void "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(FloorVoid $void, array $state): void
	{
		$fields = new FieldReader($state);
		$void->name = $fields->string('name', 255);
		$void->polygon = json_encode($fields->raw('polygon'), JSON_THROW_ON_ERROR);
		$void->liningMaterial = $this->materialResolver->resolve($fields->nullableId('liningMaterialId'));
		$void->liningDepth = $fields->nullableInt('liningDepth', 1, self::MaxLiningDepth);
		$this->voidRepository->persist($void);
	}

	private static function cast(AEntity $element): FloorVoid
	{
		assert($element instanceof FloorVoid);

		return $element;
	}
}
