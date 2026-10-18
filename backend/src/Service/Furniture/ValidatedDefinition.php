<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/** A definition that passed `DefinitionValidator`: its JSON as stored and its parts at the default size and parameter values. */
final readonly class ValidatedDefinition
{
	/** @param list<ResolvedPart> $parts */
	public function __construct(public string $json, public array $parts)
	{
	}
}
