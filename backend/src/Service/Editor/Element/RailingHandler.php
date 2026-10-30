<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\RailingDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\HandrailProfileEnum;
use FlatDesigner\Model\Entity\Enum\RailingStyleEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Railing;
use FlatDesigner\Model\Repository\RailingRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use const JSON_THROW_ON_ERROR;

/**
 * Railings (FD-90): balustrades along a polyline, on a void's or gallery's edge, a terrace or a retaining wall. Only the
 * fields are checked; the posts and the infill are the viewer's (`viewer/engine/railings.ts`).
 */
final readonly class RailingHandler implements ElementHandler
{
	/** mm, from its base to the top of the handrail. */
	public const int DefaultHeight = 1000;

	private const int MinHeight = 300;

	private const int MaxHeight = 2000;

	/** How far the base may stand above or below the floor's level, mm (as a plot's ground). */
	private const int MaxBaseZ = 10000;

	/** mm, the balusters' gap or the rods' pitch. */
	private const int MinSpacing = 20;

	private const int MaxSpacing = 500;

	/** mm between posts. */
	public const int DefaultPostSpacing = 1200;

	private const int MinPostSpacing = 300;

	private const int MaxPostSpacing = 3000;

	private const int MaxPoints = 200;

	/** mm, the shortest stretch between two points. */
	private const int MinStretch = 10;

	public function __construct(private RailingRepository $railingRepository, private MaterialResolver $materialResolver)
	{
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Railing;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['name', 'points', 'height', 'baseZ', 'style', 'spacing', 'postSpacing', 'handrail', 'materialId', 'infillMaterialId'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(Floor $floor, array $fields): array
	{
		return [
			'name' => 'Railing',
			'height' => self::DefaultHeight,
			'baseZ' => 0,
			'style' => RailingStyleEnum::Balusters->value,
			'spacing' => null,
			'postSpacing' => self::DefaultPostSpacing,
			'handrail' => HandrailProfileEnum::Round->value,
			'materialId' => null,
			'infillMaterialId' => null,
		];
	}

	public function find(Floor $floor, int $id): ?Railing
	{
		return $this->railingRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		return self::cast($element)->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		return State::of(RailingDto::fromEntity(self::cast($element)));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state);
		$materialId = $fields->nullableId('materialId');
		$infillMaterialId = $fields->nullableId('infillMaterialId');
		$this->materialResolver->resolve($materialId);
		$this->materialResolver->resolve($infillMaterialId);

		return [
			'name' => $fields->string('name', 255),
			'points' => self::points($fields->raw('points')),
			'height' => $fields->int('height', self::MinHeight, self::MaxHeight),
			'baseZ' => $fields->int('baseZ', -self::MaxBaseZ, self::MaxBaseZ),
			'style' => $fields->enum('style', RailingStyleEnum::class)->value,
			'spacing' => $fields->nullableInt('spacing', self::MinSpacing, self::MaxSpacing),
			'postSpacing' => $fields->int('postSpacing', self::MinPostSpacing, self::MaxPostSpacing),
			'handrail' => $fields->enum('handrail', HandrailProfileEnum::class)->value,
			'materialId' => $materialId,
			'infillMaterialId' => $infillMaterialId,
		];
	}

	public function create(Floor $floor, array $state): Railing
	{
		$railing = State::stamp(new Railing(
			floor: $floor,
			name: '',
			points: '[]',
			height: self::DefaultHeight,
			style: RailingStyleEnum::Balusters,
			postSpacing: self::DefaultPostSpacing,
			handrail: HandrailProfileEnum::Round,
		));
		$this->apply($railing, $state);

		return $railing;
	}

	public function update(AEntity $element, array $state): void
	{
		$railing = self::cast($element);
		$railing->updatedAt = new DateTimeImmutable();
		$this->apply($railing, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$railing = self::cast($element);
		$railing->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$railing->updatedAt = new DateTimeImmutable();
		$this->railingRepository->persist($railing);
	}

	public function label(array $state): string
	{
		return sprintf('railing "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/**
	 * The polyline: 2 to `MaxPoints` whole-mm points within the floor's coordinates, each `MinStretch` from the one before;
	 * the last may repeat the first (a railing round a void).
	 *
	 * @return list<array{int, int}>
	 */
	public static function points(mixed $points): array
	{
		if (!is_array($points) || !array_is_list($points) || count($points) < 2 || count($points) > self::MaxPoints) {
			throw EditException::invalid(sprintf('Field "points" must be a list of 2 to %d [x, y] points.', self::MaxPoints));
		}
		$result = [];
		foreach ($points as $point) {
			if (
				!is_array($point)
				|| count($point) !== 2
				|| !is_int($point[0] ?? null)
				|| !is_int($point[1] ?? null)
				|| abs($point[0]) > State::CoordinateLimit
				|| abs($point[1]) > State::CoordinateLimit
			) {
				throw EditException::invalid(sprintf('Every point must be [x, y] in whole mm within ±%d.', State::CoordinateLimit));
			}
			$previous = $result[count($result) - 1] ?? null;
			if ($previous !== null && hypot($point[0] - $previous[0], $point[1] - $previous[1]) < self::MinStretch) {
				throw EditException::invalid(sprintf('The points of a railing must be at least %d mm apart.', self::MinStretch));
			}
			$result[] = [$point[0], $point[1]];
		}

		return $result;
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(Railing $railing, array $state): void
	{
		$fields = new FieldReader($state);
		$railing->name = $fields->string('name', 255);
		$railing->points = json_encode($fields->raw('points'), JSON_THROW_ON_ERROR);
		$railing->height = $fields->int('height', self::MinHeight, self::MaxHeight);
		$railing->baseZ = $fields->int('baseZ', -self::MaxBaseZ, self::MaxBaseZ);
		$railing->style = $fields->enum('style', RailingStyleEnum::class);
		$railing->spacing = $fields->nullableInt('spacing', self::MinSpacing, self::MaxSpacing);
		$railing->postSpacing = $fields->int('postSpacing', self::MinPostSpacing, self::MaxPostSpacing);
		$railing->handrail = $fields->enum('handrail', HandrailProfileEnum::class);
		$railing->material = $this->materialResolver->resolve($fields->nullableId('materialId'));
		$railing->infillMaterial = $this->materialResolver->resolve($fields->nullableId('infillMaterialId'));
		$this->railingRepository->persist($railing);
	}

	private static function cast(AEntity $element): Railing
	{
		assert($element instanceof Railing);

		return $element;
	}
}
