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
use FlatDesigner\Service\Editor\Geometry\WallLine;

/**
 * Walls, straight or curved (`sagitta`, `WallCurve`), with optional wall finish bands per face (`leftFinishes` / `rightFinishes`, `WallFinishBands`). The state
 * leaves out the openings (they are elements of their own) but keeps their offsets as `openingOffsets` ([{id, offset}]
 * by id): moving the start point shifts the offsets so the openings stay where they are on the plan, and undo restores
 * them exactly. A deleted wall hides its openings until undo brings it back.
 */
final readonly class WallHandler implements ElementHandler
{
	public const int MinLength = 100;

	public function __construct(
		private WallRepository $wallRepository,
		private OpeningRepository $openingRepository,
		private MaterialResolver $materialResolver,
		private WallFinishBands $wallFinishBands,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Wall;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['name', 'type', 'startX', 'startY', 'endX', 'endY', 'sagitta', 'thickness', 'height', 'materialId', 'leftFinishes', 'rightFinishes'];
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
			'sagitta' => 0,
			'thickness' => 100,
			'height' => 2600,
			'materialId' => null,
			'leftFinishes' => null,
			'rightFinishes' => null,
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
			// Missing in changes logged before walls could curve.
			'sagitta' => WallCurve::read($fields),
			'thickness' => $fields->int('thickness', 10, 2000),
			'height' => $fields->int('height', 100, State::MaxSize),
			'materialId' => $materialId,
			'leftFinishes' => $this->faceFinishes($fields->raw('leftFinishes'), 'leftFinishes'),
			'rightFinishes' => $this->faceFinishes($fields->raw('rightFinishes'), 'rightFinishes'),
		];

		// Turning the wall round turns its faces round: unless the request sets the bands (undo, redo), they stay
		// on the same side of the plan, as the openings do; so does its curve unless the request sets it.
		if ($currentWall !== null && self::reverses($currentWall, $normalized)) {
			if (self::keepsFaces($currentWall, $normalized)) {
				[$normalized['leftFinishes'], $normalized['rightFinishes']] = [$normalized['rightFinishes'], $normalized['leftFinishes']];
			}
			$normalized['sagitta'] = WallCurve::turnedRound($currentWall->sagitta, $normalized['sagitta']);
		}

		$line = self::line($normalized);
		$length = (int) floor($line->length());
		if ($length < self::MinLength) {
			throw EditException::invalid(sprintf('A wall must be at least %d mm long.', self::MinLength));
		}
		WallCurve::check($line);

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

	/** Centre-line length in whole mm, along the arc of a curved wall (rounded down, so openings that fit it fit the wall). */
	public static function length(Wall $wall): int
	{
		return (int) floor(WallLine::fromWall($wall)->length());
	}

	/** @param array{startX: int, startY: int, endX: int, endY: int, sagitta: int, thickness: int, ...} $state */
	private static function line(array $state): WallLine
	{
		return new WallLine(0, $state['startX'], $state['startY'], $state['endX'], $state['endY'], $state['thickness'], $state['sagitta']);
	}

	/**
	 * Offsets of the wall's openings after the change, checked to still fit. `$requested` (undo, redo) is
	 * taken as it is when it differs from the current offsets; otherwise a moved start point shifts them so
	 * every opening keeps its place on the plan.
	 *
	 * @param array{startX: int, startY: int, endX: int, endY: int, sagitta: int, thickness: int, height: int, ...} $next
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
		$old = WallLine::fromWall($wall);
		$new = self::line($next);
		if ($offsets === $current && ($next['startX'] !== $wall->startX || $next['startY'] !== $wall->startY)) {
			foreach ($openings as $opening) {
				// Start of the opening on the plan, projected onto the new centre line (twin of `moveWallEnds()`).
				$at = $old->length() > 0 ? $old->point($opening->offset, 0.0) : [(float) $wall->startX, (float) $wall->startY];
				$offsets[$opening->id] = (int) round($new->along($at));
			}
		}

		$radius = $new->arc()['radius'] ?? null;
		foreach ($openings as $opening) {
			// Sliding and pocket doors also keep the wall their open leaf slides along; on a curved wall it has to stay shallow.
			if (!OpeningPlacement::fits($opening, $offsets[$opening->id], $length, $next['thickness'], $next['height'], $radius)) {
				throw EditException::invalid(sprintf('Opening "%s" would no longer fit the wall.', $opening->name));
			}
		}
		if (!OpeningPlacement::nichesFit($openings, $next['thickness'])) {
			throw EditException::invalid('Niches from both faces would meet inside the wall.');
		}

		return $offsets;
	}

	/**
	 * A face's own bands; null leaves the face to its room's, [] keeps it bare.
	 *
	 * @return list<array{materialId: int, bottom: int, top: int}>|null
	 */
	private function faceFinishes(mixed $bands, string $field): ?array
	{
		return $bands === null ? null : $this->wallFinishBands->parse($bands, $field);
	}

	/** @param array{startX: int, startY: int, endX: int, endY: int, ...} $next */
	private static function reverses(Wall $wall, array $next): bool
	{
		$dx = $wall->endX - $wall->startX;
		$dy = $wall->endY - $wall->startY;

		return $dx * ($next['endX'] - $next['startX']) + $dy * ($next['endY'] - $next['startY']) < 0;
	}

	/** @param array{leftFinishes: mixed, rightFinishes: mixed, ...} $next */
	private static function keepsFaces(Wall $wall, array $next): bool
	{
		return WallFinishBands::encodeFace($next['leftFinishes']) === $wall->leftFinishes
			&& WallFinishBands::encodeFace($next['rightFinishes']) === $wall->rightFinishes;
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
		$wall->sagitta = $fields->int('sagitta', -State::CoordinateLimit, State::CoordinateLimit);
		$wall->thickness = $fields->int('thickness', 10, 2000);
		$wall->height = $fields->int('height', 100, State::MaxSize);
		$wall->material = $this->materialResolver->resolve($fields->nullableId('materialId'));
		$wall->leftFinishes = WallFinishBands::encodeFace($fields->raw('leftFinishes'));
		$wall->rightFinishes = WallFinishBands::encodeFace($fields->raw('rightFinishes'));
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
