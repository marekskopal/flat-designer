<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Enum\Type;

abstract class AEntity
{
	#[Column(type: Type::Int, primary: true, autoIncrement: true)]
	public int $id;

	#[Column(type: Type::Timestamp)]
	public DateTimeImmutable $createdAt;

	#[Column(type: Type::Timestamp)]
	public DateTimeImmutable $updatedAt;
}
