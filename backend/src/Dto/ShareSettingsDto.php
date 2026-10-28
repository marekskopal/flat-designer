<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\ShareCommentsEnum;
use FlatDesigner\Model\Entity\Enum\ShareViewEnum;
use FlatDesigner\Service\Request\BodyReader;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;

/**
 * What a share link shows: the view modes the guest may switch between, the one it opens in, the 3D camera
 * (only with the overview allowed; null = the default framing) and the display settings it starts with
 * (`sectionHeight` in mm, null = no wall cut; `dimensions` draws the plan's dimension lines; `night` shows the flat after
 * dark, lit by its lights; `sun` places the sun at a clock time on site, null = the default afternoon sun; `presentation`
 * renders in the presentation quality) and whether the guest may download the
 * exports (`exports`: the plan as PDF or SVG, the wall elevations, the 3D model as glTF), and whether the guest sees the
 * floor's open review comments or may also write them (`comments`), and whether the floors under the shared one are drawn
 * below it (`allFloors`), and whether the roofs show at the start (`roofs`). Stored as JSON in
 * `ShareLink::$settings`.
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
		public bool $night = false,
		public ?SunTimeDto $sun = null,
		public bool $presentation = false,
		public bool $exports = true,
		public ShareCommentsEnum $comments = ShareCommentsEnum::Hidden,
		public bool $allFloors = false,
		public bool $roofs = true,
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
			// Nor those saved before the night view.
			night: array_key_exists('night', $data) && BodyReader::bool($data, 'night'),
			sun: SunTimeDto::optional($data),
			presentation: array_key_exists('presentation', $data) && BodyReader::bool($data, 'presentation'),
			// Links saved before the exports allow them.
			exports: !array_key_exists('exports', $data) || BodyReader::bool($data, 'exports'),
			// Links saved before the comments do not show them.
			comments: array_key_exists('comments', $data) ? self::comments(
				BodyReader::string($data, 'comments'),
			) : ShareCommentsEnum::Hidden,
			// Links saved before stacked floors show their floor only.
			allFloors: array_key_exists('allFloors', $data) && BodyReader::bool($data, 'allFloors'),
			// Links saved before the roofs show them.
			roofs: !array_key_exists('roofs', $data) || BodyReader::bool($data, 'roofs'),
		);
	}

	private static function comments(string $comments): ShareCommentsEnum
	{
		return ShareCommentsEnum::tryFrom($comments)
			?? throw new InvalidRequestException(sprintf('Comments must be "hidden", "read" or "write", not "%s".', $comments), 422);
	}

	private static function view(string $view): ShareViewEnum
	{
		return ShareViewEnum::tryFrom($view) ?? throw new InvalidRequestException(sprintf('Unknown view "%s".', $view), 422);
	}
}
