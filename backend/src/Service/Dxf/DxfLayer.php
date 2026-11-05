<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

/** A layer of the drawing with what the reader counted on it (blocks expanded). */
final class DxfLayer
{
	public int $lines = 0;

	public int $arcs = 0;

	public int $inserts = 0;

	public int $hatches = 0;

	/** Entities the import does not read: texts, dimensions, splines, 3D… */
	public int $other = 0;

	public function __construct(public readonly string $name, public bool $hidden = false)
	{
	}
}
