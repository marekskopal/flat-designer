<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/** What the electrical schedule counts a definition's items as (its optional `category`). */
enum DefinitionCategoryEnum: string
{
	/** A light fitting: pendant, downlight, the light source of a lamp. */
	case Light = 'light';
	/** A power socket; an int parameter `gangs` counts its outlets. */
	case Socket = 'socket';
	case Switch = 'switch';
	/** A network or TV socket. */
	case Data = 'data';
	/** A water or waste connection. */
	case Plumbing = 'plumbing';

	/** @param array<mixed> $definition */
	public static function of(array $definition): ?self
	{
		$category = $definition['category'] ?? null;

		return is_string($category) ? self::tryFrom($category) : null;
	}

	/** @return list<string> */
	public static function values(): array
	{
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
