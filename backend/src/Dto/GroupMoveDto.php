<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** Shift of a group in mm and its turn in degrees counter-clockwise (about the group's footprint centre). */
final readonly class GroupMoveDto implements ArrayFactoryInterface
{
	public function __construct(public int $dx, public int $dy, public int $rotation)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			dx: BodyReader::optionalInt($data, 'dx') ?? 0,
			dy: BodyReader::optionalInt($data, 'dy') ?? 0,
			rotation: BodyReader::optionalInt($data, 'rotation') ?? 0,
		);
	}
}
