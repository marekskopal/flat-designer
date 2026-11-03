<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class VerifyEmailDto implements ArrayFactoryInterface
{
	public function __construct(#[SensitiveParameter] public string $token)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(token: BodyReader::string($data, 'token'));
	}
}
