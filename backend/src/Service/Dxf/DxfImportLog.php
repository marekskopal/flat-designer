<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

/** What an import made of the openings and what it left out. */
final class DxfImportLog
{
	public int $openings = 0;

	/** @param list<string> $warnings */
	public function __construct(public array $warnings)
	{
	}
}
