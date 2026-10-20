<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\SavedViewRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A named view of one floor: the view mode, camera, display options and active variants (`settings`, the JSON of
 * `SavedViewSettingsDto`). Applying it in the viewer shows the floor that way, e.g. for a screenshot.
 */
#[Entity(repositoryClass: SavedViewRepository::class)]
class SavedView extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: Project::class)]
		public readonly Project $project,
		#[ManyToOne(entityClass: Floor::class)]
		public Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::Text)]
		public string $settings,
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public ?User $createdBy,
	) {
	}
}
