<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture;

use Izbik\Model\Entity\Enum\FurnitureScopeEnum;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\User;

/** Something global, in a user's library or a project's own (a furniture definition, a 3D model): `ScopePolicy` rules it. */
interface ScopedAsset
{
	/** The library's owner (scope `User`). */
	public ?User $user { get; }

	/** The project it belongs to (scope `Project`). */
	public ?Project $project { get; }

	public function scope(): FurnitureScopeEnum;
}
