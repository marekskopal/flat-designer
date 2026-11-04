<?php

declare(strict_types=1);

namespace Izbik\Service\Account;

use Izbik\Model\Entity\Enum\UserRoleEnum;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\ModelAssetRepository;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Model\Repository\UserRepository;
use Izbik\Service\Project\ProjectFileProvider;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\ORM;

/**
 * Deletes an account with everything it owns: its projects (their floors, versions, files and models go with them,
 * ON DELETE CASCADE) and its library of definitions and models. The rows go in one transaction, the stored files after
 * it, best effort (a file left behind is logged, the account is gone). The last administrator stays (checked under a
 * lock of the admins' rows).
 */
final readonly class AccountDeletion
{
	public function __construct(
		private UserRepository $userRepository,
		private ProjectRepository $projectRepository,
		private ModelAssetRepository $modelRepository,
		private ProjectFileProvider $projectFileProvider,
		private ORM $orm,
		private DatabaseInterface $database,
	) {
	}

	public function delete(User $user): void
	{
		$projects = $this->projectRepository->findByOwner($user->id);
		$storageKeys = $this->modelRepository->storageKeysOfUser($user->id);
		foreach ($projects as $project) {
			$storageKeys = [
				...$storageKeys,
				...$this->projectFileProvider->storageKeys($project),
				...$this->modelRepository->storageKeysOfProject($project->id),
			];
		}

		$this->orm->getTransactionProvider()->transaction(function () use ($projects, $user): void {
			if ($user->isAdmin() && $this->lockAdmins() <= 1) {
				throw new AccountException('The last administrator cannot be deleted; make another account an admin first.');
			}
			foreach ($projects as $project) {
				$this->projectRepository->delete($project);
			}
			$this->userRepository->delete($user);
		});

		$this->projectFileProvider->deleteStoredObjects($storageKeys);
	}

	/**
	 * The number of admins, their rows locked until the transaction ends: two admins deleting themselves at once would
	 * otherwise each see the other one and leave none.
	 */
	private function lockAdmins(): int
	{
		$admins = $this->database->execute(
			'SELECT id FROM users WHERE role = ? FOR UPDATE',
			[UserRoleEnum::Admin->value],
			cached: false,
		)->fetchAll();

		return count($admins);
	}
}
