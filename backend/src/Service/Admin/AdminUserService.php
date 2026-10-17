<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Admin;

use DateTimeImmutable;
use FlatDesigner\Dto\AdminUserUpdateDto;
use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\UserRepository;
use FlatDesigner\Service\Admin\Exception\AdminUserException;
use FlatDesigner\Validator\UserFieldValidator;

/** User management for admins. Guards against locking everyone out (last admin, self-delete). */
final readonly class AdminUserService
{
	public function __construct(private UserRepository $userRepository)
	{
	}

	/** @return list<User> */
	public function listUsers(): array
	{
		return $this->userRepository->findAllOrdered();
	}

	public function updateUser(User $target, AdminUserUpdateDto $update): User
	{
		if ($update->name !== null) {
			$target->name = UserFieldValidator::normalizeName($update->name) ?? throw new AdminUserException('Invalid name.', 422);
		}

		if ($update->email !== null) {
			$this->applyEmail($target, $update->email);
		}

		if ($update->role !== null && $update->role !== $target->role) {
			if ($target->isAdmin() && $this->userRepository->countAdmins() <= 1) {
				throw new AdminUserException('Cannot demote the last administrator.');
			}
			$target->role = $update->role;
		}

		$target->updatedAt = new DateTimeImmutable();
		$this->userRepository->persist($target);

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

		$this->userRepository->delete($target);
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
	}
}
