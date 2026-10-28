<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\OpeningDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\DoorFrameStyleEnum;
use FlatDesigner\Model\Entity\Enum\DoorHandleEnum;
use FlatDesigner\Model\Entity\Enum\DoorLeafStyleEnum;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\ShutterStyleEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\RoofRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use FlatDesigner\Service\Editor\Geometry\WallLine;
use FlatDesigner\Service\Editor\Geometry\WallTop;

/**
 * Doors, passages and windows. An opening stays on the wall it was created on (`wallId` is fixed); it must
 * fit within the wall's length and height and must not overlap another opening of that wall. A sliding or
 * pocket door also needs the wall beside it, towards `hinge`, free for its open leaf (a pocket door a wall
 * thick enough for the pocket, a niche one thicker than its `depth`). Only a window down to the floor can be `walkable`, with one or two sashes. Leaf
 * styles are for doors with a leaf; a solid lower panel and shutters for windows.
 */
final readonly class OpeningHandler implements ElementHandler
{
	public const int MaxMullions = 8;

	public const int MaxTransoms = 4;

	public const int MaxSillBoard = 300;

	public function __construct(
		private OpeningRepository $openingRepository,
		private WallRepository $wallRepository,
		private MaterialResolver $materialResolver,
		private OpeningLook $look,
		private RoofRepository $roofRepository,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Opening;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['wallId', 'name', 'type', 'offset', 'width', 'height', 'sillHeight', 'leafWidth', 'hinge', 'side', 'entrance', 'walkable', 'frameStyle', 'handle', 'mullions', 'transoms', 'sillBoard', 'leafStyle', 'leafPanels', 'panelHeight', 'shutters', 'shutterMaterialId', 'shuttersClosed', 'depth'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(Floor $floor, array $fields): array
	{
		return OpeningDefaults::of(OpeningTypeEnum::tryFrom(is_string($fields['type'] ?? null) ? $fields['type'] : ''));
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

		$length = WallHandler::length($wall);
		$roofs = array_map(fn (Roof $roof): RoofGeometry => RoofGeometry::fromRoof($roof), $this->roofRepository->findByFloor($floor->id));
		$top = new WallTop(WallLine::fromWall($wall), $wall->height, $wall->upToRoof, $roofs);
		$offset = $fields->int('offset', 0, $length);
		$width = $fields->int('width', 100, $length);
		// A gable wall rising to the roof holds openings above its height.
		$height = $fields->int('height', 100, $top->highest());
		$sillHeight = $fields->int('sillHeight', 0, $top->highest());
		if ($offset + $width > $length) {
			throw EditException::invalid(
				sprintf('The opening ends %d mm past the end of the wall (%d mm long).', $offset + $width - $length, $length),
			);
		}
		// Checked when it is set anew only: a roof lowered since does not block renaming or undoing the opening.
		if (OpeningHead::placedAnew($currentOpening, $offset, $width, $sillHeight, $height)) {
			OpeningHead::check($top, $wall->height, $offset, $width, $sillHeight + $height);
		}

		$type = $fields->enum('type', OpeningTypeEnum::class);
		$hinge = $fields->nullableEnum('hinge', OpeningHingeEnum::class) ?? ($type->isSliding() ? OpeningHingeEnum::Start : null);
		$depth = OpeningPlacement::nicheDepth($type, $fields->nullableInt('depth', 1, State::MaxSize), $wall->thickness);
		OpeningPlacement::check(
			$type,
			$hinge,
			$offset,
			$width,
			$length,
			$wall->thickness,
			array_filter($this->openingRepository->findByWalls([$wall->id]), fn (Opening $o): bool => $o->id !== $currentOpening?->id),
			$fields->nullableEnum('side', OpeningSideEnum::class) ?? OpeningSideEnum::Left,
			$depth,
		);
		self::checkCurve($wall, $type, $hinge, $width);

		$entrance = $fields->bool('entrance');
		if ($entrance && $type === OpeningTypeEnum::Niche) {
			throw EditException::invalid('A niche cannot be the entrance.');
		}
		$walkable = $fields->bool('walkable');
		if ($walkable && ($type !== OpeningTypeEnum::Window || $sillHeight !== 0)) {
			throw EditException::invalid('Only a window down to the floor (sill height 0) can be walkable.');
		}
		$window = self::windowFields($type, $fields);
		if ($walkable && $window['mullions'] > 1) {
			throw EditException::invalid('A walkable window has one or two sashes (mullions 0 or 1).');
		}

		return [
			'wallId' => $wall->id,
			'name' => $fields->string('name', 255),
			'type' => $type->value,
			'offset' => $offset,
			'width' => $width,
			'height' => $height,
			'sillHeight' => $sillHeight,
			'entrance' => $entrance,
			'walkable' => $walkable,
			'frameStyle' => $type === OpeningTypeEnum::Niche
				? DoorFrameStyleEnum::None->value
				: ($fields->nullableEnum('frameStyle', DoorFrameStyleEnum::class) ?? DoorFrameStyleEnum::Lining)->value,
			'depth' => $depth,
		] + self::leafFields($type, $hinge, $width, $fields) + $window + $this->look->fields($type, $height, $fields);
	}

	/**
	 * The fields of a window's glazing: `mullions` (vertical bars, default 1 = two panes), `transoms` (horizontal
	 * bars) and `sillBoard` (how far the inner sill board reaches past the wall face, mm; none: no boards). Doors
	 * keep the defaults.
	 *
	 * @return array{mullions: int, transoms: int, sillBoard: int|null}
	 */
	private static function windowFields(OpeningTypeEnum $type, FieldReader $fields): array
	{
		if ($type !== OpeningTypeEnum::Window) {
			return ['mullions' => 1, 'transoms' => 0, 'sillBoard' => null];
		}

		return [
			'mullions' => $fields->raw('mullions') === null ? 1 : $fields->int('mullions', 0, self::MaxMullions),
			'transoms' => $fields->raw('transoms') === null ? 0 : $fields->int('transoms', 0, self::MaxTransoms),
			'sillBoard' => $fields->nullableInt('sillBoard', 0, self::MaxSillBoard),
		];
	}

	/**
	 * The fields of the leaves: a passage has none, a sliding leaf is as wide as its opening, and only doors
	 * with a leaf have a handle. A niche has no leaf either, only the `side` face it is cut into (default Left).
	 *
	 * @return array{leafWidth: int|null, hinge: string|null, side: string|null, handle: string|null}
	 */
	private static function leafFields(OpeningTypeEnum $type, ?OpeningHingeEnum $hinge, int $width, FieldReader $fields): array
	{
		$leaves = $type->hasLeaves();
		$passage = $type->isDoor() && !$leaves;
		$niche = $type === OpeningTypeEnum::Niche;

		return [
			'leafWidth' => $leaves && !$type->isSliding() ? $fields->nullableInt('leafWidth', 100, $width) : null,
			'hinge' => $passage || $niche ? null : $hinge?->value,
			'side' => $passage ? null : ($fields->nullableEnum(
				'side',
				OpeningSideEnum::class,
			) ?? ($niche ? OpeningSideEnum::Left : null))?->value,
			'handle' => $leaves ? $fields->nullableEnum('handle', DoorHandleEnum::class)?->value : null,
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
		$type = OpeningTypeEnum::tryFrom(is_string($state['type'] ?? null) ? $state['type'] : '')?->label() ?? 'opening';

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
		$opening->walkable = $fields->bool('walkable');
		$opening->frameStyle = $fields->enum('frameStyle', DoorFrameStyleEnum::class);
		$opening->handle = $fields->nullableEnum('handle', DoorHandleEnum::class);
		$opening->mullions = $fields->int('mullions', 0, self::MaxMullions);
		$opening->transoms = $fields->int('transoms', 0, self::MaxTransoms);
		$opening->sillBoard = $fields->nullableInt('sillBoard', 0, self::MaxSillBoard);
		$opening->leafStyle = $fields->enum('leafStyle', DoorLeafStyleEnum::class);
		$opening->leafPanels = $fields->int('leafPanels', 1, OpeningLook::MaxLeafPanels);
		$opening->panelHeight = $fields->nullableInt('panelHeight', 0, State::MaxSize);
		$opening->shutters = $fields->nullableEnum('shutters', ShutterStyleEnum::class);
		$opening->shutterMaterial = $this->materialResolver->resolve($fields->nullableId('shutterMaterialId'));
		$opening->shuttersClosed = $fields->bool('shuttersClosed');
		$opening->depth = $fields->nullableInt('depth', 1, State::MaxSize);
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

	/** On a curved wall a door or window is built straight, so it has to be shallow (`OpeningPlacement::fitsCurve()`). */
	private static function checkCurve(Wall $wall, OpeningTypeEnum $type, ?OpeningHingeEnum $hinge, int $width): void
	{
		$radius = WallLine::fromWall($wall)->arc()['radius'] ?? null;
		if ($radius !== null && !OpeningPlacement::fitsCurve($type, $hinge, $width, $radius, $wall->thickness)) {
			throw EditException::invalid(sprintf(
				'On this curved wall a door or window is built straight, so it may take %d mm of the wall at most%s.',
				OpeningPlacement::maxCurvedWidth($radius, $wall->thickness),
				$type->isSliding() ? ' with the wall its open leaf slides along' : '',
			));
		}
	}

	private static function cast(AEntity $element): Opening
	{
		assert($element instanceof Opening);

		return $element;
	}
}
