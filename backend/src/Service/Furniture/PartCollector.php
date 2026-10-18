<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/** The resolved parts of a definition, with the limits on part entries and parts counted across all nesting levels. */
final class PartCollector
{
	private int $entries = 0;

	/** @var list<ResolvedPart> */
	private array $parts = [];

	/** One more part entry read from the list at `$path`. */
	public function entry(string $path): void
	{
		$this->entries++;
		if ($this->entries > DefinitionEvaluator::MaxPartEntries) {
			throw new DefinitionException(
				DefinitionErrorEnum::Limit,
				$path,
				sprintf('At most %d part entries.', DefinitionEvaluator::MaxPartEntries),
			);
		}
	}

	public function add(ResolvedPart $part): void
	{
		if (count($this->parts) >= DefinitionEvaluator::MaxParts) {
			throw new DefinitionException(
				DefinitionErrorEnum::Limit,
				$part->path,
				sprintf('More than %d parts.', DefinitionEvaluator::MaxParts),
			);
		}
		$this->parts[] = $part;
	}

	/** @return list<ResolvedPart> */
	public function parts(): array
	{
		return $this->parts;
	}
}
