<?php

declare(strict_types=1);

namespace Izbik\Dto;

/** An imported drawing: the edit (one batch), how many walls and openings it made, and what was left out and why. */
final readonly class DxfImportResultDto
{
	/** @param list<string> $warnings */
	public function __construct(public EditResultDto $result, public int $walls, public int $openings, public array $warnings)
	{
	}
}
