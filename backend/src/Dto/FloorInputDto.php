<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/**
 * Body of floor create and update; update replaces the name and level. A missing `elevation` or `slabThickness` is
 * computed on create (`FloorStack`) and kept on update.
 */
final readonly class FloorInputDto implements ArrayFactoryInterface
{
	public function __construct(public string $name, public int $level, public ?int $elevation = null, public ?int $slabThickness = null)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			name: BodyReader::string($data, 'name'),
			level: BodyReader::int($data, 'level'),
			elevation: BodyReader::optionalInt($data, 'elevation'),
			slabThickness: BodyReader::optionalInt($data, 'slabThickness'),
		);
	}
}
