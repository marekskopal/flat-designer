<?php

declare(strict_types=1);

namespace Izbik\Dto;

/**
 * The lighting circuits of a floor (switch items' `controls`, shown items only), the lights no switch controls and
 * the switches that control no light. A switch controls lights on its own floor only.
 */
final readonly class LightingDto
{
	/**
	 * @param list<LightingCircuitDto> $circuits
	 * @param list<CircuitItemDto> $unswitched
	 * @param list<CircuitItemDto> $unlinked
	 */
	public function __construct(public array $circuits, public array $unswitched, public array $unlinked)
	{
	}
}
