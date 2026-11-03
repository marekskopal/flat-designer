<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class RequestPasswordResetDto implements ArrayFactoryInterface
{
	public function __construct(#[SensitiveParameter] public string $email)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(email: BodyReader::string($data, 'email'));
	}
}
