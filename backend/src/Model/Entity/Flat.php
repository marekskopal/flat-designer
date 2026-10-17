<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\FlatRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Enum\Type;

#[Entity(repositoryClass: FlatRepository::class)]
class Flat extends AEntity
{
	public function __construct(
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::String, nullable: true)]
		public ?string $address,
		#[Column(type: Type::Text, nullable: true)]
		public ?string $description,
	) {
	}
}
