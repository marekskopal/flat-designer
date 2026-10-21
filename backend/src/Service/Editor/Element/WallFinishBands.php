<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use const JSON_THROW_ON_ERROR;

/**
 * Horizontal wall finish bands `{materialId, bottom, top}` (mm above the floor): a room's `wallFinishes` and a wall
 * face's `leftFinishes` / `rightFinishes`, which override the room's on that face.
 */
final readonly class WallFinishBands
{
	private const int MaxBands = 10;

	/** JSON schema of one band, for MCP tools. */
	public const array BandSchema = [
		'type' => 'object',
		'properties' => ['materialId' => ['type' => 'integer'], 'bottom' => ['type' => 'integer'], 'top' => ['type' => 'integer']],
		'required' => ['materialId', 'bottom', 'top'],
	];

	public function __construct(private MaterialResolver $materialResolver)
	{
	}

	/**
	 * Checked bands of a field; an empty list stays empty.
	 *
	 * @return list<array{materialId: int, bottom: int, top: int}>
	 */
	public function parse(mixed $bands, string $field): array
	{
		if (!is_array($bands) || !array_is_list($bands) || count($bands) > self::MaxBands) {
			throw EditException::invalid(sprintf('Field "%s" must be a list of at most %d bands.', $field, self::MaxBands));
		}

		$normalized = [];
		foreach ($bands as $band) {
			$fields = new FieldReader(is_array($band) ? self::object($band) : []);
			$materialId = $fields->nullableId('materialId') ?? throw EditException::invalid('Every wall finish needs a materialId.');
			$this->materialResolver->resolve($materialId);
			$bottom = $fields->int('bottom', 0, State::MaxSize);
			$top = $fields->int('top', 1, State::MaxSize);
			if ($top <= $bottom) {
				throw EditException::invalid('A wall finish must end above where it starts (top > bottom).');
			}
			$normalized[] = ['materialId' => $materialId, 'bottom' => $bottom, 'top' => $top];
		}

		return $normalized;
	}

	/** A face's bands as stored (`Wall::$leftFinishes`): null stays null (the room's), [] is kept (a bare face). */
	public static function encodeFace(mixed $bands): ?string
	{
		return $bands === null ? null : json_encode($bands, JSON_THROW_ON_ERROR);
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
