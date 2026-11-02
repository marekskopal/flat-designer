<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Request\BodyReader;
use Izbik\Service\Request\Exception\InvalidRequestException;

/**
 * A 3D camera of a share link or a saved view: its position, the point it looks at and the lens, a focal length in mm
 * on 35 mm film across the view's longer side (null: the viewer's standard 45° vertical field of view).
 */
final readonly class ShareCameraDto implements ArrayFactoryInterface
{
	public const int MinFocalLength = 10;
	public const int MaxFocalLength = 200;

	public function __construct(public CameraPointDto $position, public CameraPointDto $target, public ?int $focalLength = null)
	{
		if ($focalLength !== null && ($focalLength < self::MinFocalLength || $focalLength > self::MaxFocalLength)) {
			throw new InvalidRequestException(
				sprintf('Camera focal length must be %d–%d mm.', self::MinFocalLength, self::MaxFocalLength),
				422,
			);
		}
	}

	public static function fromArray(array $data): static
	{
		return new self(
			position: CameraPointDto::fromArray(BodyReader::object($data, 'position')),
			target: CameraPointDto::fromArray(BodyReader::object($data, 'target')),
			focalLength: BodyReader::optionalInt($data, 'focalLength'),
		);
	}
}
