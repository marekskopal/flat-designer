<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;
use FlatDesigner\Model\Repository\ProjectMaterialPriceRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A project's price of a material (in the project's `currency`): per m² and per running metre (skirting, edge banding).
 * Without a material it prices the default look of `surface` (a ceiling without a material is white plaster). Prices
 * are not floor content: no revision, no undo, not in versions.
 */
#[Entity(repositoryClass: ProjectMaterialPriceRepository::class)]
class ProjectMaterialPrice extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: Project::class)]
		public readonly Project $project,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $material,
		#[ColumnEnum(enum: QuantitySurfaceEnum::class, nullable: true)]
		public ?QuantitySurfaceEnum $surface,
		#[Column(type: Type::Double, nullable: true)]
		public ?float $pricePerArea,
		#[Column(type: Type::Double, nullable: true)]
		public ?float $pricePerLength,
	) {
	}
}
