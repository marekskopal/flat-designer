<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** Body of project create and update; update replaces all fields (the site fields and the façade left out are 0 / none). */
final readonly class ProjectInputDto implements ArrayFactoryInterface
{
	public function __construct(
		public string $name,
		public ?string $address,
		public ?string $description,
		/** Degrees clockwise from plan north (+y) to true north. */
		public int $northAngle = 0,
		public ?float $latitude = null,
		public ?float $longitude = null,
		public ?string $timeZone = null,
		/** The default façade material (FD-88). */
		public ?int $exteriorMaterialId = null,
	) {
	}

	public static function fromArray(array $data): static
	{
		return new self(
			name: BodyReader::string($data, 'name'),
			address: BodyReader::optionalString($data, 'address'),
			description: BodyReader::optionalString($data, 'description'),
			northAngle: BodyReader::optionalInt($data, 'northAngle') ?? 0,
			latitude: BodyReader::optionalNumber($data, 'latitude'),
			longitude: BodyReader::optionalNumber($data, 'longitude'),
			timeZone: BodyReader::optionalString($data, 'timeZone'),
			exteriorMaterialId: BodyReader::optionalInt($data, 'exteriorMaterialId'),
		);
	}
}
