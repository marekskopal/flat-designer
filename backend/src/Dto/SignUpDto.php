<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class SignUpDto implements ArrayFactoryInterface
{
	public function __construct(
		#[SensitiveParameter] public string $email,
		#[SensitiveParameter] public string $password,
		public string $name,
		public LocaleEnum $locale = LocaleEnum::En,
	)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			email: BodyReader::string($data, 'email'),
			password: BodyReader::string($data, 'password'),
			name: BodyReader::string($data, 'name'),
			locale: LocaleEnum::fromBrowser(BodyReader::optionalString($data, 'locale')),
		);
	}
}
