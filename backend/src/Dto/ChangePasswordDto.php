<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class ChangePasswordDto implements ArrayFactoryInterface
{
	public function __construct(#[SensitiveParameter] public string $currentPassword, #[SensitiveParameter] public string $newPassword,)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			currentPassword: BodyReader::string($data, 'currentPassword'),
			newPassword: BodyReader::string($data, 'newPassword'),
		);
	}
}
