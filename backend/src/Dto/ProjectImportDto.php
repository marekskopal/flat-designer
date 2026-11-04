<?php

declare(strict_types=1);

namespace Izbik\Dto;

/** A project made from a project file, with where to open it and what of the file was left out. */
final readonly class ProjectImportDto
{
	/** @param list<string> $warnings */
	public function __construct(
		public ProjectDto $project,
		/** The floor to open: the lowest one at or above ground. */
		public ?int $floorId,
		/** What the import left out (a file, model, definition or price this instance could not take), for the user. */
		public array $warnings,
	) {
	}
}
