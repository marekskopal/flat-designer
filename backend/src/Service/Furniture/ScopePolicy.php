<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Service\Project\ProjectProvider;

/**
 * Who may read, change and use a scoped asset: global ones are for everyone (admins change them), a library is its
 * owner's (and admins'), a project's own go with access to the project. The same rules for furniture definitions and
 * 3D models.
 */
final readonly class ScopePolicy
{
	public function __construct(private ProjectProvider $projectProvider)
	{
	}

	/**
	 * Whose libraries a listing shows besides the global assets: the user's own, or with a project its owner's when the
	 * user may read that library.
	 *
	 * @return list<int>
	 */
	public static function libraryOwnerIds(User $user, ?Project $project): array
	{
		if ($project === null) {
			return [$user->id];
		}
		$owner = $project->owner;

		return $owner !== null && self::canReadLibraryOf($user, $owner) ? [$owner->id] : [];
	}

	/** Its owner and admins — which, as project access is owner-or-admin too, covers whoever works on the owner's projects. */
	public static function canReadLibraryOf(User $user, User $owner): bool
	{
		return $user->isAdmin() || $owner->id === $user->id;
	}

	public function canRead(User $user, ScopedAsset $asset): bool
	{
		return match ($asset->scope()) {
			FurnitureScopeEnum::Global => true,
			FurnitureScopeEnum::User => $asset->user !== null && self::canReadLibraryOf($user, $asset->user),
			FurnitureScopeEnum::Project => $asset->project !== null && $this->projectProvider->findAccessible(
				$user,
				$asset->project->id,
			) !== null,
		};
	}

	public function canWrite(User $user, ScopedAsset $asset): bool
	{
		return $asset->scope() === FurnitureScopeEnum::Global ? $user->isAdmin() : $this->canRead($user, $asset);
	}

	/** Whether items of the project may use it: global ones, the project owner's library and the project's own. */
	public static function isUsableIn(ScopedAsset $asset, Project $project): bool
	{
		return match ($asset->scope()) {
			FurnitureScopeEnum::Global => true,
			FurnitureScopeEnum::User => $project->owner !== null && $asset->user?->id === $project->owner->id,
			FurnitureScopeEnum::Project => $asset->project?->id === $project->id,
		};
	}
}
