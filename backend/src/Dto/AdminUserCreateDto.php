<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\UserRoleEnum;
use Izbik\Service\Request\BodyReader;
use Izbik\Service\Request\Exception\InvalidRequestException;
use SensitiveParameter;

/** A new account an admin adds (`POST /api/admin/users`). */
final readonly class AdminUserCreateDto implements ArrayFactoryInterface
{
	public function __construct(
		public string $name,
		public string $email,
		#[SensitiveParameter] public string $password,
		public UserRoleEnum $role = UserRoleEnum::User,
	) {
	}

	public static function fromArray(array $data): static
	{
		$role = BodyReader::optionalString($data, 'role');

		return new self(
			name: BodyReader::string($data, 'name'),
			email: BodyReader::string($data, 'email'),
			password: BodyReader::string($data, 'password'),
			role: $role === null ? UserRoleEnum::User : UserRoleEnum::tryFrom($role) ?? throw new InvalidRequestException(
				'Unknown role.',
				422,
			),
		);
	}
}
