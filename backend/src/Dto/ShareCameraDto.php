<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** Where the 3D view of a share link starts: the camera position and the point it looks at. */
final readonly class ShareCameraDto implements ArrayFactoryInterface
{
	public function __construct(public CameraPointDto $position, public CameraPointDto $target)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			position: CameraPointDto::fromArray(BodyReader::object($data, 'position')),
			target: CameraPointDto::fromArray(BodyReader::object($data, 'target')),
		);
	}
}
