<?php

declare(strict_types=1);

namespace Izbik\Service\Account;

use Izbik\Model\Entity\User;
use Izbik\Model\Repository\ModelAssetRepository;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Model\Repository\UserRepository;
use Izbik\Service\Project\ProjectFileProvider;
use MarekSkopal\ORM\ORM;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes an account with everything it owns: its projects (their floors, versions, files and models go with them,
 * ON DELETE CASCADE) and its library of definitions and models. The rows go in one transaction, the stored files after
 * it, best effort (a file left behind is logged, the account is gone). The last administrator stays.
 */
final readonly class AccountDeletion
{
	public function __construct(
		private UserRepository $userRepository,
		private ProjectRepository $projectRepository,
		private ModelAssetRepository $modelRepository,
		private ProjectFileProvider $projectFileProvider,
		private ORM $orm,
		private LoggerInterface $logger,
	) {
	}

	public function delete(User $user): void
	{
		if ($user->isAdmin() && $this->userRepository->countAdmins() <= 1) {
			throw new AccountException('The last administrator cannot be deleted; make another account an admin first.');
		}

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
			foreach ($projects as $project) {
				$this->projectRepository->delete($project);
			}
			$this->userRepository->delete($user);
		});

		try {
			$this->projectFileProvider->deleteStoredObjects($storageKeys);
		} catch (Throwable $e) {
			$this->logger->warning(sprintf('Stored files of the deleted account %d were left behind: %s', $user->id, $e->getMessage()));
		}
	}
}
