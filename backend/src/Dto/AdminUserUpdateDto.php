<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\UserRoleEnum;
use Izbik\Service\Request\BodyReader;
use Izbik\Service\Request\Exception\InvalidRequestException;
use SensitiveParameter;

/** Partial update; a null field is left unchanged. */
final readonly class AdminUserUpdateDto implements ArrayFactoryInterface
{
	public function __construct(
		public ?string $name,
		public ?string $email,
		public ?UserRoleEnum $role,
		/** A new password, which signs the user out everywhere. */
		#[SensitiveParameter] public ?string $password = null,
	)
	{
	}

	public static function fromArray(array $data): static
	{
		$role = BodyReader::optionalString($data, 'role');

		return new self(
			name: BodyReader::optionalString($data, 'name'),
			email: BodyReader::optionalString($data, 'email'),
			role: $role === null ? null : UserRoleEnum::tryFrom($role) ?? throw new InvalidRequestException('Unknown role.', 422),
			password: BodyReader::optionalString($data, 'password'),
		);
	}
}
