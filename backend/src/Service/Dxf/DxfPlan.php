<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

use JsonSerializable;

/**
 * The walls and openings read from a drawing, placed on the plan (whole mm), before anything is written: the editor's
 * preview and the import's input.
 *
 * @phpstan-type PlanWall array{startX: int, startY: int, endX: int, endY: int, sagitta: int, thickness: int}
 * @phpstan-type PlanOpening array{wall: int, type: string, offset: int, width: int, leafWidth: int|null, hinge: string|null, side: string|null}
 */
final readonly class DxfPlan implements JsonSerializable
{
	/**
	 * @param int $units the `$INSUNITS` code the drawing was read in
	 * @param list<PlanWall> $walls
	 * @param list<PlanOpening> $openings `wall` is the index into `$walls`
	 * @param list<array{int, int, int, int}> $unpaired wall-layer lines no wall took (x1, y1, x2, y2), at most `MaxUnpaired`
	 * @param list<string> $warnings
	 */
	public function __construct(
		public int $units,
		public array $walls,
		public array $openings,
		public array $unpaired,
		public array $warnings,
	)
	{
	}

	/**
	 * @return array{
	 *     units: int,
	 *     walls: list<PlanWall>,
	 *     openings: list<PlanOpening>,
	 *     unpaired: list<array{int, int, int, int}>,
	 *     warnings: list<string>,
	 * }
	 */
	public function jsonSerialize(): array
	{
		return [
			'units' => $this->units,
			'walls' => $this->walls,
			'openings' => $this->openings,
			'unpaired' => $this->unpaired,
			'warnings' => $this->warnings,
		];
	}
}
