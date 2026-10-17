<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Service\Request\BodyReader;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;

/** Partial update; a null field is left unchanged. */
final readonly class AdminUserUpdateDto implements ArrayFactoryInterface
{
	public function __construct(public ?string $name, public ?string $email, public ?UserRoleEnum $role,)
	{
	}

	public static function fromArray(array $data): static
	{
		$role = BodyReader::optionalString($data, 'role');

		return new self(
			name: BodyReader::optionalString($data, 'name'),
			email: BodyReader::optionalString($data, 'email'),
			role: $role === null ? null : UserRoleEnum::tryFrom($role) ?? throw new InvalidRequestException('Unknown role.', 422),
		);
	}
}
