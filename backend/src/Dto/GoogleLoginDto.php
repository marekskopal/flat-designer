<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class GoogleLoginDto implements ArrayFactoryInterface
{
	public function __construct(#[SensitiveParameter] public string $idToken)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(idToken: BodyReader::string($data, 'idToken'));
	}
}
