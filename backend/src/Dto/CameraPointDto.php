<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** A camera point in plan millimetres: x east, y north, z the elevation above the floor. */
final readonly class CameraPointDto implements ArrayFactoryInterface
{
	public function __construct(public int $x, public int $y, public int $z)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(x: BodyReader::int($data, 'x'), y: BodyReader::int($data, 'y'), z: BodyReader::int($data, 'z'));
	}
}
