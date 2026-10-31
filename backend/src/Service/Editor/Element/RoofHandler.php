<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\RoofDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\RoofShapeEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\RoofRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\Geometry\WallLine;
use FlatDesigner\Service\Project\FloorStack;
use const INF;
use const M_PI;

/**
 * Roofs over a floor (FD-38). Only the fields are checked here; the planes are `RoofGeometry`, drawn by the viewer
 * (`viewer/engine/roof-geometry.ts`). The state leaves out the windows (elements of their own, which move with the
 * roof); a deleted roof hides its windows until undo brings it back. A changed roof does not check its windows again:
 * the viewer leaves out one no longer on its roof.
 */
final readonly class RoofHandler implements ElementHandler
{
	/** mm, a side of the eaves rectangle. */
	public const int MinSide = 1000;

	public const int MaxSide = 60000;

	/** Degrees. */
	public const int MinPitch = 5;

	public const int MaxPitch = 75;

	/** mm past the eaves or the gables. */
	public const int MaxOverhang = 2000;

	public const int MinThickness = 50;

	public const int MaxThickness = 1000;

	public const int MinParapet = 100;

	public const int MaxParapet = 2000;

	public function __construct(
		private RoofRepository $roofRepository,
		private WallRepository $wallRepository,
		private MaterialResolver $materialResolver,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Roof;
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
			'depth',
			'eaveHeight',
			'pitch',
			'overhang',
			'vergeOverhang',
			'thickness',
			'parapet',
			'coveringMaterialId',
			'soffitMaterialId',
			'gutters',
			'gutterMaterialId',
			'downpipes',
			'snowGuards',
			'ridgeStyle',
		];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/**
	 * A gable roof pitched at 35° over the floor: the smallest rectangle round the walls' outer faces, turned along the
	 * longest exterior wall (any wall without one), its ridge along the longer side, the eaves at the tallest wall.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(Floor $floor, array $fields): array
	{
		$walls = $this->wallRepository->findByFloor($floor->id);

		return [
			'name' => 'Roof',
			'shape' => RoofShapeEnum::Gable->value,
			...self::outline($walls),
			'eaveHeight' => FloorStack::tallestWall(array_map(fn (Wall $wall): int => $wall->height, $walls)),
			'pitch' => 35,
			'overhang' => 400,
			'vergeOverhang' => 200,
			'thickness' => 250,
			'parapet' => null,
			'coveringMaterialId' => null,
			'soffitMaterialId' => null,
			...RoofDetails::Defaults,
		];
	}

	public function find(Floor $floor, int $id): ?Roof
	{
		return $this->roofRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		return self::cast($element)->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		$state = State::of(RoofDto::fromEntity(self::cast($element), []));
		unset($state['windows']);

		return $state;
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state + RoofDetails::Defaults);
		$shape = $fields->enum('shape', RoofShapeEnum::class);
		$parapet = $fields->nullableInt('parapet', self::MinParapet, self::MaxParapet);
		if ($parapet !== null && $shape !== RoofShapeEnum::Flat) {
			// A flat roof given another shape leaves its parapet behind; a parapet given anew is refused.
			if ($current === null || self::cast($current)->parapet !== $parapet) {
				throw EditException::invalid('Only a flat roof has a parapet.');
			}
			$parapet = null;
		}
		$coveringMaterialId = $fields->nullableId('coveringMaterialId');
		$soffitMaterialId = $fields->nullableId('soffitMaterialId');
		$this->materialResolver->resolve($coveringMaterialId);
		$this->materialResolver->resolve($soffitMaterialId);
		$gutterMaterialId = $fields->nullableId('gutterMaterialId');
		$this->materialResolver->resolve($gutterMaterialId);
		$width = $fields->int('width', self::MinSide, self::MaxSide);
		$depth = $fields->int('depth', self::MinSide, self::MaxSide);

		return [
			'name' => $fields->string('name', 255),
			'shape' => $shape->value,
			'x' => $fields->int('x', -State::CoordinateLimit, State::CoordinateLimit),
			'y' => $fields->int('y', -State::CoordinateLimit, State::CoordinateLimit),
			'rotation' => (($fields->int('rotation', -3600, 3600, 'degrees') % 360) + 360) % 360,
			'width' => $width,
			'depth' => $depth,
			'eaveHeight' => $fields->int('eaveHeight', 0, State::MaxSize),
			'pitch' => $fields->int('pitch', self::MinPitch, self::MaxPitch, 'degrees'),
			'overhang' => $fields->int('overhang', 0, self::MaxOverhang),
			'vergeOverhang' => $fields->int('vergeOverhang', 0, self::MaxOverhang),
			'thickness' => $fields->int('thickness', self::MinThickness, self::MaxThickness),
			'parapet' => $parapet,
			'coveringMaterialId' => $coveringMaterialId,
			'soffitMaterialId' => $soffitMaterialId,
			'gutterMaterialId' => $gutterMaterialId,
			...RoofDetails::normalize($fields, $shape, $width, $depth, $current === null ? null : self::cast($current)),
		];
	}

	public function create(Floor $floor, array $state): Roof
	{
		$roof = State::stamp(new Roof(
			floor: $floor,
			name: '',
			shape: RoofShapeEnum::Gable,
			x: 0,
			y: 0,
			rotation: 0,
			width: self::MinSide,
			depth: self::MinSide,
			eaveHeight: 0,
			pitch: 35,
			overhang: 0,
			vergeOverhang: 0,
			thickness: 250,
		));
		$this->apply($roof, $state);

		return $roof;
	}

	public function update(AEntity $element, array $state): void
	{
		$roof = self::cast($element);
		$roof->updatedAt = new DateTimeImmutable();
		$this->apply($roof, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$roof = self::cast($element);
		$roof->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$roof->updatedAt = new DateTimeImmutable();
		$this->roofRepository->persist($roof);
	}

	public function label(array $state): string
	{
		return sprintf('roof "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/**
	 * The smallest rectangle round the walls' footprints (their ends squared off half the thickness out, which reaches a
	 * corner's outside), turned along the longest exterior wall, or the longest wall; its ridge along the longer side.
	 * A floor without walls gets 10 × 8 m from the origin.
	 *
	 * @param list<Wall> $walls
	 * @return array{x: int, y: int, rotation: int, width: int, depth: int}
	 */
	private static function outline(array $walls): array
	{
		if ($walls === []) {
			return ['x' => 5000, 'y' => 4000, 'rotation' => 0, 'width' => 10000, 'depth' => 8000];
		}
		$exterior = array_values(array_filter($walls, fn (Wall $wall): bool => $wall->type === WallTypeEnum::Exterior));
		$longest = null;
		foreach ($exterior === [] ? $walls : $exterior as $wall) {
			if ($longest === null || WallLine::fromWall($wall)->chord() > WallLine::fromWall($longest)->chord()) {
				$longest = $wall;
			}
		}
		$angle = (int) round(rad2deg(atan2($longest->endY - $longest->startY, $longest->endX - $longest->startX)));
		$rotation = (($angle % 180) + 180) % 180;
		$cos = cos($rotation * M_PI / 180);
		$sin = sin($rotation * M_PI / 180);

		$minS = $minT = INF;
		$maxS = $maxT = -INF;
		foreach ($walls as $wall) {
			$half = $wall->thickness / 2;
			$ends = ['startLeft' => $half, 'startRight' => $half, 'endLeft' => $half, 'endRight' => $half];
			foreach (WallLine::fromWall($wall)->outline($ends) as [$x, $y]) {
				$s = $x * $cos + $y * $sin;
				$t = -$x * $sin + $y * $cos;
				$minS = min($minS, $s);
				$maxS = max($maxS, $s);
				$minT = min($minT, $t);
				$maxT = max($maxT, $t);
			}
		}
		$width = max(self::MinSide, (int) round($maxS - $minS));
		$depth = max(self::MinSide, (int) round($maxT - $minT));
		$s = ($minS + $maxS) / 2;
		$t = ($minT + $maxT) / 2;
		$centre = ['x' => (int) round($s * $cos - $t * $sin), 'y' => (int) round($s * $sin + $t * $cos)];
		if ($width < $depth) {
			return $centre + ['rotation' => ($rotation + 90) % 360, 'width' => $depth, 'depth' => $width];
		}

		return $centre + ['rotation' => $rotation, 'width' => $width, 'depth' => $depth];
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(Roof $roof, array $state): void
	{
		$fields = new FieldReader($state);
		$roof->name = $fields->string('name', 255);
		$roof->shape = $fields->enum('shape', RoofShapeEnum::class);
		$roof->x = $fields->int('x', -State::CoordinateLimit, State::CoordinateLimit);
		$roof->y = $fields->int('y', -State::CoordinateLimit, State::CoordinateLimit);
		$roof->rotation = $fields->int('rotation', 0, 359, 'degrees');
		$roof->width = $fields->int('width', self::MinSide, self::MaxSide);
		$roof->depth = $fields->int('depth', self::MinSide, self::MaxSide);
		$roof->eaveHeight = $fields->int('eaveHeight', 0, State::MaxSize);
		$roof->pitch = $fields->int('pitch', self::MinPitch, self::MaxPitch, 'degrees');
		$roof->overhang = $fields->int('overhang', 0, self::MaxOverhang);
		$roof->vergeOverhang = $fields->int('vergeOverhang', 0, self::MaxOverhang);
		$roof->thickness = $fields->int('thickness', self::MinThickness, self::MaxThickness);
		$roof->parapet = $fields->nullableInt('parapet', self::MinParapet, self::MaxParapet);
		$roof->coveringMaterial = $this->materialResolver->resolve($fields->nullableId('coveringMaterialId'));
		$roof->soffitMaterial = $this->materialResolver->resolve($fields->nullableId('soffitMaterialId'));
		$roof->gutterMaterial = $this->materialResolver->resolve($fields->nullableId('gutterMaterialId'));
		RoofDetails::apply($roof, $fields);
		$this->roofRepository->persist($roof);
	}

	private static function cast(AEntity $element): Roof
	{
		assert($element instanceof Roof);

		return $element;
	}
}
