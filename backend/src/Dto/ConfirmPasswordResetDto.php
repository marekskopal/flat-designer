<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class ConfirmPasswordResetDto implements ArrayFactoryInterface
{
	public function __construct(#[SensitiveParameter] public string $token, #[SensitiveParameter] public string $password)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(token: BodyReader::string($data, 'token'), password: BodyReader::string($data, 'password'));
	}
}
