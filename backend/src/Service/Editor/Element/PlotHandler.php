<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\PlotDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Plot;
use FlatDesigner\Model\Repository\PlotRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\PolygonValidator;
use FlatDesigner\Service\Json\JsonDecoder;
use const JSON_THROW_ON_ERROR;

/** Plots: the boundary of the land around the building and the ground's material. */
final readonly class PlotHandler implements ElementHandler
{
	/** A plot may reach further from the floor's origin than a room (`State::CoordinateLimit`): a kilometre, mm. */
	private const int CoordinateLimit = 1000000;

	/** How far a new plot's boundary stands off the floor's walls and rooms, mm. */
	private const int DefaultMargin = 3000;

	/** Half the side of a new plot on an empty floor, about the origin, mm. */
	private const int DefaultHalfSide = 10000;

	public function __construct(
		private PlotRepository $plotRepository,
		private WallRepository $wallRepository,
		private RoomRepository $roomRepository,
		private MaterialResolver $materialResolver,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Plot;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['name', 'polygon', 'groundMaterialId'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/**
	 * A new plot is a rectangle `DefaultMargin` clear of the floor's walls (their curves too) and rooms; on an empty
	 * floor a square about the origin.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(Floor $floor, array $fields): array
	{
		return ['name' => 'Plot', 'polygon' => $this->defaultBoundary($floor), 'groundMaterialId' => null];
	}

	/** @return list<array{int, int}> */
	private function defaultBoundary(Floor $floor): array
	{
		$xs = [];
		$ys = [];
		$bulge = 0;
		foreach ($this->wallRepository->findByFloor($floor->id) as $wall) {
			array_push($xs, $wall->startX, $wall->endX);
			array_push($ys, $wall->startY, $wall->endY);
			$bulge = max($bulge, abs($wall->sagitta));
		}
		foreach ($this->roomRepository->findByFloor($floor->id) as $room) {
			foreach (JsonDecoder::decodePolygon($room->polygon) as [$x, $y]) {
				$xs[] = $x;
				$ys[] = $y;
			}
		}
		if (count($xs) === 0 || count($ys) === 0) {
			$half = self::DefaultHalfSide;

			return [[-$half, -$half], [$half, -$half], [$half, $half], [-$half, $half]];
		}
		$margin = self::DefaultMargin + $bulge;
		[$minX, $maxX] = [min($xs) - $margin, max($xs) + $margin];
		[$minY, $maxY] = [min($ys) - $margin, max($ys) + $margin];

		return [[$minX, $minY], [$maxX, $minY], [$maxX, $maxY], [$minX, $maxY]];
	}

	public function find(Floor $floor, int $id): ?Plot
	{
		return $this->plotRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		return self::cast($element)->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		return State::of(PlotDto::fromEntity(self::cast($element)));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state);
		$groundMaterialId = $fields->nullableId('groundMaterialId');
		$this->materialResolver->resolve($groundMaterialId);

		return [
			'name' => $fields->string('name', 255),
			'polygon' => PolygonValidator::validate($fields->raw('polygon'), self::CoordinateLimit),
			'groundMaterialId' => $groundMaterialId,
		];
	}

	public function create(Floor $floor, array $state): Plot
	{
		$plot = State::stamp(new Plot(floor: $floor, name: '', polygon: '[]'));
		$this->apply($plot, $state);

		return $plot;
	}

	public function update(AEntity $element, array $state): void
	{
		$plot = self::cast($element);
		$plot->updatedAt = new DateTimeImmutable();
		$this->apply($plot, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$plot = self::cast($element);
		$plot->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$plot->updatedAt = new DateTimeImmutable();
		$this->plotRepository->persist($plot);
	}

	public function label(array $state): string
	{
		return sprintf('plot "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(Plot $plot, array $state): void
	{
		$fields = new FieldReader($state);
		$plot->name = $fields->string('name', 255);
		$plot->polygon = json_encode($fields->raw('polygon'), JSON_THROW_ON_ERROR);
		$plot->groundMaterial = $this->materialResolver->resolve($fields->nullableId('groundMaterialId'));
		$this->plotRepository->persist($plot);
	}

	private static function cast(AEntity $element): Plot
	{
		assert($element instanceof Plot);

		return $element;
	}
}
