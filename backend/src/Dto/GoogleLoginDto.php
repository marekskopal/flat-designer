<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Service\Request\BodyReader;
use SensitiveParameter;

final readonly class GoogleLoginDto implements ArrayFactoryInterface
{
	/** `locale`: the browser's language, for an account the sign-in creates. */
	public function __construct(#[SensitiveParameter] public string $idToken, public LocaleEnum $locale = LocaleEnum::En)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			idToken: BodyReader::string($data, 'idToken'),
			locale: LocaleEnum::fromBrowser(BodyReader::optionalString($data, 'locale')),
		);
	}
}
