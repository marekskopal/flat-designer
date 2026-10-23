<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Project;

/**
 * Where the project's building stands, for the viewer's sun: true north `northAngle` degrees clockwise from plan north
 * (+y), the place in degrees (north and east positive; both null when not set) and the IANA time zone its clock time is
 * read in (null: local solar time).
 */
final readonly class LocationDto
{
	public function __construct(public int $northAngle, public ?float $latitude, public ?float $longitude, public ?string $timeZone,)
	{
	}

	public static function fromEntity(Project $project): self
	{
		return new self(
			northAngle: $project->northAngle,
			latitude: $project->latitude,
			longitude: $project->longitude,
			timeZone: $project->timeZone,
		);
	}
}
