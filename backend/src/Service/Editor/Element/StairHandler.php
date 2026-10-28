<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\StairDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\StairConstructionEnum;
use FlatDesigner\Model\Entity\Enum\StairRailingEnum;
use FlatDesigner\Model\Entity\Enum\StairShapeEnum;
use FlatDesigner\Model\Entity\Enum\StairTurnEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Stair;
use FlatDesigner\Model\Repository\StairRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\StairRise;

/**
 * Stairs from their floor up to the next one (FD-37). Only the fields are checked here; the treads, landings and the
 * footprint are drawn by the viewer (`viewer/engine/stairs.ts`).
 */
final readonly class StairHandler implements ElementHandler
{
	/** mm, a flight's width; a spiral's diameter. */
	private const int MinWidth = 600;

	private const int MaxWidth = 3000;

	private const int MinSpiralDiameter = 1200;

	private const int MaxSpiralDiameter = 4000;

	/** mm, a tread's depth. */
	private const int MinGoing = 200;

	private const int MaxGoing = 350;

	private const int MaxRisers = 60;

	/** A rise of its own (FD-87): two risers at least (2 × 140 mm), at most a storey and a half, mm. */
	private const int MinOwnRise = 2 * StairRise::MinRiser;

	private const int MaxOwnRise = 6000;

	/** How far the first riser may stand above or below the floor's level, mm (as a plot's ground). */
	private const int MaxBaseZ = 10000;

	/** mm between the flights of a U stair. */
	private const int MaxGap = 1000;

	private const int DefaultWidth = 900;

	private const int DefaultSpiralDiameter = 1600;

	public function __construct(
		private StairRepository $stairRepository,
		private MaterialResolver $materialResolver,
		private StairRise $stairRise,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Stair;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return [
			'name',
			'shape',
			'x',
			'y',
			'rotation',
			'width',
			'going',
			'risers',
			'firstFlight',
			'turn',
			'gap',
			'construction',
			'railing',
			'treadMaterialId',
			'structureMaterialId',
			'rise',
			'baseZ',
		];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/**
	 * A straight concrete flight 900 mm wide with a railing on the right; a spiral is 1600 mm across.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(Floor $floor, array $fields): array
	{
		$spiral = ($fields['shape'] ?? null) === StairShapeEnum::Spiral->value;

		return [
			'name' => 'Stairs',
			'shape' => StairShapeEnum::Straight->value,
			'x' => 0,
			'y' => 0,
			'rotation' => 0,
			'width' => $spiral ? self::DefaultSpiralDiameter : self::DefaultWidth,
			'going' => 270,
			'risers' => null,
			'firstFlight' => null,
			'turn' => StairTurnEnum::Left->value,
			'gap' => 100,
			'construction' => StairConstructionEnum::Solid->value,
			'railing' => StairRailingEnum::Right->value,
			'treadMaterialId' => null,
			'structureMaterialId' => null,
			'rise' => null,
			'baseZ' => 0,
		];
	}

	public function find(Floor $floor, int $id): ?Stair
	{
		return $this->stairRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		return self::cast($element)->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		return State::of(StairDto::fromEntity(self::cast($element)));
	}

	/**
	 * Checks the fields. A riser count is checked against the floors' rise (each riser 140–220 mm) and the landing's
	 * split against the count, but only when they are set anew: the rise changes with the floors, and a stair whose
	 * count no longer fits is still moved, renamed and undone (the viewer shows the riser it gets). Fields a shape does
	 * not use keep their values; a shape change that leaves the stored width out of the new shape's range (a spiral's
	 * diameter is wider) takes the shape's default width.
	 *
	 * @return array<string, mixed>
	 */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$stair = $current === null ? null : self::cast($current);
		$fields = new FieldReader($state);
		$shape = $fields->enum('shape', StairShapeEnum::class);
		$width = $this->width($fields, $shape, $stair);
		$risers = $fields->nullableInt('risers', 2, self::MaxRisers);
		$firstFlight = $fields->nullableInt('firstFlight', 1, self::MaxRisers);
		// Missing in changes logged before outdoor stairs: to the next floor, from the floor's level.
		$ownRise = $fields->nullableInt('rise', self::MinOwnRise, self::MaxOwnRise);
		$risersChanged = $stair === null || $risers !== $stair->risers || $ownRise !== $stair->rise;
		$landingChanged = $stair === null || $firstFlight !== $stair->firstFlight || $shape !== $stair->shape;
		$rise = null;
		if ($risers !== null && $risersChanged) {
			$rise = $ownRise ?? $this->stairRise->of($floor);
			$riser = $rise / $risers;
			if ($riser < StairRise::MinRiser || $riser > StairRise::MaxRiser) {
				throw EditException::invalid(sprintf(
					'%d risers make each %.0f mm high over the %d mm rise; a riser must be %d–%d mm (null counts them).',
					$risers,
					$riser,
					$rise,
					StairRise::MinRiser,
					StairRise::MaxRiser,
				));
			}
		}
		if ($shape->hasLanding() && $firstFlight !== null && ($risersChanged || $landingChanged)) {
			$count = StairRise::risers($rise ?? $ownRise ?? $this->stairRise->of($floor), $risers);
			if ($firstFlight >= $count) {
				throw EditException::invalid(sprintf('Field "firstFlight" must be fewer than the stair\'s %d risers.', $count));
			}
		}
		$treadMaterialId = $fields->nullableId('treadMaterialId');
		$structureMaterialId = $fields->nullableId('structureMaterialId');
		$this->materialResolver->resolve($treadMaterialId);
		$this->materialResolver->resolve($structureMaterialId);

		return [
			'name' => $fields->string('name', 255),
			'shape' => $shape->value,
			'x' => $fields->int('x', -State::CoordinateLimit, State::CoordinateLimit),
			'y' => $fields->int('y', -State::CoordinateLimit, State::CoordinateLimit),
			'rotation' => (($fields->int('rotation', -3600, 3600, 'degrees') % 360) + 360) % 360,
			'width' => $width,
			'going' => $fields->int('going', self::MinGoing, self::MaxGoing),
			'risers' => $risers,
			'firstFlight' => $firstFlight,
			'turn' => $fields->enum('turn', StairTurnEnum::class)->value,
			'gap' => $fields->int('gap', 0, self::MaxGap),
			'construction' => $fields->enum('construction', StairConstructionEnum::class)->value,
			'railing' => $fields->enum('railing', StairRailingEnum::class)->value,
			'treadMaterialId' => $treadMaterialId,
			'structureMaterialId' => $structureMaterialId,
			'rise' => $ownRise,
			'baseZ' => $fields->raw('baseZ') === null ? 0 : $fields->int('baseZ', -self::MaxBaseZ, self::MaxBaseZ),
		];
	}

	public function create(Floor $floor, array $state): Stair
	{
		$stair = State::stamp(new Stair(
			floor: $floor,
			name: '',
			shape: StairShapeEnum::Straight,
			x: 0,
			y: 0,
			rotation: 0,
			width: self::DefaultWidth,
			going: 270,
			risers: null,
			firstFlight: null,
			turn: StairTurnEnum::Left,
			gap: 100,
			construction: StairConstructionEnum::Solid,
			railing: StairRailingEnum::Right,
		));
		$this->apply($stair, $state);

		return $stair;
	}

	public function update(AEntity $element, array $state): void
	{
		$stair = self::cast($element);
		$stair->updatedAt = new DateTimeImmutable();
		$this->apply($stair, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$stair = self::cast($element);
		$stair->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$stair->updatedAt = new DateTimeImmutable();
		$this->stairRepository->persist($stair);
	}

	public function label(array $state): string
	{
		return sprintf('stairs "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** The width in the shape's range; the stored width of another shape, left as it was, gives way to the default. */
	private function width(FieldReader $fields, StairShapeEnum $shape, ?Stair $current): int
	{
		[$min, $max] = $shape === StairShapeEnum::Spiral
			? [self::MinSpiralDiameter, self::MaxSpiralDiameter]
			: [self::MinWidth, self::MaxWidth];
		$width = $fields->raw('width');
		if ($current !== null && $shape !== $current->shape && $width === $current->width && ($width < $min || $width > $max)) {
			return $shape === StairShapeEnum::Spiral ? self::DefaultSpiralDiameter : self::DefaultWidth;
		}

		return $fields->int('width', $min, $max);
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(Stair $stair, array $state): void
	{
		$fields = new FieldReader($state);
		$shape = $fields->enum('shape', StairShapeEnum::class);
		$turn = $fields->enum('turn', StairTurnEnum::class);
		$construction = $fields->enum('construction', StairConstructionEnum::class);
		$railing = $fields->enum('railing', StairRailingEnum::class);
		$stair->name = $fields->string('name', 255);
		$stair->shape = $shape;
		$stair->x = $fields->int('x', -State::CoordinateLimit, State::CoordinateLimit);
		$stair->y = $fields->int('y', -State::CoordinateLimit, State::CoordinateLimit);
		$stair->rotation = $fields->int('rotation', 0, 359, 'degrees');
		$stair->width = $fields->int('width', self::MinWidth, self::MaxSpiralDiameter);
		$stair->going = $fields->int('going', self::MinGoing, self::MaxGoing);
		$stair->risers = $fields->nullableInt('risers', 2, self::MaxRisers);
		$stair->firstFlight = $fields->nullableInt('firstFlight', 1, self::MaxRisers);
		$stair->turn = $turn;
		$stair->gap = $fields->int('gap', 0, self::MaxGap);
		$stair->construction = $construction;
		$stair->railing = $railing;
		$stair->treadMaterial = $this->materialResolver->resolve($fields->nullableId('treadMaterialId'));
		$stair->structureMaterial = $this->materialResolver->resolve($fields->nullableId('structureMaterialId'));
		$stair->rise = $fields->nullableInt('rise', self::MinOwnRise, self::MaxOwnRise);
		$stair->baseZ = $fields->int('baseZ', -self::MaxBaseZ, self::MaxBaseZ);
		$this->stairRepository->persist($stair);
	}

	private static function cast(AEntity $element): Stair
	{
		assert($element instanceof Stair);

		return $element;
	}
}
