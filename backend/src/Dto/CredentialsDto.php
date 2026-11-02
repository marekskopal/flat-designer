<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class CredentialsDto implements ArrayFactoryInterface
{
	public function __construct(#[SensitiveParameter] public string $email, #[SensitiveParameter] public string $password,)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(email: BodyReader::string($data, 'email'), password: BodyReader::string($data, 'password'));
	}
}
