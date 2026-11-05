<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/** The language a user sees the application and gets their mail in. */
enum LocaleEnum: string
{
	case En = 'en';
	case Cs = 'cs';

	/** A language the browser asked for at sign-up (`cs`, `cs-CZ`, by its primary subtag); anything else, or none, is English. */
	public static function fromBrowser(?string $language): self
	{
		if ($language === null) {
			return self::En;
		}

		return self::tryFrom(strtolower(explode('-', str_replace('_', '-', trim($language)))[0])) ?? self::En;
	}

	/** The first language of an `Accept-Language` header (`cs-CZ,cs;q=0.9,en;q=0.8` → Czech); none or another, English. */
	public static function fromAcceptLanguage(string $header): self
	{
		return self::fromBrowser(explode(';', explode(',', $header)[0])[0]);
	}
}
