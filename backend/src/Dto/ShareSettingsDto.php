<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\ShareViewEnum;
use FlatDesigner\Service\Request\BodyReader;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;

/**
 * What a share link shows: the view modes the guest may switch between, the one it opens in, the 3D camera
 * (only with the overview allowed; null = the default framing) and the display settings it starts with
 * (`sectionHeight` in mm, null = no wall cut; `dimensions` draws the plan's dimension lines). Stored as JSON in `ShareLink::$settings`.
 */
final readonly class ShareSettingsDto implements ArrayFactoryInterface
{
	/** @param list<ShareViewEnum> $views */
	public function __construct(
		public array $views,
		public ShareViewEnum $initialView,
		public ?ShareCameraDto $camera,
		public bool $ceilings,
		public bool $labels,
		public ?int $sectionHeight,
		public bool $dimensions = false,
	) {
	}

	public static function fromArray(array $data): static
	{
		$camera = BodyReader::optionalObject($data, 'camera');

		return new self(
			views: array_map(self::view(...), BodyReader::stringList($data, 'views')),
			initialView: self::view(BodyReader::string($data, 'initialView')),
			camera: $camera === null ? null : ShareCameraDto::fromArray($camera),
			ceilings: BodyReader::bool($data, 'ceilings'),
			labels: BodyReader::bool($data, 'labels'),
			sectionHeight: BodyReader::optionalInt($data, 'sectionHeight'),
			// Links saved before dimension lines existed have no flag.
			dimensions: array_key_exists('dimensions', $data) && BodyReader::bool($data, 'dimensions'),
		);
	}

	private static function view(string $view): ShareViewEnum
	{
		return ShareViewEnum::tryFrom($view) ?? throw new InvalidRequestException(sprintf('Unknown view "%s".', $view), 422);
	}
}
