<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

/**
 * The files and models a project file brought along, uploaded anew before its snapshot is imported: the new id by the
 * snapshot's (`SnapshotLocalizer`).
 */
final readonly class ImportedAssets
{
	/**
	 * @param array<int, int> $fileIds
	 * @param array<int, int> $modelIds
	 */
	public function __construct(public array $fileIds = [], public array $modelIds = [])
	{
	}
}
