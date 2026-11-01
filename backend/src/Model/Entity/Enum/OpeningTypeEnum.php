<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/**
 * `Door`: one swinging leaf. `DoubleDoor`: two leaves hinged on the jambs. `SlidingDoor`: a leaf hung on a wall
 * face that slides along it. `PocketDoor`: a leaf sliding into a pocket inside the wall. `Passage`: a doorway
 * without a leaf. `Niche`: a recess `depth` deep into the `side` face, not through the wall. `GarageDoor`: a door of
 * a `garageDoorStyle` (FD-89) on the `side` face, the garage's.
 */
enum OpeningTypeEnum: string
{
	case Door = 'Door';
	case DoubleDoor = 'DoubleDoor';
	case SlidingDoor = 'SlidingDoor';
	case PocketDoor = 'PocketDoor';
	case Passage = 'Passage';
	case Window = 'Window';
	case Niche = 'Niche';
	case GarageDoor = 'GarageDoor';

	/** Every doorway, a passage included. */
	public function isDoor(): bool
	{
		return $this !== self::Window && $this !== self::Niche;
	}

	/** Doors with a leaf (or two). */
	public function hasLeaves(): bool
	{
		return $this->isDoor() && $this !== self::Passage;
	}

	public function isSliding(): bool
	{
		return $this === self::SlidingDoor || $this === self::PocketDoor;
	}

	/** The hinge an opening gets when none is given: sliding doors slide and garage doors hang from the start. */
	public function defaultHinge(): ?OpeningHingeEnum
	{
		return $this->isSliding() || $this === self::GarageDoor ? OpeningHingeEnum::Start : null;
	}

	public function label(): string
	{
		return match ($this) {
			self::Door => 'door',
			self::DoubleDoor => 'double door',
			self::SlidingDoor => 'sliding door',
			self::PocketDoor => 'pocket door',
			self::Passage => 'passage',
			self::Window => 'window',
			self::Niche => 'niche',
			self::GarageDoor => 'garage door',
		};
	}
}
