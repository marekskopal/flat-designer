<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** A room to outline around the point (x, y), with any other room fields (name, code, type, …). */
final readonly class RoomDetectInputDto implements ArrayFactoryInterface
{
	/** @param array<string, mixed> $fields */
	public function __construct(public int $x, public int $y, public array $fields)
	{
	}

	public static function fromArray(array $data): static
	{
		$fields = $data;
		unset($fields['x'], $fields['y']);

		return new self(BodyReader::int($data, 'x'), BodyReader::int($data, 'y'), $fields);
	}
}
