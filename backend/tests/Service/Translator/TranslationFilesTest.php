<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Translator;

use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Service\Translator\Translator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/** `translations/<locale>.json`: every language has the English keys, and no placeholder English lacks. */
#[CoversNothing]
final class TranslationFilesTest extends TestCase
{
	public function testEveryLanguageHasTheSameKeysAsEnglish(): void
	{
		$english = self::texts(LocaleEnum::En);
		foreach (LocaleEnum::cases() as $locale) {
			self::assertSame(array_keys($english), array_keys(self::texts($locale)), $locale->value);
		}
	}

	public function testATranslationUsesOnlyPlaceholdersOfTheEnglishText(): void
	{
		$english = self::texts(LocaleEnum::En);
		foreach (LocaleEnum::cases() as $locale) {
			foreach (self::texts($locale) as $key => $text) {
				self::assertSame(
					[],
					array_diff(self::placeholders($text), self::placeholders($english[$key] ?? '')),
					$locale->value . ' ' . $key,
				);
				self::assertNotSame('', trim($text), $locale->value . ' ' . $key);
			}
		}
	}

	public function testTheTranslatorReadsTheShippedFiles(): void
	{
		self::assertSame('Variant 2', new Translator()->translate('names.variant', LocaleEnum::En, ['number' => 2]));
		self::assertSame('Varianta 2', new Translator()->translate('names.variant', LocaleEnum::Cs, ['number' => 2]));
	}

	/** @return array<string, string> Dotted keys, sorted. */
	private static function texts(LocaleEnum $locale): array
	{
		$decoded = json_decode((string) file_get_contents(__DIR__ . '/../../../translations/' . $locale->value . '.json'), true);
		self::assertIsArray($decoded);
		$texts = [];
		$walk = static function (array $node, string $prefix) use (&$walk, &$texts): void {
			foreach ($node as $key => $value) {
				$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
				if (is_array($value)) {
					$walk($value, $path);
				} else {
					self::assertIsString($value, $path);
					$texts[$path] = $value;
				}
			}
		};
		$walk($decoded, '');
		ksort($texts);

		return $texts;
	}

	/** @return list<string> */
	private static function placeholders(string $text): array
	{
		preg_match_all('/\{(\w+)\}/', $text, $matches);

		return $matches[1];
	}
}
