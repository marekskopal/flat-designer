<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

/** A straight piece of a drawing in world coordinates, with its layer and the innermost block it came from. */
final readonly class DxfSegment
{
	public function __construct(
		public string $layer,
		public float $x1,
		public float $y1,
		public float $x2,
		public float $y2,
		public ?string $block = null,
	) {
	}

	public function scaled(float $factor): self
	{
		return new self($this->layer, $this->x1 * $factor, $this->y1 * $factor, $this->x2 * $factor, $this->y2 * $factor, $this->block);
	}
}
