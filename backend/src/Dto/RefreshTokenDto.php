<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class RefreshTokenDto implements ArrayFactoryInterface
{
	public function __construct(#[SensitiveParameter] public string $refreshToken)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(refreshToken: BodyReader::string($data, 'refreshToken'));
	}
}
