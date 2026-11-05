<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;

/**
 * What the reader takes from a DXF file: its model space flattened into lines and arcs in the drawing's units (blocks
 * expanded, object coordinate systems applied), the layers and what was counted on each.
 */
final readonly class DxfDrawing
{
	/**
	 * @param int|null $units the header's `$INSUNITS`; null when missing
	 * @param array<string, DxfLayer> $layers by name, the table's and those only entities name
	 * @param list<DxfSegment> $segments
	 * @param list<DxfArc> $arcs
	 */
	public function __construct(public ?int $units, public array $layers, public array $segments, public array $arcs)
	{
	}

	/** @param list<string> $layers */
	public function onLayers(array $layers, float $scale): self
	{
		return new self(
			$this->units,
			$this->layers,
			array_values(array_map(
				static fn (DxfSegment $segment): DxfSegment => $segment->scaled($scale),
				array_filter($this->segments, static fn (DxfSegment $segment): bool => in_array($segment->layer, $layers, true)),
			)),
			array_values(array_map(
				static fn (DxfArc $arc): DxfArc => $arc->scaled($scale),
				array_filter($this->arcs, static fn (DxfArc $arc): bool => in_array($arc->layer, $layers, true)),
			)),
		);
	}
}
