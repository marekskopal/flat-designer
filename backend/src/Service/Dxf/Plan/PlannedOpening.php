<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Model\Entity\Enum\OpeningHingeEnum;
use Izbik\Model\Entity\Enum\OpeningSideEnum;
use Izbik\Model\Entity\Enum\OpeningTypeEnum;

/** An opening found in a wall, from `from` to `to` along its frame's `t` (mm). */
final readonly class PlannedOpening
{
	public function __construct(
		public float $from,
		public float $to,
		public OpeningTypeEnum $type,
		public ?OpeningHingeEnum $hinge = null,
		public ?OpeningSideEnum $side = null,
		public ?float $leafWidth = null,
	) {
	}
}
