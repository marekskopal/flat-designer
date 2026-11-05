<?php

declare(strict_types=1);

namespace Izbik\Tests\Model\Entity\Enum;

use Izbik\Model\Entity\Enum\LocaleEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocaleEnum::class)]
final class LocaleEnumTest extends TestCase
{
	#[TestWith(['cs', LocaleEnum::Cs])]
	#[TestWith(['cs-CZ', LocaleEnum::Cs])]
	#[TestWith(['CS_cz', LocaleEnum::Cs])]
	#[TestWith(['en-GB', LocaleEnum::En])]
	#[TestWith(['csb', LocaleEnum::En])]
	#[TestWith(['de-DE', LocaleEnum::En])]
	#[TestWith(['', LocaleEnum::En])]
	#[TestWith([null, LocaleEnum::En])]
	public function testTheBrowsersLanguageGoesByItsPrimarySubtag(?string $language, LocaleEnum $expected): void
	{
		self::assertSame($expected, LocaleEnum::fromBrowser($language));
	}
}
