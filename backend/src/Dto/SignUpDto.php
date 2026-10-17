<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class SignUpDto implements ArrayFactoryInterface
{
	public function __construct(
		#[SensitiveParameter] public string $email,
		#[SensitiveParameter] public string $password,
		public string $name,
	)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			email: BodyReader::string($data, 'email'),
			password: BodyReader::string($data, 'password'),
			name: BodyReader::string($data, 'name'),
		);
	}
}
