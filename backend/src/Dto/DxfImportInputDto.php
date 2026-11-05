<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Dxf\DxfPlanOptions;
use Izbik\Service\Request\BodyReader;

/**
 * A DXF import or its preview: the project file, how to read it (`DxfPlanOptions`), and what a plan does not say: the
 * walls' height and material, the doors' height and the windows' sill and height (null: the usual defaults), and
 * whether the floor's walls make way for the drawing's.
 */
final readonly class DxfImportInputDto implements ArrayFactoryInterface
{
	public function __construct(
		public int $fileId,
		public DxfPlanOptions $options,
		public ?int $wallHeight = null,
		public ?int $materialId = null,
		public ?int $doorHeight = null,
		public ?int $windowSill = null,
		public ?int $windowHeight = null,
		public bool $replaceExisting = false,
	) {
	}

	public static function fromArray(array $data): static
	{
		return new self(
			fileId: BodyReader::int($data, 'fileId'),
			options: DxfPlanOptions::fromArray($data),
			wallHeight: BodyReader::optionalInt($data, 'wallHeight'),
			materialId: BodyReader::optionalInt($data, 'materialId'),
			doorHeight: BodyReader::optionalInt($data, 'doorHeight'),
			windowSill: BodyReader::optionalInt($data, 'windowSill'),
			windowHeight: BodyReader::optionalInt($data, 'windowHeight'),
			replaceExisting: ($data['replaceExisting'] ?? null) === null ? false : BodyReader::bool($data, 'replaceExisting'),
		);
	}
}
