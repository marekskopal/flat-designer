<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/** Why no room was found around a point (the codes are shared with the frontend twin). */
enum RoomDetectErrorEnum: string
{
	case OnWall = 'onWall';
	case Open = 'open';
	case Invalid = 'invalid';

	public function message(): string
	{
		return match ($this) {
			self::OnWall => 'The point is inside a wall; pick a point inside the room.',
			self::Open => 'No walls close around that point.',
			self::Invalid => 'The walls around that point do not form a usable room outline.',
		};
	}
}
