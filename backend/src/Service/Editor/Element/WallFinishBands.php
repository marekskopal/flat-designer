<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Element;

use Izbik\Model\Entity\Wall;
use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Editor\FieldReader;
use Izbik\Service\Editor\Geometry\WallLine;
use const JSON_THROW_ON_ERROR;

/**
 * Horizontal wall finish bands `{materialId, bottom, top}` (mm above the floor): a room's `wallFinishes` and a wall
 * face's `leftFinishes` / `rightFinishes`, which override the room's on that face. A face's band may line only a
 * stretch of it, `from`–`to` mm along the wall's centre line from its start (either may be left out); where none of a
 * face's bands lies and it has none without a stretch, the room's bands (or a façade's default) line it
 * (`FaceBands`).
 */
final readonly class WallFinishBands
{
	private const int MaxBands = 10;

	/** JSON schema of one band, for MCP tools. */
	public const array BandSchema = [
		'type' => 'object',
		'properties' => [
			'materialId' => ['type' => 'integer'],
			'bottom' => ['type' => 'integer'],
			'top' => ['type' => 'integer'],
			'from' => ['type' => 'integer', 'description' => 'wall faces only: mm along the wall from its start where the band begins'],
			'to' => ['type' => 'integer', 'description' => 'wall faces only: mm along the wall from its start where the band ends'],
		],
		'required' => ['materialId', 'bottom', 'top'],
	];

	public function __construct(private MaterialResolver $materialResolver)
	{
	}

	/**
	 * Checked bands of a field; an empty list stays empty. A wall face's (`$face`) may start below the floor's level,
	 * down to `-$below` (a façade's plinth over the slab's edge), and line a stretch of it (`from` / `to`); a room's
	 * start on its floor and line all its walls.
	 *
	 * @return list<array{materialId: int, bottom: int, top: int, from?: int, to?: int}>
	 */
	public function parse(mixed $bands, string $field, int $below = 0, bool $face = false): array
	{
		if (!is_array($bands) || !array_is_list($bands) || count($bands) > self::MaxBands) {
			throw EditException::invalid(sprintf('Field "%s" must be a list of at most %d bands.', $field, self::MaxBands));
		}

		$normalized = [];
		foreach ($bands as $band) {
			$fields = new FieldReader(is_array($band) ? self::object($band) : []);
			$materialId = $fields->nullableId('materialId') ?? throw EditException::invalid('Every wall finish needs a materialId.');
			$this->materialResolver->resolve($materialId);
			$bottom = $fields->int('bottom', -$below, State::MaxSize);
			$top = $fields->int('top', 1 - $below, State::MaxSize);
			if ($top <= $bottom) {
				throw EditException::invalid('A wall finish must end above where it starts (top > bottom).');
			}
			$normalized[] = ['materialId' => $materialId, 'bottom' => $bottom, 'top' => $top, ...self::stretch($fields, $face)];
		}

		return $normalized;
	}

	/** A face's bands as stored (`Wall::$leftFinishes`): null stays null (the room's), [] is kept (a bare face). */
	public static function encodeFace(mixed $bands): ?string
	{
		return $bands === null ? null : json_encode($bands, JSON_THROW_ON_ERROR);
	}

	/**
	 * A wall's face bands after its line changed to `$line`, unless the request sets them (undo, redo): turned round
	 * (`$reversed`), its faces swap, so the bands stay on the same side of the plan, and their stretches' ends are
	 * projected onto the new line, so they stay where they were on the plan as the openings do (counted from the other
	 * end of a wall turned round, an open start becoming an open end).
	 *
	 * @param list<array{materialId: int, bottom: int, top: int, from?: int, to?: int}>|null $left
	 * @param list<array{materialId: int, bottom: int, top: int, from?: int, to?: int}>|null $right
	 * @return array{list<array{materialId: int, bottom: int, top: int, from?: int, to?: int}>|null, list<array{materialId: int, bottom: int, top: int, from?: int, to?: int}>|null}
	 */
	public static function followWall(Wall $wall, ?array $left, ?array $right, WallLine $line, bool $reversed): array
	{
		if (self::encodeFace($left) !== $wall->leftFinishes || self::encodeFace($right) !== $wall->rightFinishes) {
			return [$left, $right];
		}
		$old = WallLine::fromWall($wall);
		$move = static fn (?array $bands): ?array => $bands === null
			? null
			: array_map(static fn (array $band): array => self::movedBand($band, $old, $line, $reversed), $bands);

		return $reversed ? [$move($right), $move($left)] : [$move($left), $move($right)];
	}

	/**
	 * @param array{materialId: int, bottom: int, top: int, from?: int, to?: int} $band
	 * @return array{materialId: int, bottom: int, top: int, from?: int, to?: int}
	 */
	private static function movedBand(array $band, WallLine $old, WallLine $new, bool $reversed): array
	{
		$at = static fn (int $end): int => max((int) round($new->along($old->point($end, 0.0))), 0);
		[$from, $to] = [isset($band['from']) ? $at($band['from']) : null, isset($band['to']) ? $at($band['to']) : null];
		if ($reversed) {
			[$from, $to] = [$to, $from];
		}
		$moved = ['materialId' => $band['materialId'], 'bottom' => $band['bottom'], 'top' => $band['top']];
		if ($from !== null) {
			$moved['from'] = $from;
		}
		if ($to !== null) {
			$moved['to'] = max($to, ($from ?? 0) + 1);
		}

		return $moved;
	}

	/**
	 * A face band's `from` / `to` along the wall, those given.
	 *
	 * @return array{from?: int, to?: int}
	 */
	private static function stretch(FieldReader $fields, bool $face): array
	{
		$from = $fields->nullableInt('from', 0, State::CoordinateLimit * 2);
		$to = $fields->nullableInt('to', 1, State::CoordinateLimit * 2);
		if (!$face && ($from !== null || $to !== null)) {
			throw EditException::invalid('Only a wall face\'s own finish bands line a stretch of it (from / to).');
		}
		if ($from !== null && $to !== null && $to <= $from) {
			throw EditException::invalid('A wall finish must end further along the wall than it starts (to > from).');
		}

		return [...($from === null ? [] : ['from' => $from]), ...($to === null ? [] : ['to' => $to])];
	}

	/**
	 * @param array<mixed> $value
	 * @return array<string, mixed>
	 */
	private static function object(array $value): array
	{
		$object = [];
		foreach ($value as $key => $item) {
			$object[(string) $key] = $item;
		}

		return $object;
	}
}
