<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** A version with the floors stored in its snapshot, for browsing it read-only. */
final readonly class ProjectVersionDetailDto
{
	/** @param list<FloorDto> $floors */
	public function __construct(public ProjectVersionDto $version, public array $floors)
	{
	}
}
