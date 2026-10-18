<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use JsonSerializable;

/**
 * One part of an evaluated definition, in whole mm with the origin at the left-bottom-back corner of the item (x along
 * the width, y up, z towards the front). `index` is the repeat index (null for a part without `repeat`); `props` are the
 * part's other keys as written (type-specific: grain, edges, handle, …).
 */
final readonly class ResolvedPart implements JsonSerializable
{
	/** @param array<string, mixed> $props */
	public function __construct(
		public string $name,
		public string $type,
		public ?int $index,
		public int $x,
		public int $y,
		public int $z,
		public int $w,
		public int $h,
		public int $d,
		public ?string $material,
		public array $props,
	) {
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array
	{
		return [
			'name' => $this->name,
			'type' => $this->type,
			'index' => $this->index,
			'x' => $this->x,
			'y' => $this->y,
			'z' => $this->z,
			'w' => $this->w,
			'h' => $this->h,
			'd' => $this->d,
			'material' => $this->material,
			'props' => $this->props,
		];
	}
}
