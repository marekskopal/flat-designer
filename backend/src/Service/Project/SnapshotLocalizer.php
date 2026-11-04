<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

use Izbik\Model\Entity\Project;

/**
 * Puts a snapshot taken on another instance (an example project, an imported file) onto this one before it is restored
 * (`ProjectSnapshotService::import()`). Its ids link only its own entries, so the materials are matched by code
 * (`SnapshotMaterials`) and the models by their source (`SnapshotModels`), and what refers to the other instance's
 * files is left out (`SnapshotFiles`). The furniture definitions are matched as they are restored
 * (`SnapshotDefinitionRestorer`).
 */
final readonly class SnapshotLocalizer
{
	public function __construct(private SnapshotMaterials $materials, private SnapshotModels $models)
	{
	}

	/**
	 * @param array<mixed> $data the decoded snapshot
	 * @return array<mixed>
	 */
	public function localize(array $data, Project $project): array
	{
		return $this->models->localize($this->materials->localize(SnapshotFiles::withoutFiles($data)), $project);
	}
}
