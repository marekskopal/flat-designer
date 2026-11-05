<?php

declare(strict_types=1);

namespace Izbik\Dto;

/**
 * Switches and the lights they control, linked into one: a two-way pair with its lights is one circuit. `number` counts
 * them per floor (L1, L2, …).
 */
final readonly class LightingCircuitDto
{
	/**
	 * @param list<CircuitItemDto> $switches
	 * @param list<CircuitItemDto> $lights
	 */
	public function __construct(public int $number, public array $switches, public array $lights)
	{
	}
}
