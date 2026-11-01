<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\SectionLineDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\SectionLine;
use FlatDesigner\Model\Repository\SectionLineRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;

/**
 * Section lines (FD-94): cuts through the building drawn on a floor's plan, looking to the left of start → end. A new
 * one runs west to east through the middle of the floor's walls, a metre past them, looking north, named by the next
 * letter not taken on the floor.
 */
final readonly class SectionLineHandler implements ElementHandler
{
	/** mm, the shortest line. */
	public const int MinLength = 500;

	/** mm, the depth range drawn beyond the cut. */
	public const int MinDepth = 100;
	public const int MaxDepth = 100000;

	/** mm a new line reaches past the walls. */
	private const int Overrun = 1000;

	/** mm a copy lies from its original, the way it looks. */
	private const int CopyShift = 1000;

	public function __construct(private SectionLineRepository $lineRepository, private WallRepository $wallRepository)
	{
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::SectionLine;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['name', 'startX', 'startY', 'endX', 'endY', 'depth'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(Floor $floor, array $fields): array
	{
		$xs = [];
		$ys = [];
		foreach ($this->wallRepository->findByFloor($floor->id) as $wall) {
			array_push($xs, $wall->startX, $wall->endX);
			array_push($ys, $wall->startY, $wall->endY);
		}
		[$minX, $maxX, $y] = $xs === []
			? [-5000, 5000, 0]
			: [min($xs) - self::Overrun, max($xs) + self::Overrun, intdiv(min($ys) + max($ys), 2)];

		return ['name' => $this->nextName($floor), 'startX' => $minX, 'startY' => $y, 'endX' => $maxX, 'endY' => $y, 'depth' => null];
	}

	public function find(Floor $floor, int $id): ?SectionLine
	{
		return $this->lineRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		return self::cast($element)->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		return State::of(SectionLineDto::fromEntity(self::cast($element)));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state);
		$limit = State::CoordinateLimit;
		$normalized = [
			'name' => $fields->string('name', 255),
			'startX' => $fields->int('startX', -$limit, $limit),
			'startY' => $fields->int('startY', -$limit, $limit),
			'endX' => $fields->int('endX', -$limit, $limit),
			'endY' => $fields->int('endY', -$limit, $limit),
			'depth' => $fields->nullableInt('depth', self::MinDepth, self::MaxDepth),
		];
		$length = hypot($normalized['endX'] - $normalized['startX'], $normalized['endY'] - $normalized['startY']);
		if ($length < self::MinLength) {
			throw EditException::invalid(sprintf('A section line must be at least %d mm long.', self::MinLength));
		}

		return $normalized;
	}

	public function create(Floor $floor, array $state): SectionLine
	{
		$line = State::stamp(new SectionLine(floor: $floor, name: '', startX: 0, startY: 0, endX: 0, endY: 0));
		$this->apply($line, $state);

		return $line;
	}

	public function update(AEntity $element, array $state): void
	{
		$line = self::cast($element);
		$line->updatedAt = new DateTimeImmutable();
		$this->apply($line, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$line = self::cast($element);
		$line->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$line->updatedAt = new DateTimeImmutable();
		$this->lineRepository->persist($line);
	}

	public function label(array $state): string
	{
		return sprintf('section line "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/**
	 * A copy's fields: the line moved `CopyShift` the way it looks (to its left), without its name, so it takes the next
	 * free one.
	 *
	 * @param array<string, mixed> $state the original's fields
	 * @return array<string, mixed>
	 */
	public static function copied(array $state): array
	{
		$fields = new FieldReader($state);
		$limit = State::CoordinateLimit;
		[$x0, $y0] = [$fields->int('startX', -$limit, $limit), $fields->int('startY', -$limit, $limit)];
		[$x1, $y1] = [$fields->int('endX', -$limit, $limit), $fields->int('endY', -$limit, $limit)];
		$length = max(hypot($x1 - $x0, $y1 - $y0), 1.0);
		$dx = (int) round(-($y1 - $y0) / $length * self::CopyShift);
		$dy = (int) round(($x1 - $x0) / $length * self::CopyShift);
		unset($state['name']);

		return ['startX' => $x0 + $dx, 'startY' => $y0 + $dy, 'endX' => $x1 + $dx, 'endY' => $y1 + $dy] + $state;
	}

	/** The first of A, B, …, Z, AA, AB, … not taken by a line of the floor. */
	private function nextName(Floor $floor): string
	{
		$taken = array_map(fn (SectionLine $line): string => $line->name, $this->lineRepository->findByFloor($floor->id));
		$letters = range('A', 'Z');
		$names = $letters;
		foreach ($letters as $first) {
			foreach ($letters as $second) {
				$names[] = $first . $second;
			}
		}
		foreach ($names as $name) {
			if (!in_array($name, $taken, true)) {
				return $name;
			}
		}

		return 'Section';
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(SectionLine $line, array $state): void
	{
		$fields = new FieldReader($state);
		$limit = State::CoordinateLimit;
		$line->name = $fields->string('name', 255);
		$line->startX = $fields->int('startX', -$limit, $limit);
		$line->startY = $fields->int('startY', -$limit, $limit);
		$line->endX = $fields->int('endX', -$limit, $limit);
		$line->endY = $fields->int('endY', -$limit, $limit);
		$line->depth = $fields->nullableInt('depth', self::MinDepth, self::MaxDepth);
		$this->lineRepository->persist($line);
	}

	private static function cast(AEntity $element): SectionLine
	{
		assert($element instanceof SectionLine);

		return $element;
	}
}
