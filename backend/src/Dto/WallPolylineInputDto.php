<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** A run of walls: centre-line points, whether it closes, the curve of each wall (null: straight) and the fields shared by its walls. */
final readonly class WallPolylineInputDto implements ArrayFactoryInterface
{
	/**
	 * @param list<array{int, int}> $points
	 * @param array<string, mixed> $fields
	 * @param list<int>|null $sagittas
	 */
	public function __construct(public array $points, public bool $closed, public array $fields, public ?array $sagittas = null)
	{
	}

	public static function fromArray(array $data): static
	{
		$fields = $data;
		unset($fields['points'], $fields['closed'], $fields['sagittas']);

		return new self(
			points: BodyReader::pointList($data, 'points'),
			closed: ($data['closed'] ?? null) === null ? false : BodyReader::bool($data, 'closed'),
			fields: $fields,
			sagittas: ($data['sagittas'] ?? null) === null ? null : BodyReader::intList($data, 'sagittas'),
		);
	}
}
