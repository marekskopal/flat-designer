<?php

declare(strict_types=1);

namespace Izbik\Dto;

/** A layer of a DXF: what is drawn on it (blocks expanded) and the role its name suggests: `wall`, `opening` or null. */
final readonly class DxfLayerDto
{
	public function __construct(
		public string $name,
		public bool $hidden,
		public int $lines,
		public int $arcs,
		public int $inserts,
		public int $hatches,
		public int $other,
		public ?string $suggestedRole,
	) {
	}
}
