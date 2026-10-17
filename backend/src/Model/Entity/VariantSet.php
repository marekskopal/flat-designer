<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Repository\VariantSetRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * Alternatives of one piece of furniture (e.g. the kitchen with or without its island): item groups that
 * point to the set (`item_groups.variant_set_id`), of which only the active one is shown. A plain id rather
 * than a relation for the active group, as the two tables refer to each other.
 */
#[Entity(repositoryClass: VariantSetRepository::class)]
class VariantSet extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	/** The variant shown; null shows none of them. */
	#[Column(type: Type::Int, nullable: true)]
	public ?int $activeGroupId = null;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
	) {
	}
}
