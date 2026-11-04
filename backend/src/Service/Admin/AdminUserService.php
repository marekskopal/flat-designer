<?php

declare(strict_types=1);

namespace Izbik\Service\Admin;

use DateTimeImmutable;
use Izbik\Dto\AdminUserCreateDto;
use Izbik\Dto\AdminUserUpdateDto;
use Izbik\Model\Entity\Enum\UserRoleEnum;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Model\Repository\UserRepository;
use Izbik\Service\Account\AccountDeletion;
use Izbik\Service\Admin\Exception\AdminUserException;
use Izbik\Service\Authentication\LoginAttemptService;
use Izbik\Service\Provider\UserProvider;
use Izbik\Validator\PasswordValidator;
use Izbik\Validator\UserFieldValidator;
use SensitiveParameter;

/** User management for admins. Guards against locking everyone out (last admin, self-delete). */
final readonly class AdminUserService
{
	public function __construct(
		private UserRepository $userRepository,
		private ProjectRepository $projectRepository,
		private UserProvider $userProvider,
		private LoginAttemptService $loginAttempts,
		private AccountDeletion $accountDeletion,
	) {
	}

	/**
	 * An account an admin adds (with sign-up closed, the only way in besides Google for an existing one): its email
	 * counts as verified, as the admin vouches for it, and the admin passes the password on.
	 */
	public function createUser(AdminUserCreateDto $input): User
	{
		$email = UserFieldValidator::normalizeEmail($input->email) ?? throw new AdminUserException('Invalid email address.', 422);
		$name = UserFieldValidator::normalizeName($input->name) ?? throw new AdminUserException('Name is required.', 422);
		if (!PasswordValidator::isValid($input->password)) {
			throw new AdminUserException(PasswordValidator::Requirements, 422);
		}
		if ($this->userRepository->findByEmail($email) !== null) {
			throw new AdminUserException('A user with this email already exists.');
		}

		return $this->userProvider->createUser($email, $input->password, $name, $input->role, emailVerified: true);
	}

	/** @return list<User> */
	public function listUsers(): array
	{
		return $this->userRepository->findAllOrdered();
	}

	public function updateUser(User $target, AdminUserUpdateDto $update): User
	{
		// The checks that need no change come first, so a refused update writes nothing (the password is saved at once).
		$role = $update->role !== $target->role ? $update->role : null;
		if ($role !== null && $target->isAdmin() && $this->userRepository->countAdmins() <= 1) {
			throw new AdminUserException('Cannot demote the last administrator.');
		}
		if ($update->password !== null && !PasswordValidator::isValid($update->password)) {
			throw new AdminUserException(PasswordValidator::Requirements, 422);
		}

		if ($update->name !== null) {
			$target->name = UserFieldValidator::normalizeName($update->name) ?? throw new AdminUserException('Invalid name.', 422);
		}

		if ($update->email !== null) {
			$this->applyEmail($target, $update->email);
		}

		if ($role !== null) {
			$target->role = $role;
		}

		$target->updatedAt = new DateTimeImmutable();
		$this->userRepository->persist($target);

		if ($update->password !== null) {
			$this->applyPassword($target, $update->password);
		}

		return $target;
	}

	public function deleteUser(User $actor, User $target): void
	{
		if ($target->id === $actor->id) {
			throw new AdminUserException('You cannot delete your own account.');
		}

		if ($target->role === UserRoleEnum::Admin && $this->userRepository->countAdmins() <= 1) {
			throw new AdminUserException('Cannot delete the last administrator.');
		}

		$projects = $this->projectRepository->countByOwner($target->id);
		if ($projects > 0) {
			throw new AdminUserException(sprintf('%s owns %d project(s); delete them first.', $target->name, $projects));
		}

		// The library's definitions and models go with it.
		$this->accountDeletion->delete($target);
	}

	/**
	 * A new password set by an admin, e.g. for someone locked out on a server without mail: it signs the user out
	 * everywhere and lifts a sign-in lock.
	 */
	private function applyPassword(User $target, #[SensitiveParameter] string $password): void
	{
		$this->userProvider->updatePassword($target, $password);
		$this->loginAttempts->recordSuccess($target);
	}

	private function applyEmail(User $target, string $email): void
	{
		$normalized = UserFieldValidator::normalizeEmail($email) ?? throw new AdminUserException('Invalid email address.', 422);
		if ($normalized === $target->email) {
			return;
		}

		// Sign-in resolves users by email, so it must stay unique.
		if ($this->userRepository->findByEmail($normalized) !== null) {
			throw new AdminUserException('A user with this email already exists.');
		}

		$target->email = $normalized;
		// The new address is not proven to be the user's (its owner confirms it from the app's banner).
		$target->emailVerified = false;
	}
}
