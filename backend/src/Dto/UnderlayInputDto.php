<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/**
 * Body of setting a floor's underlay: the image file and its placement, either given (`x`, `y`, `scale`, `rotation`;
 * left out: kept, or the defaults for a new underlay) or calibrated from two `points` whose pixel and plan positions
 * are known.
 */
final readonly class UnderlayInputDto implements ArrayFactoryInterface
{
	/** @param list<array{px: float, py: float, x: float, y: float}>|null $points */
	public function __construct(
		public int $fileId,
		public ?int $x = null,
		public ?int $y = null,
		public ?float $scale = null,
		public ?float $rotation = null,
		public ?float $opacity = null,
		public ?array $points = null,
	) {
	}

	public static function fromArray(array $data): static
	{
		$points = null;
		if (($data['points'] ?? null) !== null) {
			$points = array_map(
				static fn (array $point): array => [
					'px' => BodyReader::number($point, 'px'),
					'py' => BodyReader::number($point, 'py'),
					'x' => BodyReader::number($point, 'x'),
					'y' => BodyReader::number($point, 'y'),
				],
				BodyReader::objectList($data, 'points'),
			);
		}

		return new self(
			fileId: BodyReader::int($data, 'fileId'),
			x: BodyReader::optionalInt($data, 'x'),
			y: BodyReader::optionalInt($data, 'y'),
			scale: BodyReader::optionalNumber($data, 'scale'),
			rotation: BodyReader::optionalNumber($data, 'rotation'),
			opacity: BodyReader::optionalNumber($data, 'opacity'),
			points: $points,
		);
	}
}
