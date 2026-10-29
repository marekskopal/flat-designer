<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** What a quantity line covers (`QuantitiesService`); a price without a material is the price of the surface's default look. */
enum QuantitySurfaceEnum: string
{
	case Floor = 'Floor';
	/** A room's wall finish bands (`wallFinishes`, e.g. tiling). */
	case WallFinish = 'WallFinish';
	/** The wall faces of a room around and above its bands, in the walls' own material (paint). */
	case Wall = 'Wall';
	case Ceiling = 'Ceiling';
	case Beams = 'Beams';
	case Skirting = 'Skirting';
	/** Boards of the Custom furniture (the cut list). */
	case Furniture = 'Furniture';
	/** A roof's covering (tiles, slate, metal, membrane) over its planes, along the slope (FD-38). */
	case RoofCovering = 'RoofCovering';
	/** The underside of a roof's planes, along the slope. */
	case Soffit = 'Soffit';
	/** The wall faces outside every room, per material: bands, else the wall's own or the project's façade (FD-88). */
	case Facade = 'Facade';

	/** Name of what the viewer draws when the surface has no material. */
	public function defaultMaterialName(): string
	{
		return match ($this) {
			self::Floor => 'No floor material',
			self::WallFinish, self::Furniture => 'No material',
			self::Wall => 'Wall paint (default)',
			self::Ceiling => 'White plaster',
			self::Beams => 'White paint',
			self::Skirting => 'White skirting',
			self::RoofCovering => 'Roof covering (default)',
			self::Soffit => 'White-painted soffit',
			self::Facade => 'Wall finish (default)',
		};
	}

	/** Skirting is bought by the running metre, the rest by the square metre. */
	public function byLength(): bool
	{
		return $this === self::Skirting;
	}
}
