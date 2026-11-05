<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Translator;

use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Service\Translator\Translator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
	private string $directory;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/izbik-translator-' . uniqid();
		mkdir($this->directory);
		file_put_contents($this->directory . '/en.json', (string) json_encode([
			'mail' => ['subject' => 'Hello {name}, {count} new'],
			'onlyEnglish' => 'English only',
		]));
		file_put_contents($this->directory . '/cs.json', (string) json_encode(['mail' => ['subject' => 'Ahoj {name}, {count} nové']]));
	}

	protected function tearDown(): void
	{
		$files = glob($this->directory . '/*');
		array_map('unlink', $files === false ? [] : $files);
		rmdir($this->directory);
	}

	public function testATextInTheLanguageWithItsPlaceholdersFilled(): void
	{
		$translator = new Translator($this->directory);

		self::assertSame('Hello Jane, 3 new', $translator->translate('mail.subject', LocaleEnum::En, ['name' => 'Jane', 'count' => 3]));
		self::assertSame('Ahoj Jana, 3 nové', $translator->translate('mail.subject', LocaleEnum::Cs, ['name' => 'Jana', 'count' => 3]));
	}

	public function testAMissingTextFallsBackToEnglishThenToItsKey(): void
	{
		$translator = new Translator($this->directory);

		self::assertSame('English only', $translator->translate('onlyEnglish', LocaleEnum::Cs));
		self::assertSame('mail.missing', $translator->translate('mail.missing', LocaleEnum::Cs));
		self::assertSame('mail', $translator->translate('mail', LocaleEnum::En));
	}
}
