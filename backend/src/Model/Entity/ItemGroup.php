<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * Items that form one piece of furniture (a hall stand, a kitchen run) and move, duplicate and delete
 * together. Members point to their group (`items.group_id`); a group may be empty. One level only: groups
 * do not contain groups, but a group can be a variant of a `VariantSet`.
 */
#[Entity(repositoryClass: ItemGroupRepository::class)]
class ItemGroup extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	/** Set when the group is one of the alternatives of a variant set; only the set's active group is shown. */
	#[ManyToOne(entityClass: VariantSet::class, nullable: true)]
	public ?VariantSet $variantSet = null;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
	) {
	}
}
