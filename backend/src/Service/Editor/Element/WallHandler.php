<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;

/**
 * Walls. The state leaves out the openings (they are elements of their own) but keeps their offsets as
 * `openingOffsets` ([{id, offset}] by id): moving the start point shifts the offsets so the openings stay
 * where they are on the plan, and undo restores them exactly. A deleted wall hides its openings until undo
 * brings it back.
 */
final readonly class WallHandler implements ElementHandler
{
	public const int MinLength = 100;

	public function __construct(
		private WallRepository $wallRepository,
		private OpeningRepository $openingRepository,
		private MaterialResolver $materialResolver,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Wall;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['name', 'type', 'startX', 'startY', 'endX', 'endY', 'thickness', 'height', 'materialId'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return [...$this->fields(), 'openingOffsets'];
	}

	/** @return array<string, mixed> */
	public function defaults(Floor $floor, array $fields): array
	{
		return [
			'name' => 'Wall',
			'type' => WallTypeEnum::Interior->value,
			'startX' => 0,
			'startY' => 0,
			'endX' => 3000,
			'endY' => 0,
			'thickness' => 100,
			'height' => 2600,
			'materialId' => null,
		];
	}

	public function find(Floor $floor, int $id): ?Wall
	{
		return $this->wallRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		$wall = self::cast($element);
		return $wall->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		$wall = self::cast($element);
		$state = State::of(WallDto::fromEntity($wall, []));
		unset($state['openings']);
		$offsets = [];
		foreach ($this->openingRepository->findByWalls([$wall->id]) as $opening) {
			$offsets[$opening->id] = $opening->offset;
		}
		$state['openingOffsets'] = self::offsetList($offsets);

		return $state;
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$currentWall = $current === null ? null : self::cast($current);
		$fields = new FieldReader($state);
		$materialId = $fields->nullableId('materialId');
		$this->materialResolver->resolve($materialId);

		$normalized = [
			'name' => $fields->string('name', 255),
			'type' => $fields->enum('type', WallTypeEnum::class)->value,
			'startX' => $fields->int('startX', -State::CoordinateLimit, State::CoordinateLimit),
			'startY' => $fields->int('startY', -State::CoordinateLimit, State::CoordinateLimit),
			'endX' => $fields->int('endX', -State::CoordinateLimit, State::CoordinateLimit),
			'endY' => $fields->int('endY', -State::CoordinateLimit, State::CoordinateLimit),
			'thickness' => $fields->int('thickness', 10, 2000),
			'height' => $fields->int('height', 100, State::MaxSize),
			'materialId' => $materialId,
		];

		$length = self::length($normalized['startX'], $normalized['startY'], $normalized['endX'], $normalized['endY']);
		if ($length < self::MinLength) {
			throw EditException::invalid(sprintf('A wall must be at least %d mm long.', self::MinLength));
		}

		$normalized['openingOffsets'] = $currentWall === null ? [] : self::offsetList(
			$this->openingOffsets($currentWall, $normalized, $length, $fields->raw('openingOffsets')),
		);

		return $normalized;
	}

	public function create(Floor $floor, array $state): Wall
	{
		$wall = State::stamp(new Wall(
			floor: $floor,
			name: '',
			type: WallTypeEnum::Interior,
			startX: 0,
			startY: 0,
			endX: 0,
			endY: 0,
			thickness: 0,
			height: 0,
			material: null,
		));
		$this->apply($wall, $state);

		return $wall;
	}

	public function update(AEntity $element, array $state): void
	{
		$wall = self::cast($element);
		$wall->updatedAt = new DateTimeImmutable();
		$this->apply($wall, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$wall = self::cast($element);
		$wall->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$wall->updatedAt = new DateTimeImmutable();
		$this->wallRepository->persist($wall);
	}

	public function label(array $state): string
	{
		return sprintf('wall "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** Centre-line length in whole mm (rounded down, so openings that fit it fit the wall). */
	public static function length(int $startX, int $startY, int $endX, int $endY): int
	{
		return (int) floor(hypot($endX - $startX, $endY - $startY));
	}

	/**
	 * Offsets of the wall's openings after the change, checked to still fit. `$requested` (undo, redo) is
	 * taken as it is when it differs from the current offsets; otherwise a moved start point shifts them so
	 * every opening keeps its place on the plan.
	 *
	 * @param array{startX: int, startY: int, endX: int, endY: int, thickness: int, height: int, ...} $next
	 * @return array<int, int> offset by opening id
	 */
	private function openingOffsets(Wall $wall, array $next, int $length, mixed $requested): array
	{
		$openings = $this->openingRepository->findByWalls([$wall->id]);
		$current = [];
		foreach ($openings as $opening) {
			$current[$opening->id] = $opening->offset;
		}

		$offsets = self::parseOffsets($requested, $current) ?? $current;
		if ($offsets === $current && ($next['startX'] !== $wall->startX || $next['startY'] !== $wall->startY)) {
			$oldLength = hypot($wall->endX - $wall->startX, $wall->endY - $wall->startY);
			$newLength = hypot($next['endX'] - $next['startX'], $next['endY'] - $next['startY']);
			foreach ($openings as $opening) {
				// Start of the opening on the plan, projected onto the new centre line.
				$t = $oldLength > 0 ? $opening->offset / $oldLength : 0.0;
				$x = $wall->startX + ($wall->endX - $wall->startX) * $t - $next['startX'];
				$y = $wall->startY + ($wall->endY - $wall->startY) * $t - $next['startY'];
				$offsets[$opening->id] = (int) round(
					($x * ($next['endX'] - $next['startX']) + $y * ($next['endY'] - $next['startY'])) / $newLength,
				);
			}
		}

		foreach ($openings as $opening) {
			// Sliding and pocket doors also keep the wall their open leaf slides along.
			if (!OpeningPlacement::fits($opening, $offsets[$opening->id], $length, $next['thickness'], $next['height'])) {
				throw EditException::invalid(sprintf('Opening "%s" would no longer fit the wall.', $opening->name));
			}
		}
		if (!OpeningPlacement::nichesFit($openings, $next['thickness'])) {
			throw EditException::invalid('Niches from both faces would meet inside the wall.');
		}

		return $offsets;
	}

	/**
	 * Requested offsets when they name exactly the wall's openings, otherwise null (ignored).
	 *
	 * @param array<int, int> $current
	 * @return array<int, int>|null
	 */
	private static function parseOffsets(mixed $requested, array $current): ?array
	{
		if (!is_array($requested) || count($requested) !== count($current)) {
			return null;
		}
		$offsets = [];
		foreach ($requested as $entry) {
			if (!is_array($entry) || !is_int($entry['id'] ?? null) || !is_int($entry['offset'] ?? null) || !isset($current[$entry['id']])) {
				return null;
			}
			$offsets[$entry['id']] = $entry['offset'];
		}

		return count($offsets) === count($current) ? $offsets : null;
	}

	/**
	 * @param array<int, int> $offsets
	 * @return list<array{id: int, offset: int}>
	 */
	private static function offsetList(array $offsets): array
	{
		ksort($offsets);
		$list = [];
		foreach ($offsets as $id => $offset) {
			$list[] = ['id' => $id, 'offset' => $offset];
		}

		return $list;
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(Wall $wall, array $state): void
	{
		$fields = new FieldReader($state);
		$wall->name = $fields->string('name', 255);
		$wall->type = $fields->enum('type', WallTypeEnum::class);
		$wall->startX = $fields->int('startX', -State::CoordinateLimit, State::CoordinateLimit);
		$wall->startY = $fields->int('startY', -State::CoordinateLimit, State::CoordinateLimit);
		$wall->endX = $fields->int('endX', -State::CoordinateLimit, State::CoordinateLimit);
		$wall->endY = $fields->int('endY', -State::CoordinateLimit, State::CoordinateLimit);
		$wall->thickness = $fields->int('thickness', 10, 2000);
		$wall->height = $fields->int('height', 100, State::MaxSize);
		$wall->material = $this->materialResolver->resolve($fields->nullableId('materialId'));
		$this->wallRepository->persist($wall);

		$offsets = $fields->raw('openingOffsets');
		if (!is_array($offsets) || $offsets === []) {
			return;
		}
		$byId = array_column($offsets, 'offset', 'id');
		foreach ($this->openingRepository->findByWalls([$wall->id]) as $opening) {
			$offset = $byId[$opening->id] ?? $opening->offset;
			if (!is_int($offset) || $offset === $opening->offset) {
				continue;
			}

			$opening->offset = $offset;
			$opening->updatedAt = new DateTimeImmutable();
			$this->openingRepository->persist($opening);
		}
	}

	private static function cast(AEntity $element): Wall
	{
		assert($element instanceof Wall);

		return $element;
	}
}
