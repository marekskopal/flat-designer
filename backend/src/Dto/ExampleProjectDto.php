<?php

declare(strict_types=1);

namespace Izbik\Dto;

/** A project made from an example, with where to open it. */
final readonly class ExampleProjectDto
{
	public function __construct(
		public ProjectDto $project,
		/** The floor to open: the start view's, else the lowest one at or above ground. */
		public ?int $floorId,
		/** The saved view to open it at, if the example names one. */
		public ?int $viewId,
	) {
	}
}
