<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Request\BodyReader;
use function in_array;
use function sprintf;

/** Which layers hold the walls and the openings, the drawing's units, and how it lies on the plan. */
final readonly class DxfPlanOptions
{
	/** Furthest an offset may move the drawing (mm), as far as a plot may reach. */
	public const int MaxOffset = 1000000;

	/**
	 * @param list<string> $wallLayers
	 * @param list<string> $openingLayers
	 * @param int|null $units `$INSUNITS` code; null takes the header's
	 * @param float $rotation degrees counter-clockwise, about the drawing's origin
	 */
	public function __construct(
		public array $wallLayers,
		public array $openingLayers = [],
		public ?int $units = null,
		public float $rotation = 0.0,
		public DxfAnchorEnum $anchor = DxfAnchorEnum::Walls,
		public int $offsetX = 0,
		public int $offsetY = 0,
	) {
	}

	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): self
	{
		$units = BodyReader::optionalInt($data, 'units');
		if ($units !== null && !in_array($units, DxfUnits::choices(), true)) {
			throw EditException::invalid(sprintf('Unknown drawing units %d.', $units));
		}
		$rotation = BodyReader::optionalNumber($data, 'rotation') ?? 0.0;
		if ($rotation < -360 || $rotation > 360) {
			throw EditException::invalid('The rotation must lie between -360 and 360 degrees.');
		}
		$anchor = DxfAnchorEnum::tryFrom(BodyReader::optionalString($data, 'anchor') ?? DxfAnchorEnum::Walls->value);
		if ($anchor === null) {
			throw EditException::invalid('The anchor must be Drawing or Walls.');
		}
		$offsetX = BodyReader::optionalInt($data, 'offsetX') ?? 0;
		$offsetY = BodyReader::optionalInt($data, 'offsetY') ?? 0;
		foreach ([$offsetX, $offsetY] as $offset) {
			if ($offset < -self::MaxOffset || $offset > self::MaxOffset) {
				throw EditException::invalid(sprintf('An offset must lie within %d mm.', self::MaxOffset));
			}
		}
		$wallLayers = BodyReader::stringList($data, 'wallLayers');
		if ($wallLayers === []) {
			throw EditException::invalid('Choose at least one layer with the walls.');
		}

		return new self(
			wallLayers: $wallLayers,
			openingLayers: ($data['openingLayers'] ?? null) === null ? [] : BodyReader::stringList($data, 'openingLayers'),
			units: $units,
			rotation: $rotation,
			anchor: $anchor,
			offsetX: $offsetX,
			offsetY: $offsetY,
		);
	}
}
